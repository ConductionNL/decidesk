<?php

/**
 * Decidiq Decision Integration Authorization Guard
 *
 * The two per-object authorization questions the ADR-019 integration-surface
 * endpoints ask: "may this caller READ this Decision's outcome envelope?"
 * (REQ-DCDH-101) and "may this caller ATTACH an outcome callback to it?"
 * (REQ-DCDH-102). Extracted from `DecisionIntegrationService` (which had grown
 * past the class-complexity budget) so the policy lives beside itself rather
 * than beside envelope assembly and callback dispatch — the same split already
 * used for `ConflictOfInterestAuthorizationGuard` and
 * `BoardEvaluationAccessGuard`.
 *
 * The two rules differ on purpose. The READ additionally allows any caller of
 * a published Decision (`isPublished === 'public'`); the WRITE does not.
 * Public readability is not a write grant — see
 * {@see self::isAuthorizedToSubscribe()} for the full derivation.
 *
 * Fails CLOSED: a Decision that cannot be resolved is never authorized. A
 * Decision that genuinely does not EXIST is deliberately allowed through, so
 * the endpoint answers its own 404 and a 403 never becomes an existence oracle
 * for UUIDs the app never issued.
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
 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-object authorization guard for the contract-decision hub's outcome-read
 * and outcome-subscribe endpoints. Fail-closed.
 *
 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
 */
class DecisionIntegrationAuthorizationGuard {

	/**
	 * The caller may read this Decision's outcome envelope.
	 *
	 * @var string
	 */
	public const READ_ALLOWED = 'allowed';

	/**
	 * The caller may NOT read this Decision's outcome envelope.
	 *
	 * @var string
	 */
	public const READ_DENIED = 'denied';

	/**
	 * The question could not be answered — the Decision could not be resolved
	 * at all. Not the same fact as a refusal; see
	 * {@see self::resolveOutcomeReadAccess()}.
	 *
	 * @var string
	 */
	public const READ_UNRESOLVED = 'unresolved';

	/**
	 * Construct the guard.
	 *
	 * The ObjectService is resolved lazily through the container — exactly as
	 * `DecisionIntegrationService` does — so an unavailable OpenRegister is a
	 * DENIAL at the guard rather than a construction failure at the controller.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService lookup)
	 * @param LoggerInterface $logger PSR-3 logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorize a read of one Decision's outcome envelope (REQ-DCDH-101).
	 *
	 * A caller is authorized when:
	 *   (a) they are the Decision's OpenRegister owner (`@self.owner`) — the
	 *       identity that raised it through `POST /api/v1/decisions`, i.e. the
	 *       consumer REQ-DCDH-003 exists to serve. This is also the established
	 *       Decidiq per-object guard on this very `decision` schema; see
	 *       `MotionCoauthorService::checkMotionAccess()`; OR
	 *   (b) the Decision is published (`isPublished === 'public'`) — the app's
	 *       own citizen-visibility flag, set by `DecisionController::publish()`.
	 *       A published decision is a public governance record by definition.
	 *
	 * The admin bypass is the caller's: `IntegrationController` passes a null
	 * `$callerUid` for a Nextcloud administrator and skips this check entirely,
	 * mirroring the `ProxyVoteController` / `ConflictOfInterestController`
	 * convention.
	 *
	 * There is deliberately no `sourceApp`-based rule: the hub holds no
	 * caller-to-consumer identity mapping. `DecisionIntegrationService::createDecision()`'s
	 * `$actorId` is written to the audit log only and never persisted on the
	 * object, and `isRegistryConsumer()` validates a callback URL rather than a
	 * caller — so `@self.owner` is the only ownership fact that actually exists.
	 *
	 * Fail-closed (ADR-005 / gate-8 `unsafe-auth-resolver`): when OpenRegister
	 * is unavailable or the lookup throws, the caller is DENIED rather than
	 * waved through. A Decision that genuinely does not exist is allowed
	 * through so that `getOutcomeEnvelope()` still answers `404` — the guard
	 * must not turn a miss into a `403`.
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callerUid Nextcloud UID of the caller (never null here — a
	 *                          null caller is the admin bypass and never reaches this method)
	 *
	 * @return bool True when the caller may read the envelope
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	public function isAuthorizedToReadOutcome(string $decisionId, string $callerUid): bool {
		return ($this->resolveOutcomeReadAccess(
			decisionId: $decisionId,
			callerUid: $callerUid
		) === self::READ_ALLOWED);
	}//end isAuthorizedToReadOutcome()

	/**
	 * The outcome-read rule of {@see self::isAuthorizedToReadOutcome()}, with
	 * "I could not tell" reported instead of folded into "no".
	 *
	 * SAME RULE, ONE MORE ANSWER. The boolean above is this method with
	 * `unresolved` collapsed onto `denied`, which is right for an HTTP caller:
	 * a request either proceeds or gets a 403, and failing closed is the only
	 * safe collapse. It is NOT right for a caller that has to decide whether to
	 * come back. `DecisionStateRequestedListener` serves a consumer's recovery
	 * heartbeat, and there "OpenRegister was unreachable for a moment" and "you
	 * may not see this Decision" call for opposite actions: wait, versus stop
	 * waiting and say why. Collapsing them would make a transient outage fail a
	 * consumer's run on an authorization error it never had.
	 *
	 * The rule itself is not restated — the boolean now delegates here, so the
	 * HTTP path and the event path cannot come to disagree about who may read.
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @return string One of self::READ_ALLOWED, self::READ_DENIED, self::READ_UNRESOLVED
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function resolveOutcomeReadAccess(string $decisionId, string $callerUid): string {
		if ($decisionId === '' || $callerUid === '') {
			return self::READ_DENIED;
		}

		$decision = $this->loadDecisionForGuard(
			decisionId: $decisionId,
			callerUid: $callerUid,
			guard: 'outcome-read'
		);

		if ($decision === false) {
			// Could not be resolved. An HTTP caller is denied (fail closed);
			// a caller that can come back is told to.
			return self::READ_UNRESOLVED;
		}

		if ($decision === null) {
			// Not found — let getOutcomeEnvelope() answer 404 instead of
			// converting a miss into a 403.
			return self::READ_ALLOWED;
		}

		// (a) The raising consumer.
		if ($this->isDecisionOwner(decision: $decision, callerUid: $callerUid) === true) {
			return self::READ_ALLOWED;
		}

		// (b) A published decision is a public governance record.
		if ((string)($decision['isPublished'] ?? '') === 'public') {
			return self::READ_ALLOWED;
		}

		return self::READ_DENIED;
	}//end resolveOutcomeReadAccess()

	/**
	 * Authorize a WRITE of one Decision's outcome callback (REQ-DCDH-102).
	 *
	 * A caller is authorized when they are the Decision's OpenRegister owner
	 * (`@self.owner`) — the identity that raised it through
	 * `POST /api/v1/decisions`. As with the read guard, a Nextcloud
	 * administrator never reaches this method: `IntegrationController` resolves
	 * a null `$callerUid` for an admin and skips the check.
	 *
	 * WHY THIS IS NOT THE READ RULE. {@see self::isAuthorizedToReadOutcome()}
	 * additionally allows any caller when `isPublished === 'public'`. That arm
	 * deliberately does NOT apply here, for three reasons that come out of the
	 * code rather than out of caution:
	 *
	 *   1. `isPublished` is a READ-visibility enum. It is only ever moved from
	 *      `internal` to `public` by `DecisionController::publish()`, which is
	 *      `#[AuthorizedAdminSetting]` and re-checks `isAdmin()` in its body,
	 *      and it is consumed by `OriController` to gate the anonymous public
	 *      feed (`$filters['isPublished'] = 'public'`). Nothing reads it as a
	 *      write grant. Carrying it onto this path would mean an admin's act of
	 *      widening who may READ silently widens who may WRITE — publishing a
	 *      decision would open its delivery target to every authenticated user.
	 *
	 *   2. The write is destructive by overwrite. `registerOutcomeCallback()`
	 *      assigns a single scalar field, so a second caller silently REPLACES
	 *      the raising consumer's delivery target: the outcome envelope — the
	 *      subject coordinates, the consumer's `externalReference` and the
	 *      `signers` array — is then pushed to a URL of the second caller's
	 *      choosing, and the legitimate consumer never receives its callback.
	 *
	 *   3. The anti-SSRF check is not a caller check. `isRegistryConsumer()`
	 *      validates the URL against the app-wide ADR-019 registry; every
	 *      authenticated user may name any registered consumer's URL. It
	 *      constrains WHERE the data goes, never WHO may redirect it.
	 *
	 * No capability is lost (ADR-044): there is no frontend caller of
	 * `/api/v1/decisions/...` at all, and the raising consumer can already set
	 * `outcomeCallbackUrl` in the create-decision body (it is a
	 * PROVENANCE_FIELD), so this endpoint is the same owner's later update.
	 *
	 * Fail-closed (ADR-005 / gate-8 `unsafe-auth-resolver`): an unresolvable
	 * lookup DENIES. A Decision that genuinely does not exist is allowed
	 * through so `registerOutcomeCallback()` still answers `not_found` → 404,
	 * keeping a 403 from becoming an existence oracle.
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callerUid Nextcloud UID of the caller (never null here — a
	 *                          null caller is the admin bypass and never reaches this method)
	 *
	 * @return bool True when the caller may attach an outcome callback
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function isAuthorizedToSubscribe(string $decisionId, string $callerUid): bool {
		if ($decisionId === '' || $callerUid === '') {
			return false;
		}

		$decision = $this->loadDecisionForGuard(
			decisionId: $decisionId,
			callerUid: $callerUid,
			guard: 'outcome-subscribe'
		);

		if ($decision === false) {
			// Could not be resolved — fail closed.
			return false;
		}

		if ($decision === null) {
			// Not found — let registerOutcomeCallback() answer not_found (404)
			// instead of converting a miss into a 403.
			return true;
		}

		return $this->isDecisionOwner(decision: $decision, callerUid: $callerUid);
	}//end isAuthorizedToSubscribe()

	/**
	 * Load one Decision for an authorization guard, distinguishing "cannot
	 * resolve" from "does not exist" — the two cases a guard must answer
	 * differently.
	 *
	 * Shared by {@see self::isAuthorizedToReadOutcome()} and
	 * {@see self::isAuthorizedToSubscribe()} so both guards resolve their
	 * subject the same way; only the rule applied to the result differs.
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callerUid Nextcloud UID of the caller (logged on failure)
	 * @param string $guard Short guard name for the log line
	 *
	 * @return array<string, mixed>|false|null The decision properties; `false`
	 *                                         when the lookup could not be
	 *                                         resolved (caller must DENY);
	 *                                         `null` when the Decision does not
	 *                                         exist (caller lets the 404 stand)
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	private function loadDecisionForGuard(string $decisionId, string $callerUid, string $guard): array|false|null {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$entity = $objectService->find(id: $decisionId, register: 'decidiq', schema: 'decision');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DecisionIntegrationAuthorizationGuard: could not resolve Decision for the ' . $guard . ' guard; denying',
				['decisionId' => $decisionId, 'caller' => $callerUid, 'exception' => $e->getMessage()]
			);
			return false;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loadDecisionForGuard()

	/**
	 * Whether the caller is the Decision's OpenRegister owner — the identity
	 * that raised it through `POST /api/v1/decisions`, since `persistDecision()`
	 * saves in the calling session and OpenRegister stamps `@self.owner` from it.
	 *
	 * `toArray()` goes through `jsonSerialize()`, so the `@self` metadata block —
	 * which carries the owner UID — is present; the flattened `owner` key is
	 * accepted as a fallback. An empty owner never matches, so an object with no
	 * recorded owner is not owned by anybody.
	 *
	 * @param array<string, mixed> $decision The decision properties
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @return bool True when the caller owns the Decision
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	private function isDecisionOwner(array $decision, string $callerUid): bool {
		$self = $decision['@self'] ?? [];
		$owner = '';
		if (is_array($self) === true && isset($self['owner']) === true) {
			$owner = (string)$self['owner'];
		} elseif (isset($decision['owner']) === true) {
			$owner = (string)$decision['owner'];
		}

		return ($owner !== '' && $owner === $callerUid);
	}//end isDecisionOwner()

	/**
	 * Normalise an ObjectEntity (or an already-decoded payload) to an array,
	 * mirroring `DecisionIntegrationService::toArray()` so the guard reads the
	 * same shape the service does.
	 *
	 * @param mixed $value ObjectEntity or array
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return (array)$value->jsonSerialize();
	}//end toArray()
}//end class
