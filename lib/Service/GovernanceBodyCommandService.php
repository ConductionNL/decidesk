<?php

/**
 * Decidiq governance-body command engine.
 *
 * Applies a cross-app command to raise or update a GovernanceBody with its
 * roster. The engine that sits behind GovernanceBodyRequestedListener, kept
 * separate from it so the rules — what is resolved before what is written, what
 * is refused — are testable without a dispatcher.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Idempotent upsert of a GovernanceBody plus its Person/Membership roster.
 *
 * Every write in here is preceded by a resolve. That is the whole design: a
 * migration in the producing app is re-runnable by construction, and a partial
 * run is completed by the next one rather than duplicated by it.
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */
class GovernanceBodyCommandService {

	/**
	 * Schema slug of the body.
	 */
	private const SCHEMA_BODY = 'governance-body';

	/**
	 * Schema slug of a natural person.
	 */
	private const SCHEMA_PERSON = 'person';

	/**
	 * Schema slug of a person's seat on a body.
	 */
	private const SCHEMA_MEMBERSHIP = 'membership';

	/**
	 * Body fields a command may set beyond the five it states explicitly.
	 *
	 * A closed list, not a passthrough: an open merge would let a producing app
	 * write `sourceApp` or `externalReference` itself and break the key this
	 * service resolves on.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_ATTRIBUTES = [
		'quorum',
		'quorumRule',
		'jurisdiction',
		'statutoryBasis',
		'votingDefault',
		'termStart',
		'termEnd',
		'parentBody',
		'workflowTemplate',
	];

	/**
	 * Membership roles this seam accepts, matching Membership.role.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_ROLES = [
		'chair',
		'vice-chair',
		'secretary',
		'treasurer',
		'member',
		'observer',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes decidiq's register objects.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * Raise or update a governance body and its roster.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own id for the originating record.
	 * @param array<string, mixed> $body Body fields; MUST carry name, bodyType, domain and active.
	 * @param array<int, array<string, mixed>> $members Roster entries of {uid, role, external, label}.
	 *
	 * @return array{id: string, created: bool} The resolved id and whether it was minted here.
	 *
	 * @throws RuntimeException When the command is incomplete or a write fails.
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function upsert(
		string $sourceApp,
		string $externalReference,
		array $body,
		array $members = [],
	): array {
		if ($sourceApp === '' || $externalReference === '') {
			throw new RuntimeException(
				'A governance-body command needs both sourceApp and externalReference: they are the key a re-run resolves on'
			);
		}

		$payload = $this->buildBodyPayload(
			sourceApp: $sourceApp,
			externalReference: $externalReference,
			body: $body,
		);

		$existing = $this->findBody(sourceApp: $sourceApp, externalReference: $externalReference);
		$created = ($existing === null);

		// The body is saved and its id read BEFORE the first membership write.
		// A membership written against an unsaved body points at nothing, and a
		// crash between the two then leaves orphans instead of a body the next
		// run can complete (REQ-GBE-004).
		$stored = $this->store->save(
			schema: self::SCHEMA_BODY,
			object: $payload,
			uuid: $this->idOf(row: ($existing ?? [])),
		);

		$bodyId = $this->idOf(row: $stored);
		if ($bodyId === null) {
			throw new RuntimeException('OpenRegister returned a governance body with no id');
		}

		$this->syncRoster(bodyId: $bodyId, members: $members);

		return [
			'id' => $bodyId,
			'created' => $created,
		];

	}//end upsert()

	/**
	 * Build the body object to store.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own reference.
	 * @param array<string, mixed> $body The commanded body fields.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @throws RuntimeException When a required field is missing.
	 */
	private function buildBodyPayload(string $sourceApp, string $externalReference, array $body): array {
		foreach (['name', 'bodyType', 'domain'] as $required) {
			if (($body[$required] ?? '') === '') {
				throw new RuntimeException('A governance-body command needs a ' . $required);
			}
		}

		// `active` decides whether the body may be assigned new work, and the
		// consuming app throws on it. Defaulting an absent value to true would
		// route objections to a disbanded committee and nothing would error, so
		// an omitted `active` is refused instead (REQ-GBE-005).
		if (array_key_exists('active', $body) === false || is_bool($body['active']) === false) {
			throw new RuntimeException(
				'A governance-body command must state `active` as a boolean: it is never defaulted'
			);
		}

		$payload = [
			'name' => (string)$body['name'],
			'bodyType' => (string)$body['bodyType'],
			'domain' => (string)$body['domain'],
			'active' => $body['active'],
			'sourceApp' => $sourceApp,
			'externalReference' => $externalReference,
		];

		foreach (self::ALLOWED_ATTRIBUTES as $key) {
			if (array_key_exists($key, $body) === true && $body[$key] !== null && $body[$key] !== '') {
				$payload[$key] = $body[$key];
			}
		}

		return $payload;

	}//end buildBodyPayload()

	/**
	 * Resolve an existing body by its provenance pair.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own reference.
	 *
	 * @return array<string, mixed>|null The row, or null when none matches.
	 */
	private function findBody(string $sourceApp, string $externalReference): ?array {
		$rows = $this->store->findAll(
			schema: self::SCHEMA_BODY,
			filters: [
				'sourceApp' => $sourceApp,
				'externalReference' => $externalReference,
			],
		);

		// The filter is re-checked in PHP rather than trusted. A filter key the
		// store does not recognise is dropped rather than refused, and a dropped
		// filter returns EVERY body — which would make this method match the
		// first unrelated row and silently overwrite it.
		foreach ($rows as $row) {
			$matchesApp = ((string)($row['sourceApp'] ?? '') === $sourceApp);
			$matchesRef = ((string)($row['externalReference'] ?? '') === $externalReference);
			if ($matchesApp === true && $matchesRef === true) {
				return $row;
			}
		}

		return null;

	}//end findBody()

	/**
	 * Create or update one seat per roster entry.
	 *
	 * @param string $bodyId The stored body's id.
	 * @param array<int, array<string, mixed>> $members The roster.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When an entry is unusable.
	 */
	private function syncRoster(string $bodyId, array $members): void {
		foreach ($members as $member) {
			$uid = trim((string)($member['uid'] ?? ''));
			if ($uid === '') {
				throw new RuntimeException('A roster entry needs a uid');
			}

			$role = (string)($member['role'] ?? 'member');
			if (in_array($role, self::ALLOWED_ROLES, true) === false) {
				throw new RuntimeException('Unknown membership role: ' . $role);
			}

			$personId = $this->resolvePerson(uid: $uid, member: $member);
			$this->upsertMembership(
				bodyId: $bodyId,
				personId: $personId,
				role: $role,
				member: $member,
			);
		}

	}//end syncRoster()

	/**
	 * Find the Person for a Nextcloud uid, creating one only when absent.
	 *
	 * @param string $uid The Nextcloud user id.
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return string The person's id.
	 *
	 * @throws RuntimeException When the person cannot be stored.
	 */
	private function resolvePerson(string $uid, array $member): string {
		$rows = $this->store->findAll(
			schema: self::SCHEMA_PERSON,
			filters: ['nextcloudUserId' => $uid],
		);

		foreach ($rows as $row) {
			if ((string)($row['nextcloudUserId'] ?? '') !== $uid) {
				continue;
			}

			$id = $this->idOf(row: $row);
			if ($id !== null) {
				return $id;
			}
		}

		$name = trim((string)($member['name'] ?? ''));
		if ($name === '') {
			$name = $uid;
		}

		$stored = $this->store->save(
			schema: self::SCHEMA_PERSON,
			object: [
				'name' => $name,
				'nextcloudUserId' => $uid,
			],
		);

		$id = $this->idOf(row: $stored);
		if ($id === null) {
			throw new RuntimeException('OpenRegister returned a person with no id');
		}

		return $id;

	}//end resolvePerson()

	/**
	 * Create or update the one seat this person holds on this body.
	 *
	 * @param string $bodyId The body's id.
	 * @param string $personId The person's id.
	 * @param string $role The membership role.
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return void
	 */
	private function upsertMembership(string $bodyId, string $personId, string $role, array $member): void {
		$rows = $this->store->findAll(
			schema: self::SCHEMA_MEMBERSHIP,
			filters: [
				'governanceBody' => $bodyId,
				'person' => $personId,
			],
		);

		$existingId = null;
		foreach ($rows as $row) {
			$sameBody = ($this->refOf(value: ($row['governanceBody'] ?? null)) === $bodyId);
			$samePerson = ($this->refOf(value: ($row['person'] ?? null)) === $personId);
			if ($sameBody === true && $samePerson === true) {
				$existingId = $this->idOf(row: $row);
				break;
			}
		}

		$object = [
			'governanceBody' => $bodyId,
			'person' => $personId,
			'role' => $role,
			// Awb 7:13(2): the secretary sits from outside the administrative
			// organ. The producer states it; an absent value reads as false
			// because "not declared external" is the ordinary case.
			'external' => (bool)($member['external'] ?? false),
		];

		$label = trim((string)($member['label'] ?? ''));
		if ($label !== '') {
			$object['label'] = $label;
		}

		$this->store->save(
			schema: self::SCHEMA_MEMBERSHIP,
			object: $object,
			uuid: $existingId,
		);

	}//end upsertMembership()

	/**
	 * Read an object's id out of either shape OpenRegister returns.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null The id, or null when absent.
	 */
	private function idOf(array $row): ?string {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($id === '') {
			return null;
		}

		return $id;

	}//end idOf()

	/**
	 * Reduce a relation value to the id it points at.
	 *
	 * OpenRegister returns a relation as a bare uuid string, or as the expanded
	 * object when the read inlined it. Comparing the raw value would miss the
	 * expanded form and mint a second membership on every re-run.
	 *
	 * @param mixed $value The relation value.
	 *
	 * @return string The id, or an empty string.
	 */
	private function refOf(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value['id'] ?? ($value['@self']['id'] ?? ''));
		}

		return (string)$value;

	}//end refOf()

}//end class
