<?php

/**
 * Decidiq Conflict-of-Interest Service
 *
 * Manages `conflict-of-interest` declarations: detection, declaration, action
 * recording, and lookup. Material declarations append a `conflict-declaration`
 * row to the board audit log.
 *
 * RBAC note: `declare()`/`recordAction()` pass `_rbac: false` to
 * `saveObject()`. The `conflict-of-interest` schema's authorization block
 * declares only `read`/`list` (decidesk_register.json — conflict-of-interest
 * declarations are sensitive personal data, so they are not `public`), and a
 * schema block that names some actions closes every action it does not name
 * (ADR-022) — so OpenRegister's own RBAC would otherwise refuse `create` for
 * every caller (no object exists yet to bypass via ownership) and `update`
 * for every non-owner caller (a chair/secretary recording the action taken
 * on someone else's declaration). This service already re-derives and
 * enforces the finer-grained per-object rule itself before either write —
 * `isAuthorizedForMember()` (declare/forMember: the declaring member, or a
 * chair/secretary of the relevant GovernanceBody) and
 * `isAuthorizedToRecordAction()` (recordAction: chair/secretary only — a
 * presiding-officer act, not something the declarant authorizes for
 * themselves) — so passing `_rbac: false` there is this consumer declaring,
 * per the `ObjectServiceInterface` RBAC contract, that authorization already
 * ran (same convention as `ProxyVoteService`).
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing conflict-of-interest declarations and their effect on
 * read/vote access to specific agenda items.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
 */
class ConflictOfInterestService {

	/**
	 * Allowed declaration-type enum values.
	 *
	 * @var string[]
	 */
	public const DECLARATION_TYPES = [
		'financial-interest',
		'personal-relationship',
		'competing-business',
		'prior-involvement',
		'none',
	];

	/**
	 * Allowed action-taken enum values.
	 *
	 * @var string[]
	 */
	public const ACTIONS = [
		'recused-from-discussion',
		'recused-from-vote',
		'disclosed-and-participated',
		'no-action-needed',
	];

	/**
	 * Allowed severity values.
	 *
	 * @var string[]
	 */
	public const SEVERITIES = ['material', 'non-material'];

	/**
	 * Constructor for ConflictOfInterestService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param AuditLogService $auditLogService Audit log dependency for material declarations
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param ConflictOfInterestAuthorizationGuard $authorizationGuard Per-object authorization guard
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly AuditLogService $auditLogService,
		private readonly ObjectServiceInterface $objectService,
		private readonly ConflictOfInterestAuthorizationGuard $authorizationGuard,
	) {
	}//end __construct()

	/**
	 * Record a new declaration. Material declarations are mirrored to the
	 * audit log and the resulting object is returned alongside success status.
	 *
	 * @param string $membershipId UUID of the declaring member's Membership (was Participant — REQ-SDM-023)
	 * @param string $agendaItemId UUID of the agenda item
	 * @param string $type One of self::DECLARATION_TYPES
	 * @param string $description Free-text rationale
	 * @param string $severity 'material' or 'non-material' (defaults to material)
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the
	 *                               authorization check (admin path, mirroring
	 *                               `ProxyVoteService`'s convention)
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/proposal.md#in-scope
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 *
	 * @return array{success: bool, declaration: array|null, message: string}
	 */
	public function declare(
		string $membershipId,
		string $agendaItemId,
		string $type,
		string $description,
		string $severity = 'material',
		?string $callerUid = null,
	): array {
		$validationFailure = $this->validateDeclarationInput(type: $type, severity: $severity);
		if ($validationFailure !== null) {
			return $validationFailure;
		}

		if ($callerUid !== null
			&& $this->isAuthorizedForMember(membershipId: $membershipId, agendaItemId: $agendaItemId, callerUid: $callerUid) === false
		) {
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Forbidden: only the declaring member, a chair or secretary of the '
					. 'relevant governance body, or an admin may record this declaration.',
			];
		}

		return $this->persistDeclaration(
			membershipId: $membershipId,
			agendaItemId: $agendaItemId,
			type: $type,
			description: $description,
			severity: $severity
		);
	}//end declare()

	/**
	 * Validate `declare()`'s enum-typed inputs before any authorization check
	 * or persistence is attempted.
	 *
	 * @param string $type One of self::DECLARATION_TYPES
	 * @param string $severity One of self::SEVERITIES
	 *
	 * @return array{success: bool, declaration: array|null, message: string}|null Failure envelope, or null when valid
	 */
	private function validateDeclarationInput(string $type, string $severity): ?array {
		if (in_array($type, self::DECLARATION_TYPES, true) === false) {
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Unknown declaration type: ' . $type,
			];
		}

		if (in_array($severity, self::SEVERITIES, true) === false) {
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Unknown severity: ' . $severity,
			];
		}

		return null;
	}//end validateDeclarationInput()

	/**
	 * Persist a validated, authorized declaration: writes the row, mirrors
	 * material declarations to the audit log, and logs the outcome.
	 *
	 * @param string $membershipId UUID of the declaring member's Membership
	 * @param string $agendaItemId UUID of the agenda item
	 * @param string $type One of self::DECLARATION_TYPES
	 * @param string $description Free-text rationale
	 * @param string $severity 'material' or 'non-material'
	 *
	 * @return array{success: bool, declaration: array|null, message: string}
	 */
	private function persistDeclaration(string $membershipId, string $agendaItemId, string $type, string $description, string $severity): array {
		try {
			// The ConflictOfInterest schema declares 'boardMember'/'agendaItem'
			// (decidesk_register.json); this previously wrote 'boardMemberKoppeling'/
			// 'agendaItemKoppeling' instead, an undeclared pair no filter or reader
			// ever matched — every declaration silently stored its member/agenda-item
			// link nowhere the schema (or the model-debt-cleanup-code repair step)
			// could find it. Fixed in the same edit that renames the parameter,
			// since both touch this exact line.
			$row = [
				'boardMember' => $membershipId,
				'agendaItem' => $agendaItemId,
				'declarationType' => $type,
				'description' => $description,
				'severity' => $severity,
				'actionTaken' => 'no-action-needed',
				'declarationTimestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			];

			// _rbac: false — isAuthorizedForMember() above already enforced the
			// per-object authorization rule (declaring member, chair/secretary, or
			// admin); the schema's own authorization block declares only read/list
			// (decidesk_register.json), so this consumer declares, per the
			// ObjectServiceInterface RBAC contract, that authorization already ran
			// (same convention as ProxyVoteService::register()).
			$saved = $this->objectService->saveObject(
				object: $row,
				register: 'decidiq',
				schema: 'conflict-of-interest',
				_rbac: false
			);

			// OpenRegister's saveObject() returns ObjectEntityInterface (a real return type,
			// enforced by PHP) and that interface extends JsonSerializable, so
			// both probes were always true and `$row` was never the value used.
			$serialized = $saved->jsonSerialize();

			if ($severity === 'material') {
				$this->auditLogService->append(
					actor: $membershipId,
					action: 'conflict-declaration',
					objectUids: [(string)($serialized['id'] ?? $serialized['uuid'] ?? ''), $agendaItemId],
					payload: ['type' => $type, 'severity' => $severity]
				);
			}

			$this->logger->info(
				'Decidiq: conflict-of-interest declared',
				[
					'membershipId' => $membershipId,
					'agendaItemId' => $agendaItemId,
					'type' => $type,
					'severity' => $severity,
				]
			);

			return [
				'success' => true,
				'declaration' => $serialized,
				'message' => 'Declaration recorded.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to record conflict-of-interest declaration',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Failed to record declaration.',
			];
		}//end try

	}//end persistDeclaration()

	/**
	 * Update the action-taken on an existing declaration.
	 *
	 * @param string $declarationId UUID of the conflict-of-interest record
	 * @param string $actionTaken One of self::ACTIONS
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the
	 *                               authorization check (admin path, mirroring
	 *                               `ProxyVoteService`'s convention). NOT equivalent
	 *                               to trusting the caller for the action itself —
	 *                               recording the action taken is a presiding-officer
	 *                               act, so a non-null caller must be chair/secretary.
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken
	 *
	 * @return array{success: bool, declaration: array|null, message: string}
	 */
	public function recordAction(string $declarationId, string $actionTaken, ?string $callerUid = null): array {
		if (in_array($actionTaken, self::ACTIONS, true) === false) {
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Unknown action-taken: ' . $actionTaken,
			];
		}

		try {
			$entity = $this->objectService->find(
				id: $declarationId,
				register: 'decidiq',
				schema: 'conflict-of-interest'
			);

			if ($entity === null) {
				return [
					'success' => false,
					'declaration' => null,
					'message' => 'Declaration not found.',
				];
			}

			// `getObject(): array` is on ObjectEntityInterface — the
			// method_exists() probe could never be false.
			$current = $entity->getObject();

			if ($callerUid !== null
				&& $this->authorizationGuard->isAuthorizedToRecordAction(declaration: $current, callerUid: $callerUid) === false
			) {
				return [
					'success' => false,
					'declaration' => null,
					'message' => 'Forbidden: only a chair or secretary of the relevant governance '
						. 'body, or an admin, may record the action taken on a conflict-of-interest declaration.',
				];
			}

			$updated = array_merge($current, ['actionTaken' => $actionTaken]);

			// _rbac: false — isAuthorizedToRecordAction() above already enforced the
			// chair/secretary-only rule; see the class docblock note on declare().
			$saved = $this->objectService->saveObject(
				object: $updated,
				register: 'decidiq',
				schema: 'conflict-of-interest',
				uuid: $declarationId,
				_rbac: false
			);

			// OpenRegister's saveObject() returns ObjectEntityInterface — is_object() could
			// never be false, so `$updated` was never the value used.
			$payload = $saved->jsonSerialize();

			return [
				'success' => true,
				'declaration' => $payload,
				'message' => 'Action recorded.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to update conflict action',
				['declarationId' => $declarationId, 'exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'declaration' => null,
				'message' => 'Failed to record action.',
			];
		}//end try

	}//end recordAction()

	/**
	 * Return the most-restrictive active conflict for a given member + agenda
	 * item pair, or null when none is on file. Restrictiveness ordering is
	 * "recused-from-vote" > "recused-from-discussion" > "disclosed-and-participated"
	 * > "no-action-needed".
	 *
	 * @param string $membershipId UUID of the member's Membership (was Participant)
	 * @param string $agendaItemId UUID of the agenda item
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.2
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/proposal.md#in-scope
	 *
	 * @return array<string, mixed>|null
	 */
	public function getActiveConflicts(string $membershipId, string $agendaItemId): ?array {
		$matches = $this->findDeclarations(membershipId: $membershipId, agendaItemId: $agendaItemId);
		if ($matches === []) {
			return null;
		}

		$weight = [
			'recused-from-vote' => 4,
			'recused-from-discussion' => 3,
			'disclosed-and-participated' => 2,
			'no-action-needed' => 1,
		];

		usort(
			$matches,
			static function (array $a, array $b) use ($weight): int {
				return (($weight[$b['actionTaken'] ?? 'no-action-needed'] ?? 0) - ($weight[$a['actionTaken'] ?? 'no-action-needed'] ?? 0));
			}
		);

		return $matches[0];
	}//end getActiveConflicts()

	/**
	 * Internal: list all declarations matching the given member + agenda item.
	 *
	 * @param string $membershipId UUID of the member's Membership (was Participant)
	 * @param string $agendaItemId UUID of the agenda item
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findDeclarations(string $membershipId, string $agendaItemId): array {
		try {
			// ObjectService::prepareFindAllConfig() only reads the register/schema
			// context from filters.register / filters.schema — a TOP-LEVEL
			// 'register'/'schema' key (as this call previously used) is silently
			// ignored, so findAll() ran with no register/schema context and
			// returned nothing (same landmine documented on
			// ProxyVoteService::forMeeting(), decidiq#443).
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'conflict-of-interest',
						'boardMember' => $membershipId,
						'agendaItem' => $agendaItemId,
					],
					'limit' => 100,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to query conflict declarations',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === false) {
				continue;
			}

			$memberMatches = (($row['boardMember'] ?? null) === $membershipId);
			$itemMatches = (($row['agendaItem'] ?? null) === $agendaItemId);
			if ($memberMatches === true && $itemMatches === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end findDeclarations()

	/**
	 * Authorize access to one member's conflict-of-interest declarations:
	 * the caller must be the declaring Membership themselves, or a chair/
	 * secretary of the relevant meeting's GovernanceBody. Used by both
	 * `declare()` (recording a new declaration about oneself) and the
	 * `forMember()` read endpoint — the two share the same rule. Thin
	 * delegate to `ConflictOfInterestAuthorizationGuard`, kept as a public
	 * method here so `ConflictOfInterestController::forMember()` can call it
	 * without taking a direct dependency on the guard.
	 *
	 * @param string $membershipId UUID of the Membership the declaration is about
	 * @param string $agendaItemId UUID of the agenda item the declaration concerns
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 *
	 * @return bool
	 */
	public function isAuthorizedForMember(string $membershipId, string $agendaItemId, string $callerUid): bool {
		return $this->authorizationGuard->isAuthorizedForMember(
			membershipId: $membershipId,
			agendaItemId: $agendaItemId,
			callerUid: $callerUid
		);
	}//end isAuthorizedForMember()
}//end class
