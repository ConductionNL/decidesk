<?php
/**
 * Decidiq MigrateMemberOnboarding.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * member-onboarding-in-plain-words change: copies `onboarding-traject` and
 * `offboarding-traject` rows onto schemas named in plain words.
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
 * Renames the joining and leaving pathways into plain words.
 *
 * @spec openspec/changes/member-onboarding-in-plain-words/specs/member-onboarding-in-plain-words/spec.md
 */
class MigrateMemberOnboarding implements IRepairStep {
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
	 * Neither schema references the other, so the order here is only the order
	 * the output reports them in.
	 *
	 * @var array<string,string>
	 */
	private const RENAMES = [
		'onboarding-traject' => 'member-onboarding',
		'offboarding-traject' => 'member-offboarding',
	];

	/**
	 * Reference properties, the schema each points at, and the property the
	 * value is written to.
	 *
	 * 🔑 `swearingInMeeting` IS BOTH A REFERENCE AND A RENAME. It becomes
	 * `installationMeeting`, so it cannot be carried by RENAMED_FIELDS alone:
	 * the value has to be resolved as a reference AND written under the new key.
	 * A value written under the old key lands on a property the target does not
	 * declare, which OpenRegister stores nowhere while answering 200.
	 *
	 * Neither source references the other, so nothing here points at a schema
	 * this same run is retiring, and no copied identifier needs retargeting.
	 *
	 * @var array<string, array{schema: string, target: string}>
	 */
	private const REFERENCES = [
		'person' => ['schema' => 'person', 'target' => 'person'],
		'targetBody' => ['schema' => 'governance-body', 'target' => 'targetBody'],
		'body' => ['schema' => 'governance-body', 'target' => 'body'],
		'membership' => ['schema' => 'membership', 'target' => 'membership'],
		'swearingInMeeting' => ['schema' => 'meeting', 'target' => 'installationMeeting'],
	];

	/**
	 * Plain properties renamed with their schema, source key to target key.
	 *
	 * `beëdigingsType` was the last Dutch-spelled property in the app, and the
	 * two swearing-in names describe one country's ceremony for something every
	 * organisation does.
	 *
	 * @var array<string,string>
	 */
	/**
	 * Step-type values renamed with the schema, old value to new.
	 *
	 * 🔴 A CARRIED ENUM IS NOT A CARRIED STRING. The `stepType` vocabulary moved
	 * `swearing-in` to `installation` with the rest of the ceremony wording, so
	 * a step copied verbatim carries a value the target no longer offers and
	 * OpenRegister refuses the WHOLE record: "Property 'steps.0.stepType'
	 * should be one of ... but is 'swearing-in'". Measured on a live
	 * `occ upgrade`: three records, all refused, reported through
	 * `$output->warning()` which does not fail the upgrade.
	 *
	 * RenameSwearingInStepType covers rows that are ALREADY on the new schema.
	 * It cannot help here, because this copy is what puts them there.
	 *
	 * @var array<string,string>
	 */
	private const RENAMED_STEP_TYPES = [
		'swearing-in' => 'installation',
	];

	private const RENAMED_FIELDS = [
		'beëdigingsType' => 'installationType',
		'swearingInDate' => 'installedOn',
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
		return 'Copy Decidiq member joining and leaving records onto schemas named in plain words';

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
	 * @spec openspec/changes/member-onboarding-in-plain-words/specs/member-onboarding-in-plain-words/spec.md#requirement-existing-records-are-carried-across
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
	 * Copy each schema across.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/member-onboarding-in-plain-words/specs/member-onboarding-in-plain-words/spec.md#requirement-existing-records-are-carried-across
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$copied = 0;

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
					$objectService->saveObject(
						register: self::REGISTER,
						schema: $target,
						object: $this->coerceToTarget(
							objectService: $objectService,
							properties: $this->declaredProperties(slug: $target),
							alreadyResolved: array_column(self::REFERENCES, 'target'),
							payload: $this->mapRow(
								objectService: $objectService,
								row: $row,
								origin: $origin
							),
						),
					);
					$existing[$origin] = true;
					$copied++;
				} catch (Throwable $e) {
					$output->warning('Failed to migrate a record: ' . $e->getMessage());
					$this->logger->warning(
						'Decidiq: member onboarding rename migration failed for one row',
						['error' => $e->getMessage(), 'schema' => $source, 'origin' => $origin]
					);
				}//end try
			}//end foreach
		}//end foreach

		$output->info('Decidiq member onboarding rename complete: ' . $copied . ' record(s).');

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
	 * @param object              $objectService The OR ObjectService.
	 * @param array<string,mixed> $row           The legacy row.
	 * @param string              $origin        The source identifier.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function mapRow(object $objectService, array $row, string $origin): array {
		$payload = [];

		foreach ($row as $key => $value) {
			if (in_array($key, ['id', 'uuid', '@self'], true) === true) {
				continue;
			}

			if ($this->isReference(key: $key, value: $value) === true) {
				$reference                     = self::REFERENCES[$key];
				$payload[$reference['target']] = $this->resolveReference(
					objectService: $objectService,
					schema: $reference['schema'],
					reference: (string)$value
				);
				continue;
			}

			if ($value === null || $value === '') {
				continue;
			}

			$payload[(self::RENAMED_FIELDS[$key] ?? $key)] = $value;
		}

		$payload[self::ORIGIN_KEY] = $origin;

		if (isset($payload['steps']) === true && is_array($payload['steps']) === true) {
			$payload['steps'] = $this->renameStepTypes(steps: $payload['steps']);
		}

		return $payload;

	}//end mapRow()

	/**
	 * Whether one source key holds a reference this migration resolves itself.
	 *
	 * @param string $key   The source property name.
	 * @param mixed  $value The value as the source holds it.
	 *
	 * @return bool True when it is a non-empty reference string.
	 */
	private function isReference(string $key, mixed $value): bool {
		return (isset(self::REFERENCES[$key]) === true
			&& is_string($value) === true
			&& $value !== '');

	}//end isReference()

	/**
	 * Move each step onto the vocabulary the target schema declares.
	 *
	 * @param array<int,mixed> $steps The steps as the source holds them.
	 *
	 * @return array<int,mixed> The steps, with renamed types.
	 */
	private function renameStepTypes(array $steps): array {
		foreach ($steps as $index => $step) {
			if (is_array($step) === false) {
				continue;
			}

			$type = (string)($step['stepType'] ?? '');
			if (isset(self::RENAMED_STEP_TYPES[$type]) === true) {
				$steps[$index]['stepType'] = self::RENAMED_STEP_TYPES[$type];
			}
		}

		return $steps;

	}//end renameStepTypes()
}//end class
