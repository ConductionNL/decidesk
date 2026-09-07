<?php
/**
 * Decidiq MigrateTheLastTwoDutchNames.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * the-last-two-dutch-names change: copies `termijnagenda-item` and
 * `bevoegdheidstoedeling` rows onto schemas named in plain words.
 *
 * 🔴 PURELY ADDITIVE. Source rows are never edited or deleted, and every source
 * schema keeps its definition (`active:false`, `hardDelete:false`).
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
 * Renames the forward agenda and the authority delegation into plain words.
 *
 * @spec openspec/changes/the-last-two-dutch-names/specs/the-last-two-dutch-names/spec.md
 */
class MigrateTheLastTwoDutchNames implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The key recording which source row a copied record came from.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * The two renames.
	 *
	 * An authority delegation may name a PARENT delegation, so the copies of
	 * both sides can appear in one pass: `parentAllocation` is retargeted at
	 * whatever was copied earlier in the same run, and rows are read in the order
	 * OpenRegister returns them, so a child seen before its parent keeps the
	 * resolved legacy identifier rather than a wrong one.
	 *
	 * @var array<string,string>
	 */
	private const RENAMES = [
		'termijnagenda-item' => 'planned-agenda-item',
		'bevoegdheidstoedeling' => 'authority-delegation',
	];

	/**
	 * Reference properties, the LEGACY schema each points at, and the property
	 * the value is written to.
	 *
	 * 🔑 FOUR OF THESE ARE RENAMES. `delegans`, `delegansDescription`,
	 * `delegatarisBody` and `beperkingen` were the only properties in either
	 * schema still written in Dutch, so they move with their schema. A value
	 * written under the old key would land on a property the target does not
	 * declare, which OpenRegister stores nowhere while answering 200.
	 *
	 * @var array<string, array{schema: string, target: string}>
	 */
	private const REFERENCES = [
		'governanceBody' => ['schema' => 'governance-body', 'target' => 'governanceBody'],
		'delegans' => ['schema' => 'governance-body', 'target' => 'delegatingBody'],
		'delegatarisBody' => ['schema' => 'governance-body', 'target' => 'delegateBody'],
		'parentAllocation' => ['schema' => 'bevoegdheidstoedeling', 'target' => 'parentAllocation'],
	];

	/**
	 * Plain properties renamed with their schema, source key to target key.
	 *
	 * @var array<string,string>
	 */
	private const RENAMED_FIELDS = [
		'delegansDescription' => 'delegatingDescription',
		'beperkingen' => 'restrictions',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface $container       Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface    $logger          Records what was migrated.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
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
		return 'Copy Decidiq forward-agenda and delegation records onto schemas named in plain words';

	}//end getName()

	/**
	 * Run the copy.
	 *
	 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-last-two-dutch-names/specs/the-last-two-dutch-names/spec.md#requirement-existing-records-are-carried-across
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

		// 🔴 RUN AS SYSTEM. A repair step has no session, so OpenRegister sees
		// the actor as 'Anonymous' and refuses `create`, and this step reports
		// that as a warning, which does not fail an upgrade.
		$objectService->runAsSystem(
			function () use ($objectService, $output): void {
				$this->migrateAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Copy each schema in dependency order.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-last-two-dutch-names/specs/the-last-two-dutch-names/spec.md#requirement-existing-records-are-carried-across
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		// Source identifier to NEW identifier, across every schema copied so far,
		// so a step can find the cycle its parent became.
		$copiedIds = [];
		$copied    = 0;

		foreach (self::RENAMES as $source => $target) {
			$existing = $this->originIndex(objectService: $objectService, schema: $target);

			foreach ($this->readRows(objectService: $objectService, schema: $source, limit: 10000) as $row) {
				$origin = $this->identifierOf(object: $row);
				if ($origin === '' || isset($existing[$origin]) === true) {
					continue;
				}

				try {
					$objectService->setRegister(self::REGISTER);
					$objectService->setSchema($target);
					$saved = $objectService->saveObject(
						register: self::REGISTER,
						schema: $target,
						object: $this->coerceToTarget(
							objectService: $objectService,
							properties: $this->declaredProperties(slug: $target),
							alreadyResolved: array_column(self::REFERENCES, 'target'),
							payload: $this->mapRow(
								objectService: $objectService,
								row: $row,
								origin: $origin,
								copiedIds: $copiedIds
							),
						),
					);
					$existing[$origin]  = true;
					$copiedIds[$origin] = $this->identifierOf(object: $this->toArray(entity: $saved));
					$copied++;
				} catch (Throwable $e) {
					$output->warning('Failed to migrate a record: ' . $e->getMessage());
					$this->logger->warning(
						'Decidiq: final rename migration failed for one row',
						['error' => $e->getMessage(), 'schema' => $source, 'origin' => $origin]
					);
				}//end try
			}//end foreach
		}//end foreach

		$output->info('Decidiq final rename complete: ' . $copied . ' record(s).');

	}//end migrateAll()

	/**
	 * Records already copied, keyed by the source they came from.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The target schema.
	 *
	 * @return array<string,bool> Keyed by source identifier.
	 */
	private function originIndex(object $objectService, string $schema): array {
		$index = [];

		foreach ($this->readRows(objectService: $objectService, schema: $schema, limit: 10000) as $row) {
			$origin = trim((string)($row[self::ORIGIN_KEY] ?? ''));
			if ($origin !== '') {
				$index[$origin] = true;
			}
		}

		return $index;

	}//end originIndex()

	/**
	 * Copy one row across, resolving and retargeting its references.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param array<string,mixed>  $row           The legacy row.
	 * @param string               $origin        The source identifier.
	 * @param array<string,string> $copiedIds     Source identifier to new identifier.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function mapRow(object $objectService, array $row, string $origin, array $copiedIds): array {
		$payload = [];

		foreach ($row as $key => $value) {
			if (in_array($key, ['id', 'uuid', '@self'], true) === true) {
				continue;
			}

			if (isset(self::REFERENCES[$key]) === true && is_string($value) === true && $value !== '') {
				$reference = self::REFERENCES[$key];
				$payload[$reference['target']] = $this->retarget(
					objectService: $objectService,
					schema: $reference['schema'],
					value: $value,
					copiedIds: $copiedIds
				);
				continue;
			}

			if ($value === null || $value === '') {
				continue;
			}

			$payload[(self::RENAMED_FIELDS[$key] ?? $key)] = $value;
		}

		$payload[self::ORIGIN_KEY] = $origin;

		return $payload;

	}//end mapRow()

	/**
	 * The identifier a copied row should point at.
	 *
	 * 🔴 A REFERENCE TO A RETIRED ROW MUST FOLLOW IT. A step copied across still
	 * held its old cycle's identifier, so it would have pointed at the RETIRED
	 * record while living on the new schema: readable, plausible, and joined to
	 * the wrong side of the migration. Where the target was copied in this run,
	 * the reference follows it; otherwise it is resolved as an ordinary slug.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param string               $schema        The legacy schema the value points at.
	 * @param string               $value         The reference as the source holds it.
	 * @param array<string,string> $copiedIds     Source identifier to new identifier.
	 *
	 * @return string The identifier to write.
	 */
	private function retarget(object $objectService, string $schema, string $value, array $copiedIds): string {
		$resolved = $this->resolveReference(
			objectService: $objectService,
			schema: $schema,
			reference: $value
		);

		return (string)($copiedIds[$resolved] ?? ($copiedIds[$value] ?? $resolved));

	}//end retarget()
}//end class
