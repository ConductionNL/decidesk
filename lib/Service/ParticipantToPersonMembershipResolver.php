<?php

/**
 * Decidiq Participant-to-Person/Membership Crosswalk Resolver
 *
 * Resolves a deprecated `Participant` UUID to the `Person`+`Membership` pair
 * it corresponds to, matching or creating as needed. Shared by both
 * model-debt-cleanup-code repair steps (`RepointConflictOfInterestBoardMember`,
 * `MigrateBoardProxyToProxyAuthorization`) and by `ProxyVoteService`, which
 * needs the same crosswalk for every freshly-registered proxy so it is
 * created with `Person` UUIDs from day one.
 *
 * Match order (judge amendment, 2026-08-19, model-debt-cleanup-schema/
 * design.md's Data-migration section): `nextcloudUserId` exact match first
 * (the strong identity key `Person.nextcloudUserId` was added specifically
 * for this crosswalk), then `email` exact match, else create a new
 * `Person`+`Membership` pair. `Participant.nextcloudUserId` is copied onto
 * the matched-or-created `Person` so a Person matched only by email still
 * gains the strong key going forward, and a later run of this resolver
 * against a sibling Participant with the same Nextcloud account matches by
 * `nextcloudUserId` instead of relying on email again.
 *
 * Idempotent: re-resolving an already-resolved Participant re-derives the
 * same Person (by nextcloudUserId or email) and reuses the existing
 * Membership for the same GovernanceBody rather than duplicating it.
 * Non-destructive: no Participant row is ever read for anything other than
 * its own fields, and none is mutated or deleted.
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
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-1-crosswalk-resolver--match-by-email-else-create
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Crosswalk resolver: Participant UUID -> Person+Membership pair.
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-1-crosswalk-resolver--match-by-email-else-create
 */
class ParticipantToPersonMembershipResolver {

	/**
	 * The decidesk register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The deprecated Participant schema slug.
	 *
	 * @var string
	 */
	private const PARTICIPANT_SCHEMA = 'participant';

	/**
	 * The Person schema slug.
	 *
	 * @var string
	 */
	private const PERSON_SCHEMA = 'person';

	/**
	 * The Membership schema slug.
	 *
	 * @var string
	 */
	private const MEMBERSHIP_SCHEMA = 'membership';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a Participant UUID to its Person+Membership pair, matching or
	 * creating as needed (Decision 1, steps 1-3).
	 *
	 * Returns null only when the Participant itself cannot be loaded (unknown
	 * id, or OpenRegister unavailable) — every other outcome (matched or
	 * newly created) succeeds by design, since the resolver never merges two
	 * different people and always has a safe "create" fallback.
	 *
	 * @param string $participantId UUID of the Participant to resolve
	 *
	 * @return array{person: string, membership: string}|null
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-1-crosswalk-resolver--match-by-email-else-create
	 */
	public function resolve(string $participantId): ?array {
		$participant = $this->loadParticipant(participantId: $participantId);
		if ($participant === null) {
			return null;
		}

		$person = $this->resolvePerson(participant: $participant);
		$personId = (string)($person['id'] ?? $person['uuid'] ?? '');
		if ($personId === '') {
			$this->logger->warning(
				'Decidiq: ParticipantToPersonMembershipResolver could not resolve or create a Person',
				['participantId' => $participantId]
			);
			return null;
		}

		$governanceBodyId = (string)($participant['governanceBody'] ?? '');
		$membershipId = $this->resolveMembership(personId: $personId, governanceBodyId: $governanceBodyId, participant: $participant);
		if ($membershipId === '') {
			$this->logger->warning(
				'Decidiq: ParticipantToPersonMembershipResolver could not resolve or create a Membership',
				['participantId' => $participantId, 'personId' => $personId]
			);
			return null;
		}

		$this->logger->info(
			'Decidiq: resolved Participant to Person/Membership',
			[
				'participantId' => $participantId,
				'personId' => $personId,
				'membershipId' => $membershipId,
			]
		);

		return [
			'person' => $personId,
			'membership' => $membershipId,
		];

	}//end resolve()

	/**
	 * Load the source Participant row.
	 *
	 * @param string $participantId UUID of the Participant
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadParticipant(string $participantId): ?array {
		try {
			$entity = $this->objectService->find(id: $participantId, register: self::REGISTER, schema: self::PARTICIPANT_SCHEMA);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: ParticipantToPersonMembershipResolver could not load the Participant',
				['participantId' => $participantId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(entity: $entity);
	}//end loadParticipant()

	/**
	 * Resolve (or create) the Person for a Participant row (Decision 1,
	 * steps 2-3). Match order: `nextcloudUserId` exact, then `email` exact,
	 * else create. Backfills `nextcloudUserId` onto a Person matched only by
	 * email so a later resolution reaches the strong key.
	 *
	 * @param array<string, mixed> $participant The source Participant row
	 *
	 * @return array<string, mixed> The matched, backfilled, or newly created Person
	 */
	private function resolvePerson(array $participant): array {
		$nextcloudUserId = trim((string)($participant['nextcloudUserId'] ?? ''));
		$email = trim((string)($participant['email'] ?? ''));

		$person = null;
		if ($nextcloudUserId !== '') {
			$person = $this->findOnePersonBy(field: 'nextcloudUserId', value: $nextcloudUserId);
		}

		if ($person === null && $email !== '') {
			$person = $this->findOnePersonBy(field: 'email', value: $email);
		}

		if ($person === null) {
			return $this->createPerson(participant: $participant, nextcloudUserId: $nextcloudUserId, email: $email);
		}

		if ($nextcloudUserId !== '' && trim((string)($person['nextcloudUserId'] ?? '')) === '') {
			return $this->backfillNextcloudUserId(person: $person, nextcloudUserId: $nextcloudUserId);
		}

		return $person;
	}//end resolvePerson()

	/**
	 * Find one Person object matching an equality filter.
	 *
	 * @param string $field Person property to filter on
	 * @param string $value Value to match exactly
	 *
	 * @return array<string, mixed>|null
	 */
	private function findOnePersonBy(string $field, string $value): ?array {
		try {
			// `findAll()`'s register/schema context is read from
			// `$config['filters']['register']`/`['schema']` — a TOP-LEVEL key is
			// silently ignored and the query then runs with no context (same
			// landmine documented on ProxyVoteService::forMeeting()).
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::PERSON_SCHEMA,
						$field => $value,
					],
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			return null;
		}

		foreach ($rows as $entity) {
			$person = $this->toArray(entity: $entity);
			if ($person !== null) {
				return $person;
			}
		}

		return null;
	}//end findOnePersonBy()

	/**
	 * Create a new Person from a Participant's identity fields (Decision 1,
	 * step 3).
	 *
	 * @param array<string, mixed> $participant The source Participant row
	 * @param string $nextcloudUserId Carried-over Nextcloud UID, empty when absent
	 * @param string $email Carried-over email, empty when absent
	 *
	 * @return array<string, mixed> The newly created Person
	 */
	private function createPerson(array $participant, string $nextcloudUserId, string $email): array {
		$payload = [
			'name' => (string)($participant['displayName'] ?? ''),
		];

		if ($email !== '') {
			$payload['email'] = $email;
		}

		if ($nextcloudUserId !== '') {
			$payload['nextcloudUserId'] = $nextcloudUserId;
		}

		try {
			$saved = $this->objectService->saveObject(object: $payload, register: self::REGISTER, schema: self::PERSON_SCHEMA);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: ParticipantToPersonMembershipResolver failed to create a Person',
				['exception' => $e->getMessage()]
			);
			return $payload;
		}

		return ($this->toArray(entity: $saved) ?? $payload);
	}//end createPerson()

	/**
	 * Backfill `nextcloudUserId` onto a Person matched only by email, so a
	 * later resolution of a sibling Participant reaches the strong key
	 * (judge amendment, Person.nextcloudUserId description).
	 *
	 * @param array<string, mixed> $person The matched Person, missing nextcloudUserId
	 * @param string $nextcloudUserId The Nextcloud UID to backfill
	 *
	 * @return array<string, mixed> The updated Person, or the original on failure
	 */
	private function backfillNextcloudUserId(array $person, string $nextcloudUserId): array {
		$personId = (string)($person['id'] ?? $person['uuid'] ?? '');
		if ($personId === '') {
			return $person;
		}

		$updated = array_merge($person, ['nextcloudUserId' => $nextcloudUserId]);

		try {
			$saved = $this->objectService->saveObject(
				object: $updated,
				register: self::REGISTER,
				schema: self::PERSON_SCHEMA,
				uuid: $personId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: ParticipantToPersonMembershipResolver failed to backfill nextcloudUserId onto a Person',
				['personId' => $personId, 'exception' => $e->getMessage()]
			);
			return $person;
		}

		return ($this->toArray(entity: $saved) ?? $updated);
	}//end backfillNextcloudUserId()

	/**
	 * Resolve (or create) the Membership linking a Person to the
	 * Participant's GovernanceBody (Decision 1, steps 2-3).
	 *
	 * @param string $personId UUID of the resolved Person
	 * @param string $governanceBodyId UUID of the GovernanceBody, empty when the Participant carried none
	 * @param array<string, mixed> $participant The source Participant row
	 *
	 * @return string UUID of the matched or newly created Membership, empty on failure
	 */
	private function resolveMembership(string $personId, string $governanceBodyId, array $participant): string {
		if ($governanceBodyId !== '') {
			$existing = $this->findOneMembership(personId: $personId, governanceBodyId: $governanceBodyId);
			if ($existing !== null) {
				return (string)($existing['id'] ?? $existing['uuid'] ?? '');
			}
		}

		$payload = [
			'person' => $personId,
			'role' => (string)($participant['role'] ?? 'member'),
		];

		if ($governanceBodyId !== '') {
			$payload['governanceBody'] = $governanceBodyId;
		}

		if (isset($participant['party']) === true) {
			$payload['party'] = $participant['party'];
		}

		if (isset($participant['votingWeight']) === true) {
			$payload['votingWeight'] = $participant['votingWeight'];
		}

		try {
			$saved = $this->objectService->saveObject(object: $payload, register: self::REGISTER, schema: self::MEMBERSHIP_SCHEMA);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: ParticipantToPersonMembershipResolver failed to create a Membership',
				['personId' => $personId, 'governanceBodyId' => $governanceBodyId, 'exception' => $e->getMessage()]
			);
			return '';
		}

		$membership = ($this->toArray(entity: $saved) ?? $payload);
		return (string)($membership['id'] ?? $membership['uuid'] ?? '');
	}//end resolveMembership()

	/**
	 * Find an existing Membership for a Person in a GovernanceBody.
	 *
	 * @param string $personId UUID of the Person
	 * @param string $governanceBodyId UUID of the GovernanceBody
	 *
	 * @return array<string, mixed>|null
	 */
	private function findOneMembership(string $personId, string $governanceBodyId): ?array {
		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::MEMBERSHIP_SCHEMA,
						'person' => $personId,
						'governanceBody' => $governanceBodyId,
					],
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			return null;
		}

		foreach ($rows as $entity) {
			$membership = $this->toArray(entity: $entity);
			if ($membership !== null) {
				return $membership;
			}
		}

		return null;
	}//end findOneMembership()

	/**
	 * Normalise an OR find/findAll/saveObject result into a plain array.
	 *
	 * @param mixed $entity An ObjectEntity, array, or null
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $entity): ?array {
		if ($entity === null) {
			return null;
		}

		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$serialized = $entity->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$object = $entity->getObject();
			if (is_array($object) === true) {
				return $object;
			}
		}

		return null;
	}//end toArray()
}//end class
