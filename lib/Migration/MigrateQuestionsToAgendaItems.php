<?php
/**
 * Decidiq MigrateQuestionsToAgendaItems.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * questions-as-agenda-items change: copies every `mondelinge-vraag` and
 * `interpellatieverzoek` row onto the generic `agenda-item`, carrying its
 * distinctive fields in `typeFields` and its kind in a per-body
 * `agenda-item-type` row.
 *
 * 🔴 PURELY ADDITIVE. The source rows are never edited and never deleted, and
 * both schemas keep their definitions (`active:false`, `hardDelete:false`), so
 * a rollback still finds its data. This mirrors the supersession
 * generic-body-configuration used for VveConfiguration.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies oral questions and interpellation requests onto generic agenda items.
 *
 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
 */
class MigrateQuestionsToAgendaItems implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The generic target schema slug.
	 *
	 * @var string
	 */
	private const TARGET_SCHEMA = 'agenda-item';

	/**
	 * The schema holding the configurable kinds.
	 *
	 * @var string
	 */
	private const TYPE_SCHEMA = 'agenda-item-type';

	/**
	 * The key under `typeFields` recording which source row an item came from.
	 *
	 * 🔑 THIS IS THE IDEMPOTENCY KEY, so it is written on every migrated item
	 * and never on anything else.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * How the two legacy schemas map onto the generic one.
	 *
	 * `meetingField` differs because the two schemas disagreed about what a
	 * meeting reference is called; `typeName` is the DEFAULT name of the kind
	 * created when a body has none, and is deliberately English because it is a
	 * fallback, not a translation. An organisation renames it, or seeds its own.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const SOURCES = [
		'mondelinge-vraag' => [
			'meetingField' => 'targetMeeting',
			'typeName' => 'Oral question',
			'orderField' => 'sortOrder',
			'carry' => [
				'questionNumber',
				'submitter',
				'politicalGroup',
				'portfolioHolder',
				'lifecycle',
				'rejectionReason',
				'answerSummary',
				'answeredBy',
				'followUpCommitment',
				'followUpWrittenQuestion',
				'sourceWrittenQuestion',
				'questionHourAgendaItem',
			],
		],
		'interpellatieverzoek' => [
			'meetingField' => 'behandeldIn',
			'typeName' => 'Interpellation request',
			'orderField' => null,
			'carry' => [
				'requestNumber',
				'requester',
				'politicalGroup',
				'questions',
				'portfolioHolder',
				'lifecycle',
				'steunbetuigingen',
				'councilResolutionDate',
				'rejectionReason',
				'agendaItem',
				'handlingMinutes',
			],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface     $container       Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface        $logger          Records what was migrated.
	 * @param AgendaItemTypeResolver $types           Resolves the configurable kind each row becomes.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly AgendaItemTypeResolver $types,
	) {
	}//end __construct()

	/**
	 * The logger the shared legacy-row reads report through.
	 *
	 * @return LoggerInterface The logger.
	 *
	 * @spec exclude Trait accessor; exposes an already-injected dependency.
	 */
	protected function migrationLogger(): LoggerInterface {
		return $this->logger;

	}//end migrationLogger()

	/**
	 * Repair-step label.
	 *
	 * @return string The label.
	 *
	 * @spec exclude Trivial repair-step label accessor.
	 */
	public function getName(): string {
		return 'Copy Decidiq oral questions and interpellations onto agenda items';

	}//end getName()

	/**
	 * Run the copy.
	 *
	 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`, so
	 * every failure here is logged and reported, never raised.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->info('OpenRegister unavailable — nothing to migrate.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('Could not resolve OpenRegister ObjectService: ' . $e->getMessage());
			return;
		}

		// 🔴 RUN AS SYSTEM, AND THE WHOLE TRAVERSAL IN ONE SCOPE. A repair step
		// executes during `occ upgrade`, where there is no session, so
		// OpenRegister sees the actor as 'Anonymous' and refuses `create` — and
		// this step reports such a failure as $output->warning(), which does NOT
		// fail an upgrade. Without this line the upgrade says "Update successful"
		// and nothing anyone reads says the migration did not happen. The sibling
		// body-configuration migration carries the same comment for the same
		// measured reason.
		$objectService->runAsSystem(
			function () use ($objectService, $output): void {
				$this->migrateAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Copy every legacy question, inside the caller's system scope.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$migrated = 0;
		$skipped  = 0;

		try {
			$alreadyMigrated = $this->migratedOriginIndex(objectService: $objectService);
		} catch (Throwable $e) {
			$output->warning('Question migration could not read its inputs: ' . $e->getMessage());
			return;
		}

		foreach (self::SOURCES as $sourceSchema => $mapping) {
			foreach ($this->readSources(objectService: $objectService, schema: $sourceSchema) as $index => $source) {
				$origin = trim((string)($source['id'] ?? $source['uuid'] ?? ''));

				// 🔴 AN UNIDENTIFIABLE SOURCE IS SKIPPED, NOT MIGRATED. Without a
				// stable origin there is no idempotency key, so a second run would
				// copy the row again.
				if ($origin === '' || isset($alreadyMigrated[$origin]) === true) {
					$skipped++;
					continue;
				}

				$typeId = $this->types->resolve(
					objectService: $objectService,
					name: $mapping['typeName'],
					bodyReference: (string)($source['governanceBody'] ?? '')
				);
				if ($typeId === '') {
					$skipped++;
					continue;
				}

				try {
					$objectService->setRegister(self::REGISTER);
					$objectService->setSchema(self::TARGET_SCHEMA);
					$objectService->saveObject(
						register: self::REGISTER,
						schema: self::TARGET_SCHEMA,
						object: $this->mapItem(
							source: $source,
							mapping: $mapping,
							typeId: $typeId,
							origin: $origin,
							fallbackOrder: ($index + 1),
							// Resolved HERE, where the service is in scope: the
							// target property validates as a uuid and a seeded
							// row holds a slug.
							meeting: $this->resolveReference(
								objectService: $objectService,
								schema: 'meeting',
								reference: (string)($source[$mapping['meetingField']] ?? '')
							)
						),
					);
					$alreadyMigrated[$origin] = true;
					$migrated++;
				} catch (Throwable $e) {
					$skipped++;
					$output->warning('Failed to migrate a question: ' . $e->getMessage());
					$this->logger->warning(
						'Decidiq: agenda-item migration failed for one row',
						['error' => $e->getMessage(), 'source' => $sourceSchema, 'origin' => $origin]
					);
				}//end try
			}//end foreach
		}//end foreach

		$this->applyConfigurations(objectService: $objectService, output: $output);

		$output->info(
			'Decidiq question migration complete: ' . $migrated . ' migrated, ' . $skipped . ' skipped.'
		);

	}//end migrateAll()

	/**
	 * Source rows already copied, keyed by their origin identifier.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Keyed by source object identifier.
	 */
	private function migratedOriginIndex(object $objectService): array {
		$index = [];

		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema(self::TARGET_SCHEMA);
			$existing = $objectService->findAll(['limit' => 10000]);
		} catch (Throwable $e) {
			$this->logger->info('Decidiq: no agenda items yet', ['error' => $e->getMessage()]);
			return $index;
		}

		foreach ($existing as $entity) {
			$object = $this->toArray(entity: $entity);
			if ($object === null) {
				continue;
			}

			$fields = ($object['typeFields'] ?? null);
			if (is_array($fields) === false) {
				continue;
			}

			$origin = trim((string)($fields[self::ORIGIN_KEY] ?? ''));
			if ($origin !== '') {
				$index[$origin] = true;
			}
		}

		return $index;

	}//end migratedOriginIndex()

	/**
	 * Every row of one legacy schema, as arrays.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The legacy schema slug.
	 *
	 * @return array<int, array<string,mixed>> The source rows.
	 */
	private function readSources(object $objectService, string $schema): array {
		return $this->readRows(objectService: $objectService, schema: $schema, limit: 10000);

	}//end readSources()

	/**
	 * Map one legacy row onto a generic agenda item.
	 *
	 * `title` and `orderNumber` are required by the schema, so both always get a
	 * value: an untitled question keeps its number, and a row with no order takes
	 * its position in the traversal rather than failing the save.
	 *
	 * @param array<string,mixed> $source        The legacy row.
	 * @param array<string,mixed> $mapping       The source's mapping entry.
	 * @param string              $typeId        The resolved agenda-item type.
	 * @param string              $origin        The source object identifier.
	 * @param int                 $fallbackOrder The order to use when the row names none.
	 * @param string              $meeting       The already-resolved meeting identifier, or ''.
	 *
	 * @return array<string,mixed> The generic payload.
	 */
	private function mapItem(
		array $source,
		array $mapping,
		string $typeId,
		string $origin,
		int $fallbackOrder,
		string $meeting,
	): array {
		$subject = trim((string)($source['subject'] ?? ''));
		if ($subject === '') {
			$subject = trim((string)($source['questionNumber'] ?? $source['requestNumber'] ?? ''));
		}

		// `title` is required by the schema, so a row that named neither a
		// subject nor a number still has to save rather than be dropped.
		if ($subject === '') {
			$subject = 'Untitled';
		}

		$payload = [
			'title' => $subject,
			// Every question is put to the body and discussed; the coarse enum
			// carries no more meaning than that, and the configurable type
			// carries the rest.
			'itemType' => 'discussion',
			'orderNumber' => $fallbackOrder,
			'type' => $typeId,
		];

		$orderField = ($mapping['orderField'] ?? null);
		if (is_string($orderField) === true && is_numeric($source[$orderField] ?? null) === true) {
			$payload['orderNumber'] = (int)$source[$orderField];
		}

		$rationale = trim((string)($source['rationale'] ?? ''));
		if ($rationale !== '') {
			$payload['description'] = $rationale;
		}

		if ($meeting !== '') {
			$payload['meeting'] = $meeting;
		}

		$payload['typeFields'] = $this->typeFieldsFor(
			source: $source,
			carry: (array)$mapping['carry'],
			origin: $origin
		);

		return $payload;

	}//end mapItem()

	/**
	 * The values a migrated item carries under `typeFields`.
	 *
	 * The origin marker goes in first and unconditionally: it is the migration's
	 * idempotency key, so an item that carried none would be copied again on the
	 * next run.
	 *
	 * @param array<string,mixed> $source The legacy row.
	 * @param array<int,string>   $carry  The keys this kind carries across.
	 * @param string              $origin The source object identifier.
	 *
	 * @return array<string,mixed> The type-specific values.
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	private function typeFieldsFor(array $source, array $carry, string $origin): array {
		$fields = [self::ORIGIN_KEY => $origin];

		foreach ($carry as $key) {
			$value = ($source[$key] ?? null);
			// A NULL IS NOT AN ABSENT VALUE TO THE VALIDATOR, and an empty string
			// records nothing worth keeping.
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$fields[$key] = $value;
		}

		return $fields;

	}//end typeFieldsFor()

	/**
	 * Fold every legacy question-hour configuration onto its body's types.
	 *
	 * The three facts the schema held were already declared on AgendaItemType,
	 * whose own property notes name this schema as their source. The submission
	 * window applies to both kinds; the support threshold only to the kind that
	 * needs support before it is admitted.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-the-question-hour-configuration-moves-onto-the-type
	 */
	private function applyConfigurations(object $objectService, IOutput $output): void {
		$applied = 0;

		foreach ($this->readSources(objectService: $objectService, schema: 'vragenuur-configuratie') as $config) {
			$body = $this->resolveBody(
				objectService: $objectService,
				reference: (string)($config['governanceBody'] ?? '')
			);

			foreach (self::SOURCES as $mapping) {
				$typeId = $this->types->find(
					objectService: $objectService,
					name: $mapping['typeName'],
					body: $body
				);
				if ($typeId === '') {
					continue;
				}

				$patch = $this->configurationPatch(
					config: $config,
					withThreshold: ($mapping['typeName'] === self::SOURCES['interpellatieverzoek']['typeName'])
				);
				if ($patch === []) {
					continue;
				}

				try {
					$objectService->setRegister(self::REGISTER);
					$objectService->setSchema(self::TYPE_SCHEMA);
					// 🔴 A PATCH IS STILL VALIDATED AS A WHOLE OBJECT.
					// `agenda-item-type` declares `name` required, and sending
					// the patch alone made OpenRegister refuse the update with
					// "The required property (name) is missing" — reported
					// through $output->warning(), which does not fail an
					// upgrade. The name is the one this run just looked the
					// type up by, so carrying it changes nothing but the
					// completeness of the payload.
					$objectService->saveObject(
						register: self::REGISTER,
						schema: self::TYPE_SCHEMA,
						object: ($patch + ['name' => $mapping['typeName']]),
						uuid: $typeId,
					);
					$applied++;
				} catch (Throwable $e) {
					$output->warning('Failed to apply a question-hour configuration: ' . $e->getMessage());
				}
			}//end foreach
		}//end foreach

		if ($applied > 0) {
			$output->info('Decidiq question-hour configuration applied to ' . $applied . ' agenda-item types.');
		}

	}//end applyConfigurations()

	/**
	 * The type fields one legacy configuration contributes.
	 *
	 * @param array<string,mixed> $config        The legacy configuration row.
	 * @param bool                $withThreshold Whether this kind takes a support threshold.
	 *
	 * @return array<string,mixed> The patch, empty when the row said nothing.
	 */
	private function configurationPatch(array $config, bool $withThreshold): array {
		$patch = [];

		$window = ($config['submissionPeriodHours'] ?? null);
		if (is_numeric($window) === true) {
			$patch['submissionWindowHours'] = (int)$window;
		}

		if ($withThreshold === false) {
			return $patch;
		}

		$thresholdType = trim((string)($config['interpellationSupportThresholdType'] ?? ''));
		if ($thresholdType !== '') {
			$patch['supportThresholdType'] = $thresholdType;
		}

		$thresholdValue = ($config['interpellationSupportThresholdValue'] ?? null);
		if (is_numeric($thresholdValue) === true) {
			$patch['supportThresholdValue'] = (float)$thresholdValue;
		}

		return $patch;

	}//end configurationPatch()
}//end class
