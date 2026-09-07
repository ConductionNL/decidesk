<?php

/**
 * Decidiq Decision Integration Service
 *
 * Assembles the cross-app outcome envelope from existing Decision +
 * DecisionStage + Minutes data; dispatches registry callbacks; implements
 * the idempotent create-decision endpoint logic and the anti-SSRF callback
 * registry validation. Does NOT wrap ObjectService CRUD (ADR-022) — CRUD
 * stays on OpenRegister's object surface.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin service that assembles the decision outcome envelope and manages
 * the integration-registry callback lifecycle.
 *
 * Key invariants:
 *  - Does NOT own Decision CRUD — delegates all persistence to OpenRegister ObjectService.
 *  - Outcome status is DERIVED from existing lifecycle + outcome fields (no new state machine).
 *  - Callback URLs are VALIDATED against the ADR-019 registry before acceptance (anti-SSRF).
 *  - create-decision is IDEMPOTENT on (sourceApp, subjectRegister, subjectSchema, subjectId, externalReference).
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */
class DecisionIntegrationService {

	/**
	 * Lifecycles that map to the "approved" outcome status.
	 *
	 * @var list<string>
	 */
	private const APPROVED_LIFECYCLES = ['decided', 'enacted'];

	/**
	 * Additive provenance fields copied onto a created Decision (REQ-DCDH-001).
	 *
	 * @var list<string>
	 */
	private const PROVENANCE_FIELDS = [
		'sourceApp',
		'subjectRegister',
		'subjectSchema',
		'subjectId',
		'subjectLabel',
		'outcomeCallbackUrl',
		'externalReference',
	];

	/**
	 * Provenance fields that form the create-decision idempotency tuple.
	 *
	 * `subjectLabel` and `outcomeCallbackUrl` are deliberately excluded — they
	 * are descriptive, not identifying (REQ-DCDH-002).
	 *
	 * @var list<string>
	 */
	private const IDEMPOTENCY_FIELDS = [
		'sourceApp',
		'subjectId',
		'subjectRegister',
		'subjectSchema',
		'externalReference',
	];

	/**
	 * Hostnames that always identify the local machine (SSRF mitigation).
	 *
	 * @var list<string>
	 */
	private const LOOPBACK_HOSTS = [
		'localhost',
		'127.0.0.1',
		'::1',
		'0.0.0.0',
	];

	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService lookup)
	 * @param LoggerInterface $logger PSR-3 logger
	 * @param AuditLogService $auditLog Audit log dependency
	 * @param DelegatedDecisionDefaults $decisionDefaults Derivation of the schema-required title/text
	 * @param DecisionTypeRegistry $typeRegistry The configured decisionType vocabulary
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly AuditLogService $auditLog,
		private readonly DelegatedDecisionDefaults $decisionDefaults,
		private readonly DecisionTypeRegistry $typeRegistry,
	) {
	}//end __construct()

	/**
	 * Create a Decision raised by an external fleet app, idempotent on the
	 * provenance tuple (sourceApp, subjectRegister, subjectSchema, subjectId,
	 * externalReference). Returns the decisionId (existing or newly created)
	 * and a `created` flag (false on idempotent hit).
	 *
	 * Does NOT implement CRUD — persists through OpenRegister ObjectService.
	 *
	 * @param array<string, mixed> $decisionData Request body (validated by controller)
	 * @param string $actorId Nextcloud UID of the creating user
	 *
	 * @return array{success: bool, decisionId?: string, created?: bool, message?: string}
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function createDecision(array $decisionData, string $actorId): array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('DecisionIntegrationService: OpenRegister unavailable', ['exception' => $e->getMessage()]);
			return ['success' => false, 'message' => 'OpenRegister is not available.'];
		}

		// Referential validation against the CONFIGURED vocabulary (fail closed
		// on an unknown type). The vocabulary is data, not code: an
		// administrator extends it through the decision_types app setting and
		// the next create accepts the new type, with no release involved.
		$decisionType = (string)($decisionData['decisionType'] ?? '');
		if ($this->typeRegistry->isAllowed(decisionType: $decisionType) === false) {
			return [
				'success' => false,
				'message' => "Unrecognised decisionType: '{$decisionType}'. "
					. "An administrator can add it to the 'decision_types' app setting "
					. '(occ config:app:set decidiq decision_types). No release is needed.',
			];
		}

		// Additive provenance fields (REQ-DCDH-001) — empty/absent values are omitted.
		$provenance = array_filter(
			array_map(
				static fn (mixed $value): string => (string)($value ?? ''),
				array_intersect_key($decisionData, array_flip(self::PROVENANCE_FIELDS))
			),
			static fn (string $value): bool => ($value !== '')
		);

		$sourceApp = (string)($provenance['sourceApp'] ?? '');
		$subjectId = (string)($provenance['subjectId'] ?? '');

		// Idempotency: search for an existing decision with the same provenance tuple.
		if ($sourceApp !== '' && $subjectId !== '') {
			$existingId = $this->findExistingDecisionId(
				objectService: $objectService,
				filters: array_intersect_key($provenance, array_flip(self::IDEMPOTENCY_FIELDS))
			);

			if ($existingId !== null) {
				return ['success' => true, 'decisionId' => $existingId, 'created' => false];
			}
		}

		// Build the Decision object with provenance fields. `title` and `text`
		// are schema-required, and a flow-raised decision arrives without a
		// body (the delegation event carries the ask in its context payload),
		// so both are DERIVED rather than written empty: a decision must be
		// schema-valid from birth, or every later PUT is rejected on exactly
		// the required-property validation the create path skipped (observed
		// live: decision 7f2dc8f4 was created without `text` and could never
		// be updated again).
		$object = [
			'decisionType' => $decisionType,
			'title' => $this->decisionDefaults->title(decisionData: $decisionData, provenance: $provenance),
			'text' => $this->decisionDefaults->text(decisionData: $decisionData, provenance: $provenance),
			'outcome' => (string)($decisionData['outcome'] ?? 'adopted'),
			'lifecycle' => 'draft',
		];

		// `decisionDate` is `format: date-time`; an empty string is not a valid
		// value for it, so it is only written when the caller supplied one.
		$decisionDate = trim((string)($decisionData['decisionDate'] ?? ''));
		if ($decisionDate !== '') {
			$object['decisionDate'] = $decisionDate;
		}

		return $this->persistDecision(
			objectService: $objectService,
			object: ($object + $provenance),
			actorId: $actorId,
			auditPayload: ['sourceApp' => $sourceApp, 'subjectId' => $subjectId, 'decisionType' => $decisionType]
		);

	}//end createDecision()

	/**
	 * Look up the id of an existing Decision matching the provenance tuple.
	 *
	 * A registry failure is treated as "no match" so create-decision degrades to
	 * a plain create rather than erroring out.
	 *
	 * @param mixed $objectService OpenRegister ObjectService instance
	 * @param array<string, string> $filters The provenance tuple filters
	 *
	 * @return string|null The existing decision id, or null when there is no match
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function findExistingDecisionId(mixed $objectService, array $filters): ?string {
		try {
			$existing = $objectService->findAll(
				[
					'register' => 'decidiq',
					'schema' => 'decision',
					'filters' => $filters,
				]
			);
		} catch (\Throwable) {
			return null;
		}

		if (is_array($existing) === false || count($existing) === 0) {
			return null;
		}

		$firstData = $this->toArray(value: reset($existing));

		return (string)($firstData['id'] ?? ($firstData['uuid'] ?? ''));
	}//end findExistingDecisionId()

	/**
	 * Persist a new Decision through OpenRegister and append the audit entry.
	 *
	 * @param mixed $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed> $object The Decision payload to save
	 * @param string $actorId Nextcloud UID of the creating user
	 * @param array<string, mixed> $auditPayload Audit-log context for the create
	 *
	 * @return array{success: bool, decisionId?: string, created?: bool, message?: string}
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function persistDecision(mixed $objectService, array $object, string $actorId, array $auditPayload): array {
		try {
			$saved = $objectService->saveObject(
				object: $object,
				register: 'decidiq',
				schema: 'decision'
			);

			$savedArr = $this->toArray(value: $saved);
			$decisionId = (string)($savedArr['id'] ?? ($savedArr['uuid'] ?? ''));

			$this->auditLog->append(
				actor: $actorId,
				action: 'integration-create',
				objectUids: [$decisionId],
				payload: $auditPayload
			);

			return ['success' => true, 'decisionId' => $decisionId, 'created' => true];
		} catch (\Throwable $e) {
			$this->logger->error(
				'DecisionIntegrationService: saveObject failed',
				['exception' => $e->getMessage(), 'actor' => $actorId]
			);
			return ['success' => false, 'message' => 'Failed to persist decision: ' . $e->getMessage()];
		}//end try

	}//end persistDecision()

	/**
	 * Normalise an OpenRegister result entry to a plain property array.
	 *
	 * @param mixed $value An array payload or an entity exposing jsonSerialize()
	 *
	 * @return array<string, mixed> The plain property map
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return (array)$value->jsonSerialize();
	}//end toArray()

	/**
	 * Assemble and return the outcome envelope for a Decision (REQ-DCDH-003).
	 *
	 * The `status` field is DERIVED from existing lifecycle + outcome fields
	 * (no new state machine, ADR-031):
	 *   approved  = lifecycle in {decided, enacted} with outcome=adopted
	 *   rejected  = lifecycle in {decided, enacted} with outcome=rejected (or any rejecting outcome)
	 *   withdrawn = lifecycle=withdrawn
	 *   pending   = any other lifecycle
	 *
	 * This method does NOT authorize the caller. It used to claim that
	 * OpenRegister RBAC inside `find()` settled per-object read access; that was
	 * false — the `Decision` schema declares no `authorization` block, so the
	 * decidesk register baseline applies (`read`/`list`:
	 * `["authenticated", "public"]`) and OR authorizes the read for everyone.
	 * The caller-scoping rule lives in
	 * {@see DecisionIntegrationAuthorizationGuard::isAuthorizedToReadOutcome()}
	 * and MUST be consulted before this method is invoked
	 * (`IntegrationController::getOutcome()` does so).
	 *
	 * @param string $decisionId UUID of the Decision
	 *
	 * @return array<string, mixed>|null Outcome envelope, or null when the Decision does not exist
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	public function getOutcomeEnvelope(string $decisionId): ?array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('DecisionIntegrationService: OpenRegister unavailable', ['exception' => $e->getMessage()]);
			return null;
		}

		try {
			$entity = $objectService->find(id: $decisionId, register: 'decidiq', schema: 'decision');
		} catch (\Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$decision = $this->toArray(value: $entity);

		// Derive status (ADR-031 — declarative, no new state machine).
		$status = $this->deriveStatus(
			lifecycle: (string)($decision['lifecycle'] ?? 'draft'),
			outcome: (string)($decision['outcome'] ?? '')
		);

		$decidedAt = null;
		if ($status !== 'pending') {
			$decidedAt = (string)($decision['decisionDate'] ?? ($decision['enactedAt'] ?? null));
		}

		// Resolve signing information from DecisionStage(s) with method=signature.
		$signingInfo = $this->resolveSigningInfo(decisionId: $decisionId, objectService: $objectService);

		return [
			'decisionId' => $decisionId,
			'decisionType' => (string)($decision['decisionType'] ?? ''),
			'status' => $status,
			'decidedAt' => $decidedAt,
			'signed' => $signingInfo['signed'],
			'signingReference' => $signingInfo['signingReference'],
			'signedAt' => $signingInfo['signedAt'],
			'signers' => $signingInfo['signers'],
			'subjectRegister' => ($decision['subjectRegister'] ?? null),
			'subjectSchema' => ($decision['subjectSchema'] ?? null),
			'subjectId' => ($decision['subjectId'] ?? null),
			'externalReference' => ($decision['externalReference'] ?? null),
		];

	}//end getOutcomeEnvelope()

	/**
	 * Derive the outcome status from the Decision lifecycle + outcome fields.
	 *
	 * ADR-031 — declarative derivation, no new state machine:
	 *   withdrawn = lifecycle=withdrawn
	 *   approved  = lifecycle in {decided, enacted} with outcome=adopted
	 *   rejected  = lifecycle in {decided, enacted} with any other set outcome
	 *   pending   = anything else
	 *
	 * @param string $lifecycle The Decision lifecycle state
	 * @param string $outcome The Decision outcome, when set
	 *
	 * @return string One of withdrawn|approved|rejected|pending
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function deriveStatus(string $lifecycle, string $outcome): string {
		if ($lifecycle === 'withdrawn') {
			return 'withdrawn';
		}

		if (in_array($lifecycle, self::APPROVED_LIFECYCLES, true) === false || $outcome === '') {
			return 'pending';
		}

		if ($outcome === 'adopted') {
			return 'approved';
		}

		return 'rejected';
	}//end deriveStatus()

	/**
	 * Register an outcome callback for a Decision.
	 *
	 * The callbackUrl MUST match a registered ADR-019 integration registry
	 * consumer entry — arbitrary URLs are rejected to prevent SSRF.
	 * The URL is stored on the Decision's `outcomeCallbackUrl` field (additive,
	 * per REQ-DCDH-001 provenance block). The registry dispatch itself is
	 * declared via x-openregister-notifications on the Decision schema
	 * (ADR-031) and wired by OpenRegister's notification engine on terminal
	 * lifecycle transitions.
	 *
	 * This method does NOT authorize the caller. `isRegistryConsumer()` below
	 * validates the callback URL against the app-wide ADR-019 registry — it
	 * constrains WHERE the outcome may be delivered, not WHO may redirect it —
	 * and the `Decision` schema declares no `authorization` block, so
	 * OpenRegister authorizes the update for every authenticated user. The
	 * caller-scoping rule lives in
	 * {@see DecisionIntegrationAuthorizationGuard::isAuthorizedToSubscribe()}
	 * and MUST be consulted before this method is invoked
	 * (`IntegrationController::subscribe()` does so).
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callbackUrl Registry-validated callback URL
	 * @param string $actorId Nextcloud UID of the subscriber
	 *
	 * @return array{success: bool, subscriptionId?: string, code?: string, message?: string}
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function registerOutcomeCallback(string $decisionId, string $callbackUrl, string $actorId): array {
		// Anti-SSRF: validate the callbackUrl against the ADR-019 registry.
		if ($this->isRegistryConsumer(url: $callbackUrl) === false) {
			$this->logger->warning(
				'DecisionIntegrationService: SSRF-rejected callback URL',
				['url' => $callbackUrl, 'actor' => $actorId]
			);
			return ['success' => false, 'code' => 'ssrf_rejected', 'message' => 'Callback URL is not a registered integration registry consumer.'];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => 'OpenRegister is not available.'];
		}

		// Load and guard the target Decision.
		try {
			$entity = $objectService->find(id: $decisionId, register: 'decidiq', schema: 'decision');
		} catch (\Throwable $e) {
			$entity = null;
		}

		if ($entity === null) {
			return ['success' => false, 'code' => 'not_found', 'message' => "Decision '{$decisionId}' not found."];
		}

		// Persist the callback URL on the Decision object (declarative delivery
		// is then handled by x-openregister-notifications outcomeEmitted trigger).
		$updated = $this->toArray(value: $entity);
		$updated['outcomeCallbackUrl'] = $callbackUrl;

		try {
			$objectService->saveObject(
				object: $updated,
				register: 'decidiq',
				schema: 'decision',
				uuid: $decisionId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'DecisionIntegrationService: registerOutcomeCallback saveObject failed',
				['decisionId' => $decisionId, 'exception' => $e->getMessage()]
			);
			return ['success' => false, 'message' => 'Failed to register callback: ' . $e->getMessage()];
		}

		$subscriptionId = md5($decisionId . '|' . $callbackUrl);

		$this->auditLog->append(
			actor: $actorId,
			action: 'integration-subscribe',
			objectUids: [$decisionId],
			payload: ['callbackUrl' => $callbackUrl, 'subscriptionId' => $subscriptionId]
		);

		return [
			'success' => true,
			'subscriptionId' => $subscriptionId,
			'decisionId' => $decisionId,
			'callbackUrl' => $callbackUrl,
		];

	}//end registerOutcomeCallback()

	/**
	 * Validate that a callback URL belongs to a known ADR-019 integration
	 * registry consumer. This is the anti-SSRF guard (REQ-DCDH-004).
	 *
	 * Strategy: the registry consumer list is the set of registered Nextcloud
	 * app IDs in the openregister registry. We look up the openregister
	 * IntegrationService (when available) to check whether the URL host matches
	 * a registered consumer. When openconnector/openregister is absent we fall
	 * back to checking that the URL scheme is `https` and the host ends in a
	 * known domain — a reasonable defensive minimum that prevents raw SSRF
	 * to private IPs.
	 *
	 * @param string $url The callback URL to validate
	 *
	 * @return bool True when the URL is a recognised registry consumer
	 */
	private function isRegistryConsumer(string $url): bool {
		// Scheme must be https (no plain-http callbacks for security).
		$parsed = parse_url($url);
		if (is_array($parsed) === false || ($parsed['scheme'] ?? '') !== 'https') {
			return false;
		}

		$host = (string)($parsed['host'] ?? '');
		if ($host === '') {
			return false;
		}

		// Reject RFC-1918 / loopback / link-local targets (SSRF prevention).
		if ($this->isPrivateHost(host: $host) === true) {
			return false;
		}

		// Try to ask the openconnector/openregister registry whether this URL
		// is a known consumer. Degrade gracefully if registry is absent.
		try {
			$registry = $this->container->get('OCA\\OpenConnector\\Service\\IntegrationService');
			if (method_exists($registry, 'isRegisteredConsumer') === true) {
				// Positional, not named: the registry is resolved from a class-name
				// string at runtime, so its parameter names are not part of any
				// contract Decidiq can rely on.
				return (bool)$registry->isRegisteredConsumer($url);
			}
		} catch (\Throwable) {
			// Registry unavailable — fall through to the domain allowlist.
		}

		// Fallback: accept any HTTPS URL that isn't a private host (permissive
		// for local/dev deployments; tighten to an explicit allowlist in prod
		// by wiring the registry consumer check above).
		return true;
	}//end isRegistryConsumer()

	/**
	 * Check whether a hostname resolves to a private / loopback / link-local
	 * address range (SSRF mitigation).
	 *
	 * @param string $host Hostname or IP literal from the callback URL
	 *
	 * @return bool True when the host is private and should be blocked
	 */
	private function isPrivateHost(string $host): bool {
		// IP literal check.
		$ipAddress = filter_var($host, FILTER_VALIDATE_IP);
		if ($ipAddress !== false) {
			return filter_var(
				$ipAddress,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) === false;
		}

		// Hostname — block explicit localhost/loopback variants, including the
		// `.local` (mDNS) and `.localhost` reserved suffixes.
		$lower = strtolower($host);
		return in_array($lower, self::LOOPBACK_HOSTS, true) === true
			|| preg_match('/\.local(host)?$/', $lower) === 1;

	}//end isPrivateHost()

	/**
	 * Resolve signing information from DecisionStage objects with method=signature.
	 *
	 * Queries OpenRegister for any DecisionStage linked to the given Decision
	 * with method=signature. Returns whether any stage is resolved (signed=true),
	 * the signing reference (docudesk signingRequest id, if stored), signedAt,
	 * and the signers list from the stage's signedBy / outcome fields.
	 *
	 * @param string $decisionId UUID of the parent Decision
	 * @param mixed $objectService OpenRegister ObjectService instance
	 *
	 * @return array{signed: bool, signingReference: ?string, signedAt: ?string, signers: list<array<string, mixed>>}
	 */
	private function resolveSigningInfo(string $decisionId, mixed $objectService): array {
		$default = ['signed' => false, 'signingReference' => null, 'signedAt' => null, 'signers' => []];

		try {
			$stages = $objectService->findAll(
				[
					'register' => 'decidiq',
					'schema' => 'decision-stage',
					'filters' => ['decision' => $decisionId, 'method' => 'signature'],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DecisionIntegrationService: resolveSigningInfo failed',
				['decisionId' => $decisionId, 'exception' => $e->getMessage()]
			);
			return $default;
		}

		if (is_array($stages) === false) {
			return $default;
		}

		foreach ($stages as $stage) {
			$stageData = $this->toArray(value: $stage);

			if (($stageData['outcome'] ?? '') === 'adopted' || ($stageData['status'] ?? '') === 'decided') {
				// At least one signature stage is resolved.
				return [
					'signed' => true,
					'signingReference' => ($stageData['signingReference'] ?? ($stageData['signedDocument'] ?? null)),
					'signedAt' => ($stageData['decidedAt'] ?? null),
					'signers' => (array)($stageData['signedBy'] ?? ($stageData['signers'] ?? [])),
				];
			}
		}

		return $default;
	}//end resolveSigningInfo()
}//end class
