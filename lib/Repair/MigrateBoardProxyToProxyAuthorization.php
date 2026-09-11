<?php

/**
 * Decidiq MigrateBoardProxyToProxyAuthorization Repair Step
 *
 * `board-proxy` is retired in favour of `proxy-authorization`
 * (model-debt-cleanup-schema, REQ-SDM-024): the approval-workflow concept
 * (`proxyStatus`) is folded onto `proxy-authorization`, which is also the
 * schema `ProxyVoteService` now writes exclusively (model-debt-cleanup-code
 * task 3). This step creates one `proxyAuthorization` object per existing
 * `board-proxy` row, resolving its Participant-identified
 * `grantorIntegration`/`holderIntegration` strings to `Person` UUIDs through
 * the shared `ParticipantToPersonMembershipResolver`.
 *
 * Non-destructive: the source `board-proxy` row is never mutated or
 * deleted, matching the `hardDelete: false` convention already declared on
 * that schema. Idempotent: no new schema property is added for the
 * idempotency check (model-debt-cleanup-schema does not declare one); a
 * re-run instead re-derives the same (grantor, holder, meeting) triple for
 * each source row and skips creating a duplicate `proxyAuthorization` when
 * one already exists with that triple. Every migrated row's source
 * `board-proxy` UUID is written to the log, not stored on the object
 * (migration.md's "transient note/log line").
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
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairmigrateboardproxytoproxyauthorization
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
 * Migrates board-proxy rows into proxy-authorization objects.
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairmigrateboardproxytoproxyauthorization
 */
class MigrateBoardProxyToProxyAuthorization implements IRepairStep {

	/**
	 * The OpenRegister register slug.
	 *
	 * FROZEN at the pre-rename spelling, and deliberately NOT Application::APP_ID.
	 * OpenRegister matches registers by slug; renaming it would resolve no
	 * register and this step would silently migrate nothing.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The retired board-proxy schema slug (source).
	 *
	 * @var string
	 */
	private const SOURCE_SCHEMA = 'board-proxy';

	/**
	 * The proxy-authorization schema slug (target).
	 *
	 * @var string
	 */
	private const TARGET_SCHEMA = 'proxy-authorization';

	/**
	 * The meeting schema slug.
	 *
	 * @var string
	 */
	private const MEETING_SCHEMA = 'meeting';

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
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairmigrateboardproxytoproxyauthorization
	 */
	public function getName(): string {
		return 'Migrate board-proxy rows into proxy-authorization objects';
	}//end getName()

	/**
	 * Migrate every board-proxy row into a proxy-authorization object.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairmigrateboardproxytoproxyauthorization
	 */
	public function run(IOutput $output): void {
		try {
			$sourceRows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SOURCE_SCHEMA,
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			$output->info('MigrateBoardProxyToProxyAuthorization: no board-proxy rows on this install; nothing to do.');
			$this->logger->info(
				'Decidiq: MigrateBoardProxyToProxyAuthorization found no board-proxy schema/objects',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$alreadyMigratedIndex = $this->buildTargetIndex();

		$migrated = 0;
		$alreadyMigrated = 0;
		$skipped = 0;

		foreach ($sourceRows as $entity) {
			$resolved = $this->resolveRow(entity: $entity);
			if ($resolved === null) {
				$skipped++;
				continue;
			}

			$key = $resolved['grantor'] . '|' . $resolved['holder'] . '|' . $resolved['meeting'];
			if (($alreadyMigratedIndex[$key] ?? false) === true) {
				$alreadyMigrated++;
				continue;
			}

			if ($this->saveMigratedRow(resolved: $resolved, output: $output) === false) {
				$skipped++;
				continue;
			}

			$migrated++;
			$alreadyMigratedIndex[$key] = true;
		}//end foreach

		$output->info(
			'MigrateBoardProxyToProxyAuthorization: ' . $migrated . ' migrated, '
			. $alreadyMigrated . ' already migrated, ' . $skipped . ' skipped.'
		);

	}//end run()

	/**
	 * Resolve one legacy board-proxy row to its proxy-authorization shape.
	 *
	 * Returns null when the row is unusable — malformed, or carrying a
	 * grantor/holder/meeting reference that no longer resolves. Null is the
	 * caller's "skip" signal; the unresolvable-reference path logs why, so a
	 * skipped row is never silent.
	 *
	 * @param mixed $entity One row as returned by ObjectService::findAll().
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairmigrateboardproxytoproxyauthorization
	 *
	 * @return array{sourceId: string, grantor: string, holder: string, meeting: string, proxyStatus: string}|null Resolved row, or null to skip.
	 */
	private function resolveRow(mixed $entity): ?array {
		$source = $this->toArray(entity: $entity);
		if ($source === null) {
			return null;
		}

		$sourceId = (string)($source['id'] ?? $source['uuid'] ?? '');
		if ($sourceId === '') {
			return null;
		}

		$grantorParticipant = (string)($source['grantorIntegration'] ?? '');
		$holderParticipant = (string)($source['holderIntegration'] ?? '');
		$meetingId = (string)($source['meetingIntegration'] ?? '');

		$grantor = null;
		if ($grantorParticipant !== '') {
			$grantor = $this->resolver->resolve(participantId: $grantorParticipant);
		}

		$holder = null;
		if ($holderParticipant !== '') {
			$holder = $this->resolver->resolve(participantId: $holderParticipant);
		}

		$meetingExists = ($meetingId !== '' && $this->exists(id: $meetingId, schema: self::MEETING_SCHEMA));

		if ($grantor === null || $holder === null || $meetingExists === false) {
			$this->logger->warning(
				'Decidiq: MigrateBoardProxyToProxyAuthorization skipped an unresolvable board-proxy row',
				[
					'sourceBoardProxyUuid' => $sourceId,
					'grantorResolved' => ($grantor !== null),
					'holderResolved' => ($holder !== null),
					'meetingResolved' => $meetingExists,
				]
			);
			return null;
		}

		return [
			'sourceId' => $sourceId,
			'grantor' => $grantor['person'],
			'holder' => $holder['person'],
			'meeting' => $meetingId,
			'proxyStatus' => (string)($source['proxyStatus'] ?? 'pending-approval'),
		];

	}//end resolveRow()

	/**
	 * Persist one resolved row as a proxy-authorization object.
	 *
	 * @param array $resolved The resolved row from resolveRow().
	 * @param IOutput $output Repair output channel.
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairmigrateboardproxytoproxyauthorization
	 *
	 * @return boolean True when the object was created, false when the save failed.
	 */
	private function saveMigratedRow(array $resolved, IOutput $output): bool {
		$payload = [
			'grantor' => $resolved['grantor'],
			'holder' => $resolved['holder'],
			'meeting' => $resolved['meeting'],
			'proxyStatus' => $resolved['proxyStatus'],
			// A migrated row starts at the same unsigned state every fresh
			// proxy-authorization would — a legacy board-proxy row never had a
			// signed machtiging document, so there is nothing to carry over.
			'signatureStatus' => 'unsigned',
		];

		try {
			$this->objectService->saveObject(object: $payload, register: self::REGISTER, schema: self::TARGET_SCHEMA);
			$this->logger->info(
				'Decidiq: MigrateBoardProxyToProxyAuthorization created a proxy-authorization object',
				[
					'sourceBoardProxyUuid' => $resolved['sourceId'],
					'grantor' => $resolved['grantor'],
					'holder' => $resolved['holder'],
					'meeting' => $resolved['meeting'],
				]
			);
			return true;
		} catch (Throwable $e) {
			$output->warning('Failed to migrate board-proxy ' . $resolved['sourceId'] . ': ' . $e->getMessage());
			$this->logger->warning(
				'Decidiq: MigrateBoardProxyToProxyAuthorization failed to save one object',
				['sourceBoardProxyUuid' => $resolved['sourceId'], 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end saveMigratedRow()

	/**
	 * Build an index of (grantor|holder|meeting) triples already present
	 * among existing proxy-authorization objects, for the idempotency check.
	 *
	 * @return array<string, bool>
	 */
	private function buildTargetIndex(): array {
		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::TARGET_SCHEMA,
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		$index = [];
		foreach ($rows as $entity) {
			$row = $this->toArray(entity: $entity);
			if ($row === null) {
				continue;
			}

			$grantor = (string)($row['grantor'] ?? '');
			$holder = (string)($row['holder'] ?? '');
			$meeting = (string)($row['meeting'] ?? '');
			if ($grantor === '' || $holder === '' || $meeting === '') {
				continue;
			}

			$index[$grantor . '|' . $holder . '|' . $meeting] = true;
		}

		return $index;
	}//end buildTargetIndex()

	/**
	 * Whether an id currently resolves to a live object of the given schema.
	 *
	 * @param string $id UUID to check
	 * @param string $schema Schema slug to check against
	 *
	 * @return bool
	 */
	private function exists(string $id, string $schema): bool {
		try {
			$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable $e) {
			return false;
		}

		return $entity !== null;
	}//end exists()

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
