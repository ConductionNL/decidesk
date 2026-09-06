<?php

/**
 * Decidiq Migrate Legacy Templates To DecisionTemplate Repair Step
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * unified-decision-templates schema-declaration change (ADR-032 chain link 1
 * of 3). Reads every live `process-template` and `vve-decision-template`
 * object and creates the equivalent `decision-template` object, tagged with
 * a `migratedFrom` provenance marker. Never deletes or edits a source
 * object — OpenRegister seed import is create-only, so an already-created
 * `process-template`/`vve-decision-template` object would otherwise be
 * stranded under a schema no consumer reads once the new fragment's built-in
 * `decision-template` seeds land (design.md Decision 4).
 *
 * Idempotent: re-running the step is a no-op for objects it already
 * migrated, matched by `migratedFrom.sourceSchema`/`migratedFrom.sourceUuid`
 * (migration.md step 2). Resume-safe: a partial run leaves some objects
 * migrated and some not; the next run picks up exactly where it left off.
 *
 * Graceful no-op: instances that never instantiated the legacy schemas (or
 * that have no OpenRegister available) have nothing to migrate; findAll()
 * throwing or returning empty exits cleanly with no error.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
 * @spec openspec/changes/unified-decision-templates/migration.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step migrating legacy ProcessTemplate/VveDecisionTemplate objects
 * onto the unified DecisionTemplate schema (non-destructive; sources are
 * never modified or deleted).
 *
 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
 */
class MigrateLegacyTemplatesToDecisionTemplate implements IRepairStep {

	/**
	 * The decidesk register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The legacy process-template schema slug.
	 *
	 * @var string
	 */
	private const SOURCE_PROCESS_TEMPLATE = 'process-template';

	/**
	 * The legacy vve-decision-template schema slug.
	 *
	 * @var string
	 */
	private const SOURCE_VVE_DECISION_TEMPLATE = 'vve-decision-template';

	/**
	 * The unified target schema slug.
	 *
	 * @var string
	 */
	private const TARGET_SCHEMA = 'decision-template';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Detects OpenRegister availability.
	 * @param ContainerInterface $container DI container (lazy-loads OR ObjectService).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 */
	public function getName(): string {
		return 'Migrate legacy Decidiq ProcessTemplate/VveDecisionTemplate objects to the unified DecisionTemplate schema';
	}//end getName()

	/**
	 * OpenRegister's ObjectService for the duration of one migration pass.
	 *
	 * A PROPERTY because the mappers are handed to migrateSchema() as
	 * first-class callables (`$this->mapProcessTemplate(...)`) and invoked as
	 * `$mapper($source, $uuid)` — so there is no argument slot to pass a
	 * collaborator through without changing both mapper signatures and the
	 * call contract between them.
	 *
	 * @var object|null
	 */
	private ?object $mappingObjectService = null;

	/**
	 * Run the migration.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 * @spec openspec/changes/unified-decision-templates/migration.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister not available — skipping DecisionTemplate migration.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('Could not resolve OpenRegister ObjectService — skipping DecisionTemplate migration.');
			$this->logger->warning(
				'Decidiq: DecisionTemplate migration could not resolve ObjectService',
				['error' => $e->getMessage()]
			);
			return;
		}

		// RUN AS SYSTEM. A migration executes during `occ upgrade`, where there
		// is no session — so OpenRegister sees the actor as 'Anonymous' and
		// refuses `create` on DecisionTemplate. Measured on a live upgrade before
		// this line existed: all 14 legacy templates failed with "User
		// 'Anonymous' does not have permission to 'create' objects in schema
		// 'DecisionTemplate'", and the step reported them one by one as
		// `$output->warning()` — which does not fail the upgrade. So the upgrade
		// said "Update successful", the summary said "0 migrated, 14 skipped",
		// and nothing anyone reads said the migration had not happened.
		$objectService->runAsSystem(
			function () use ($objectService, $output): void {
				$this->migrateAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Migrate both legacy template schemas.
	 *
	 * Split out of run() so the whole traversal sits inside ONE runAsSystem()
	 * scope: a per-save wrapper would re-enter for every object and leave the
	 * index build outside it.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$this->mappingObjectService = $objectService;
		$alreadyMigrated = $this->buildMigratedIndex(objectService: $objectService);
		$existingNames = $this->buildExistingNameIndex(objectService: $objectService);

		$migrated = 0;
		$skipped = 0;

		$this->migrateSchema(
			objectService: $objectService,
			sourceSchema: self::SOURCE_PROCESS_TEMPLATE,
			alreadyMigrated: $alreadyMigrated,
			existingNames: $existingNames,
			mapper: $this->mapProcessTemplate(...),
			output: $output,
			migrated: $migrated,
			skipped: $skipped,
		);

		$this->migrateSchema(
			objectService: $objectService,
			sourceSchema: self::SOURCE_VVE_DECISION_TEMPLATE,
			alreadyMigrated: $alreadyMigrated,
			existingNames: $existingNames,
			mapper: $this->mapVveDecisionTemplate(...),
			output: $output,
			migrated: $migrated,
			skipped: $skipped,
		);

		$output->info(
			'Decidiq DecisionTemplate migration complete: ' . $migrated . ' migrated, ' . $skipped . ' skipped.'
		);

	}//end migrateAll()

	/**
	 * Read every existing `decision-template` object and build a lookup of
	 * already-migrated source objects (migration.md step 2, idempotency).
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Keys of the form '<sourceSchema>|<sourceUuid>'.
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 */
	private function buildMigratedIndex(object $objectService): array {
		$index = [];

		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema(self::TARGET_SCHEMA);
			$existing = $objectService->findAll(['limit' => 1000]);
		} catch (Throwable $e) {
			// The decision-template schema/seeds have not been imported yet —
			// nothing has been migrated. InitializeSettings runs before this
			// step (info.xml ordering), so this is an unexpected but
			// non-fatal state; the loop below simply migrates everything.
			$this->logger->info(
				'Decidiq: DecisionTemplate migration found no existing decision-template objects yet',
				['error' => $e->getMessage()]
			);
			return $index;
		}

		foreach ($existing as $entity) {
			$object = $this->toArray(entity: $entity);
			if ($object === null) {
				continue;
			}

			$migratedFrom = ($object['migratedFrom'] ?? null);
			if (is_array($migratedFrom) === false) {
				continue;
			}

			$sourceSchema = (string)($migratedFrom['sourceSchema'] ?? '');
			$sourceUuid = (string)($migratedFrom['sourceUuid'] ?? '');
			if ($sourceSchema === '' || $sourceUuid === '') {
				continue;
			}

			$index[$sourceSchema . '|' . $sourceUuid] = true;
		}//end foreach

		return $index;
	}//end buildMigratedIndex()

	/**
	 * Whether this legacy row is already represented in `decision-template`.
	 *
	 * TWO WAYS IT CAN BE, and both matter:
	 *
	 *  - it was migrated on an earlier run, matched on `migratedFrom.sourceUuid`;
	 *  - a SEED already carries it, matched on name — see
	 *    buildExistingNameIndex() for why that is the honest key.
	 *
	 * Extracted so migrateSchema() stays under the cyclomatic-complexity
	 * threshold: adding the name check took it to exactly 10.
	 *
	 * @param array<string,bool> $alreadyMigrated Index keyed '<sourceSchema>|<sourceUuid>'.
	 * @param array<string,bool> $existingNames   Lower-cased names that already exist.
	 * @param string             $sourceSchema    The legacy schema slug.
	 * @param string             $uuid            The legacy row's uuid.
	 * @param string             $sourceName      The legacy row's lower-cased name.
	 *
	 * @return boolean True when it must be skipped.
	 */
	private function alreadyPresent(
		array $alreadyMigrated,
		array $existingNames,
		string $sourceSchema,
		string $uuid,
		string $sourceName,
	): bool {
		if (($alreadyMigrated[$sourceSchema . '|' . $uuid] ?? false) === true) {
			return true;
		}

		if ($sourceName !== '' && ($existingNames[$sourceName] ?? false) === true) {
			$this->logger->info(
				'Decidiq: skipping legacy template already present by name',
				['sourceSchema' => $sourceSchema, 'sourceUuid' => $uuid, 'name' => $sourceName]
			);

			return true;
		}

		return false;

	}//end alreadyPresent()

	/**
	 * The names of the decision-templates that already exist.
	 *
	 * 🔴 WITHOUT THIS, EVERY BUILT-IN TEMPLATE EXISTS TWICE.
	 *
	 * Two things produce the same logical template and neither knew about the
	 * other. `68-unified-decision-templates.json` SEEDS the thirteen built-ins,
	 * describing them in its own words as "5 ported 1:1 from ProcessTemplate, 2
	 * ported 1:1 from the urgency-enabled custom templates, and 6 ported from
	 * VveDecisionTemplate". This migration then reads those same legacy rows,
	 * which supersession deliberately KEPT, and creates a decision-template for
	 * each one.
	 *
	 * The existing idempotency index keys on `migratedFrom.sourceUuid`, so
	 * re-running the migration is safe — but a seeded row carries no
	 * `migratedFrom` at all, so it never matched, and the migration duplicated
	 * every one of them on first run.
	 *
	 * Measured on a live instance 2026-08-31, once the unified schema finally got
	 * a settings page to be seen through: 28 objects, of which 13 were
	 * duplicates — each built-in present once WITH its seeded slug and once with
	 * no slug at all. Nobody had noticed, because until that page existed the
	 * schema had no surface.
	 *
	 * Matching on NAME is the honest key here: the seeds are ports of these very
	 * rows, and a seeded object's `slug` is an import-time identifier that
	 * OpenRegister does not expose as a queryable property (the same trap
	 * `bodyUuidForSlug()` below documents), so the slug cannot be matched on.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Lower-cased template names that already exist.
	 */
	private function buildExistingNameIndex(object $objectService): array {
		$names = [];

		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema(self::TARGET_SCHEMA);
			$existing = $objectService->findAll(['limit' => 1000]);
		} catch (Throwable $e) {
			$this->logger->info(
				'Decidiq: DecisionTemplate migration could not read existing templates for name matching',
				['error' => $e->getMessage()]
			);
			return $names;
		}

		foreach ($existing as $entity) {
			$object = $this->toArray(entity: $entity);
			if ($object === null) {
				continue;
			}

			$name = trim((string)($object['name'] ?? ''));
			if ($name !== '') {
				$names[mb_strtolower($name)] = true;
			}
		}

		return $names;
	}//end buildExistingNameIndex()

	/**
	 * Migrate every live object of one legacy schema into `decision-template`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $sourceSchema The legacy schema slug being read.
	 * @param array<string,bool> $alreadyMigrated Idempotency index built before this call (migration.md step 2).
	 * @param array<string,bool> $existingNames Lower-cased names of decision-templates that already exist (by-ref: a row created here joins it).
	 * @param callable $mapper Maps a source object array to a decision-template payload.
	 * @param IOutput $output Progress reporting.
	 * @param integer $migrated Running count of migrated objects (by-ref accumulator).
	 * @param integer $skipped Running count of skipped objects (by-ref accumulator).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 * @spec openspec/changes/unified-decision-templates/migration.md
	 */
	private function migrateSchema(
		object $objectService,
		string $sourceSchema,
		array $alreadyMigrated,
		array &$existingNames,
		callable $mapper,
		IOutput $output,
		int &$migrated,
		int &$skipped,
	): void {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema($sourceSchema);
			$sourceObjects = $objectService->findAll(['limit' => 1000]);
		} catch (Throwable $e) {
			$output->info('No legacy ' . $sourceSchema . ' objects found — nothing to migrate.');
			$this->logger->info(
				'Decidiq: DecisionTemplate migration found no legacy ' . $sourceSchema . ' schema/objects',
				['error' => $e->getMessage()]
			);
			return;
		}

		foreach ($sourceObjects as $entity) {
			$source = $this->toArray(entity: $entity);
			if ($source === null) {
				$skipped++;
				continue;
			}

			$uuid = (string)($source['id'] ?? $source['uuid'] ?? '');
			if ($uuid === '') {
				$skipped++;
				continue;
			}

			$sourceName = mb_strtolower(trim((string)($source['name'] ?? '')));
			if ($this->alreadyPresent(
				alreadyMigrated: $alreadyMigrated,
				existingNames: $existingNames,
				sourceSchema: $sourceSchema,
				uuid: $uuid,
				sourceName: $sourceName,
			) === true
			) {
				$skipped++;
				continue;
			}

			try {
				$payload = $mapper($source, $uuid);
				$objectService->setRegister(self::REGISTER);
				$objectService->setSchema(self::TARGET_SCHEMA);
				$objectService->saveObject(
					register: self::REGISTER,
					schema: self::TARGET_SCHEMA,
					object: $payload,
				);
				$migrated++;
				// Record it, so a SECOND legacy row of the same name (a
				// process-template and a vve-decision-template can share one)
				// does not create a third copy in this same pass.
				if ($sourceName !== '') {
					$existingNames[$sourceName] = true;
				}

				$this->logger->info(
					'Decidiq: migrated ' . $sourceSchema . ' to decision-template',
					['sourceUuid' => $uuid]
				);
			} catch (Throwable $e) {
				$skipped++;
				$output->warning('Failed to migrate ' . $sourceSchema . ' ' . $uuid . ': ' . $e->getMessage());
				$this->logger->warning(
					'Decidiq: DecisionTemplate migration failed for one object',
					['sourceSchema' => $sourceSchema, 'sourceUuid' => $uuid, 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach

	}//end migrateSchema()

	/**
	 * Map a legacy `process-template` object to a `decision-template` payload
	 * (migration.md step 3). Every carried-forward field is copied verbatim;
	 * `decisionType`/`templateCategory` are left absent (generic default).
	 *
	 * @param array<string,mixed> $source The legacy process-template object.
	 * @param string $uuid The source object's UUID.
	 *
	 * @return array<string,mixed> The decision-template payload.
	 *
	 * @spec openspec/changes/unified-decision-templates/migration.md
	 */
	private function mapProcessTemplate(array $source, string $uuid): array {
		$payload = [
			'name' => ($source['name'] ?? ''),
			'description' => ($source['description'] ?? ''),
			'context' => ($source['context'] ?? null),
			'builtIn' => ($source['builtIn'] ?? false),
			'initialState' => ($source['initialState'] ?? null),
			'stateMachine' => ($source['stateMachine'] ?? null),
			'votingRule' => ($source['votingRule'] ?? null),
			'quorumRequired' => ($source['quorumRequired'] ?? null),
			'quorumRule' => ($source['quorumRule'] ?? null),
			'allowDecideWithoutVote' => ($source['allowDecideWithoutVote'] ?? null),
			'checklist' => [],
			'migratedFrom' => [
				'sourceSchema' => self::SOURCE_PROCESS_TEMPLATE,
				'sourceUuid' => $uuid,
			],
		];

		if (isset($source['urgencyPolicy']) === true) {
			$payload['urgencyPolicy'] = $this->resolveUrgencyPolicyRefs(
				policy: (array)$source['urgencyPolicy'],
			);
		}

		return $payload;
	}//end mapProcessTemplate()

	/**
	 * Resolve slug-shaped body references inside an urgencyPolicy to UUIDs.
	 *
	 * `urgencyPolicy.ratifyingBody` declares `format: uuid`, and its own
	 * description says seed refs "are stored by slug and mapped to UUID at
	 * import". The IMPORTER does that mapping; this migration did not — it
	 * copied the policy through verbatim, so a seeded template carrying
	 * `gemeenteraad-amsterdam` was rejected by format validation.
	 *
	 * This was invisible until the runAsSystem fix landed: every template
	 * previously failed on the identity error first, so the 2 that fail on
	 * their DATA only became reachable once the other 12 started succeeding.
	 * Fixing an outer error is what made the inner one observable.
	 *
	 * An unresolvable slug is left AS IS rather than blanked. The save then
	 * fails loudly with the same format message, which is a better outcome than
	 * silently dropping the body a template says must ratify its urgent
	 * decisions.
	 *
	 * @param array<string, mixed> $policy The source urgencyPolicy.
	 *
	 * @return array<string, mixed> The policy with body refs resolved.
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md
	 */
	private function resolveUrgencyPolicyRefs(array $policy): array {
		$ref = (string)($policy['ratifyingBody'] ?? '');
		if ($ref === '' || $this->looksLikeUuid(value: $ref) === true) {
			return $policy;
		}

		$uuid = $this->bodyUuidForSlug(slug: $ref);
		if ($uuid !== null) {
			$policy['ratifyingBody'] = $uuid;
		}

		return $policy;
	}//end resolveUrgencyPolicyRefs()

	/**
	 * Whether a value is already a UUID.
	 *
	 * @param string $value The value.
	 *
	 * @return bool True when it is UUID-shaped.
	 */
	private function looksLikeUuid(string $value): bool {
		return (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1);
	}//end looksLikeUuid()

	/**
	 * The GovernanceBody UUID for a slug, or null when it cannot be resolved.
	 *
	 * @param string $slug The body slug.
	 *
	 * @return string|null The uuid, or null.
	 */
	private function bodyUuidForSlug(string $slug): ?string {
		$objectService = $this->mappingObjectService;
		if ($objectService === null) {
			return null;
		}

		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema('governance-body');
			// THE SLUG LIVES IN `@self`, NOT IN THE OBJECT BODY.
			//
			// A seeded `slug:` key is an import-time identifier that OpenRegister
			// keeps as metadata; it is NOT a stored property. Filtering
			// `['slug' => …]` therefore matches nothing — measured on a live
			// instance: that filter returned 0 rows, and scanning all 60
			// governance bodies found no object carrying `slug` as a field at
			// all, while `['@self' => ['slug' => …]]` returned exactly 1.
			$rows = $objectService->findAll(
				['filters' => ['@self' => ['slug' => $slug]], 'limit' => 1]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not resolve a governance-body slug during template migration',
				['slug' => $slug, 'error' => $e->getMessage()]
			);
			return null;
		}

		foreach (($rows ?? []) as $row) {
			$body = $this->toArray(entity: $row);
			$uuid = (string)($body['id'] ?? $body['uuid'] ?? '');
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;
	}//end bodyUuidForSlug()

	/**
	 * Map a legacy `vve-decision-template` object to a `decision-template`
	 * payload (migration.md step 4): `decisionCategory` -> `templateCategory`,
	 * `defaultVoteThreshold` -> `votingRule.voteThreshold`,
	 * `defaultQuorumFraction` -> `quorumRule`, `context` fixed to
	 * `association`, `decisionType` fixed to `resolution`.
	 *
	 * @param array<string,mixed> $source The legacy vve-decision-template object.
	 * @param string $uuid The source object's UUID.
	 *
	 * @return array<string,mixed> The decision-template payload.
	 *
	 * @spec openspec/changes/unified-decision-templates/migration.md
	 */
	private function mapVveDecisionTemplate(array $source, string $uuid): array {
		$payload = [
			'name' => ($source['name'] ?? ''),
			'description' => ($source['description'] ?? ''),
			'context' => 'association',
			'decisionType' => 'resolution',
			'templateCategory' => ($source['decisionCategory'] ?? null),
			'builtIn' => ($source['builtIn'] ?? false),
			'proposedText' => ($source['proposedText'] ?? null),
			'regulationSource' => ($source['regulationSource'] ?? null),
			'checklist' => [],
			'migratedFrom' => [
				'sourceSchema' => self::SOURCE_VVE_DECISION_TEMPLATE,
				'sourceUuid' => $uuid,
			],
		];

		if (isset($source['defaultVoteThreshold']) === true) {
			$payload['votingRule'] = ['voteThreshold' => $source['defaultVoteThreshold']];
		}

		// `isset()` is already false for null — the `!== null` this replaces
		// could never be reached as false.
		if (isset($source['defaultQuorumFraction']) === true) {
			$payload['quorumRule'] = $source['defaultQuorumFraction'];
		}

		return $payload;
	}//end mapVveDecisionTemplate()

	/**
	 * Normalise an OR find/findAll result into a plain array.
	 *
	 * @param mixed $entity An ObjectEntity, array, or null.
	 *
	 * @return array<string,mixed>|null The object array, or null when unusable.
	 *
	 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
	 */
	private function toArray(mixed $entity): ?array {
		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$serialized = $entity->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return null;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$object = $entity->getObject();
			if (is_array($object) === true) {
				return $object;
			}

			return null;
		}

		if (is_array($entity) === true) {
			return $entity;
		}

		return null;
	}//end toArray()
}//end class
