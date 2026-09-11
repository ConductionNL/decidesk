<?php

/**
 * Decidiq RepointConflictOfInterestBoardMember Repair Step
 *
 * `model-debt-cleanup-schema` retargets `ConflictOfInterest.boardMember`
 * from `$ref: Participant` to `$ref: Membership`; that edit changes only the
 * DECLARATION, so every `conflict-of-interest` row written before this
 * change still holds a Participant UUID in `boardMember`. This repair step
 * resolves each of those UUIDs through `ParticipantToPersonMembershipResolver`
 * and rewrites `boardMember` to the resulting Membership UUID.
 *
 * Idempotent and non-destructive: a `boardMember` value that no longer
 * resolves to a live Participant is assumed already migrated and left
 * alone; a `conflict-of-interest` row is only ever updated, never deleted;
 * no Participant row is ever mutated. Safe to re-run.
 *
 * @category Repair
 * @package  OCA\Decidiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairrepointconflictofinterestboardmember
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Repair;

use OCA\Decidiq\Service\ParticipantToPersonMembershipResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repoints ConflictOfInterest.boardMember from Participant to Membership.
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairrepointconflictofinterestboardmember
 */
class RepointConflictOfInterestBoardMember implements IRepairStep {

	/**
	 * The OpenRegister register slug.
	 *
	 * FROZEN at the pre-rename spelling, and deliberately NOT Application::APP_ID.
	 * OpenRegister matches registers by slug; renaming it would resolve no
	 * register and this step would silently repoint nothing.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The conflict-of-interest schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'conflict-of-interest';

	/**
	 * The deprecated Participant schema slug.
	 *
	 * @var string
	 */
	private const PARTICIPANT_SCHEMA = 'participant';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param ParticipantToPersonMembershipResolver $resolver Shared crosswalk resolver
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantToPersonMembershipResolver $resolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairrepointconflictofinterestboardmember
	 */
	public function getName(): string {
		return 'Repoint ConflictOfInterest.boardMember from Participant to Membership';
	}//end getName()

	/**
	 * Resolve every conflict-of-interest row's boardMember.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairrepointconflictofinterestboardmember
	 */
	public function run(IOutput $output): void {
		try {
			// See ParticipantToPersonMembershipResolver::findOnePersonBy() for the
			// register/schema-must-live-inside-filters landmine this mirrors.
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SCHEMA,
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			$output->info('RepointConflictOfInterestBoardMember: no conflict-of-interest rows on this install; nothing to do.');
			$this->logger->info(
				'Decidiq: RepointConflictOfInterestBoardMember found no conflict-of-interest schema/objects',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$resolved = 0;
		$alreadyMigrated = 0;
		$skipped = 0;

		foreach ($rows as $entity) {
			$keys = $this->rowKeys(entity: $entity);
			if ($keys === null) {
				$skipped++;
				continue;
			}

			[$id, $boardMember, $row] = $keys;

			$stillParticipant = $this->isParticipant(id: $boardMember);
			if ($stillParticipant === null) {
				// Unknown — the lookup itself failed. Count as skipped so the
				// row stays eligible on the next run; never as already-migrated.
				$skipped++;
				continue;
			}

			if ($stillParticipant === false) {
				// Already migrated to a Membership UUID, or otherwise not a live
				// Participant. Either way there is nothing this step can safely
				// do — leave it alone.
				$alreadyMigrated++;
				continue;
			}

			$resolution = $this->resolver->resolve(participantId: $boardMember);
			if ($resolution === null) {
				$skipped++;
				$this->logger->warning(
					'Decidiq: RepointConflictOfInterestBoardMember could not resolve boardMember',
					['id' => $id, 'boardMember' => $boardMember]
				);
				continue;
			}

			try {
				$this->objectService->saveObject(
					object: array_merge($row, ['boardMember' => $resolution['membership']]),
					register: self::REGISTER,
					schema: self::SCHEMA,
					uuid: $id
				);
				$resolved++;
				$this->logger->info(
					'Decidiq: RepointConflictOfInterestBoardMember repointed boardMember',
					['id' => $id, 'fromParticipant' => $boardMember, 'toMembership' => $resolution['membership']]
				);
			} catch (Throwable $e) {
				$skipped++;
				$output->warning('Failed to repoint conflict-of-interest ' . $id . ': ' . $e->getMessage());
				$this->logger->warning(
					'Decidiq: RepointConflictOfInterestBoardMember failed to save one object',
					['id' => $id, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(
			'RepointConflictOfInterestBoardMember: ' . $resolved . ' resolved, '
			. $alreadyMigrated . ' already migrated, ' . $skipped . ' skipped.'
		);

	}//end run()

	/**
	 * Normalise one row and pull the two keys this step needs.
	 *
	 * @param mixed $entity One row as returned by ObjectService::findAll().
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairrepointconflictofinterestboardmember
	 *
	 * @return array{0: string, 1: string, 2: array<string, mixed>}|null [id, boardMember, row], or null when the row is unusable.
	 */
	private function rowKeys(mixed $entity): ?array {
		$row = $this->toArray(entity: $entity);
		if ($row === null) {
			return null;
		}

		$id = (string)($row['id'] ?? $row['uuid'] ?? '');
		$boardMember = (string)($row['boardMember'] ?? '');
		if ($id === '' || $boardMember === '') {
			return null;
		}

		return [$id, $boardMember, $row];
	}//end rowKeys()

	/**
	 * Whether an id currently resolves to a live Participant object.
	 *
	 * @param string $id UUID to check
	 *
	 * @return bool
	 */
	private function isParticipant(string $id): ?bool {
		try {
			$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: self::PARTICIPANT_SCHEMA);
		} catch (Throwable $e) {
			// Null means UNKNOWN, not "no". A transient OpenRegister failure
			// here used to be indistinguishable from "already migrated", so a
			// row could be counted as done and silently never repointed — on a
			// step whose whole value is that re-running it finishes the job.
			// The caller now counts unknown as skipped, leaving the row
			// eligible for the next run.
			$this->logger->warning(
				'Decidiq: RepointConflictOfInterestBoardMember could not determine whether a boardMember id is still a Participant',
				['boardMemberId' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $entity !== null;
	}//end isParticipant()

	/**
	 * Normalise an OR find/findAll result into a plain array.
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
