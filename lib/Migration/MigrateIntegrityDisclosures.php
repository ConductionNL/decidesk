<?php
/**
 * Decidiq MigrateIntegrityDisclosures.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * integrity-disclosures-in-plain-words change: copies `nevenfunctie` and
 * `geschenk` rows onto schemas named in plain words, and folds
 * `integriteitsbeleid` onto the body configuration it duplicated.
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
 * Renames two disclosure schemas and folds a third into the body configuration.
 *
 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md
 */
class MigrateIntegrityDisclosures implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The body configuration the integrity policy folds into.
	 *
	 * @var string
	 */
	private const CONFIGURATION = 'body-governance-configuration';

	/**
	 * The key recording which source row a copied record came from.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * The two straight renames, source slug to target slug.
	 *
	 * Every property keeps its name, so there is nothing to map: only the schema
	 * changes, and with it the reference each row's `governanceBody` must resolve
	 * to a uuid for.
	 *
	 * @var array<string,string>
	 */
	private const RENAMES = [
		'nevenfunctie' => 'ancillary-position',
		'geschenk' => 'declared-gift',
	];

	/**
	 * The reference properties that must be resolved before a row is written.
	 *
	 * @var array<string,string>
	 */
	private const REFERENCES = [
		'governanceBody' => 'governance-body',
		'person' => 'person',
		'recipient' => 'person',
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
		return 'Copy Decidiq integrity disclosures onto schemas named in plain words';

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
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-existing-disclosures-are-carried-across
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
				$copied = $this->copyRenamed(objectService: $objectService, output: $output);
				$folded = $this->foldPolicies(objectService: $objectService, output: $output);
				$output->info(
					'Decidiq integrity migration complete: ' . $copied . ' disclosure(s), ' . $folded . ' policy(ies).'
				);
			}
		);

	}//end run()

	/**
	 * Copy every row of the two renamed schemas.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return int How many rows were copied.
	 *
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-existing-disclosures-are-carried-across
	 */
	private function copyRenamed(object $objectService, IOutput $output): int {
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
							payload: $this->mapRow(objectService: $objectService, row: $row, origin: $origin),
						),
					);
					$existing[$origin] = true;
					$copied++;
				} catch (Throwable $e) {
					$output->warning('Failed to migrate an integrity disclosure: ' . $e->getMessage());
					$this->logger->warning(
						'Decidiq: integrity disclosure migration failed for one row',
						['error' => $e->getMessage(), 'schema' => $source, 'origin' => $origin]
					);
				}//end try
			}//end foreach
		}//end foreach

		return $copied;

	}//end copyRenamed()

	/**
	 * Fold every integrity policy onto its body's configuration.
	 *
	 * 🔑 IDENTITY IS THE BODY, exactly as it is for the configuration itself: the
	 * schema declares one configuration per governance body, so a second policy
	 * for a body it already covers is not a second record. An existing
	 * configuration is UPDATED in place rather than replaced, so the constitutive
	 * document and majority overrides another change wrote survive.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return int How many policies were folded.
	 *
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-the-integrity-policy-folds-into-the-body-configuration
	 */
	private function foldPolicies(object $objectService, IOutput $output): int {
		$configurations = $this->configurationsByBody(objectService: $objectService);
		$folded         = 0;

		foreach ($this->readRows(objectService: $objectService, schema: 'integriteitsbeleid', limit: 10000) as $policy) {
			$body = $this->resolveBody(
				objectService: $objectService,
				reference: (string)($policy['governanceBody'] ?? '')
			);
			if ($body === '') {
				continue;
			}

			$patch = $this->policyPatch(policy: $policy);
			if ($patch === []) {
				continue;
			}

			$existing = ($configurations[$body] ?? '');
			try {
				$objectService->setRegister(self::REGISTER);
				$objectService->setSchema(self::CONFIGURATION);
				if ($existing !== '') {
					// 🔴 A PATCH IS STILL VALIDATED AS A WHOLE OBJECT.
					// `body-governance-configuration` declares `governanceBody`
					// required, and sending the patch alone made OpenRegister
					// refuse the update with "The required property
					// (governanceBody) is missing" — reported through
					// $output->warning(), which does not fail an upgrade.
					$objectService->saveObject(
						register: self::REGISTER,
						schema: self::CONFIGURATION,
						object: ($patch + ['governanceBody' => $body]),
						uuid: $existing,
					);
					$folded++;
					continue;
				}

				$saved = $objectService->saveObject(
					register: self::REGISTER,
					schema: self::CONFIGURATION,
					object: ($patch + ['governanceBody' => $body]),
				);
				$configurations[$body] = $this->identifierOf(object: $this->toArray(entity: $saved));
				$folded++;
			} catch (Throwable $e) {
				$output->warning('Failed to fold an integrity policy: ' . $e->getMessage());
			}//end try
		}//end foreach

		return $folded;

	}//end foldPolicies()

	/**
	 * Existing body configurations, keyed by the body they cover.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,string> Body identifier to configuration identifier.
	 */
	private function configurationsByBody(object $objectService): array {
		$index = [];

		foreach ($this->readRows(objectService: $objectService, schema: self::CONFIGURATION, limit: 10000) as $row) {
			$body = trim((string)($row['governanceBody'] ?? ''));
			if ($body !== '') {
				$index[$body] = $this->identifierOf(object: $row);
			}
		}

		return $index;

	}//end configurationsByBody()

	/**
	 * The configuration fields one integrity policy contributes.
	 *
	 * @param array<string,mixed> $policy The legacy policy row.
	 *
	 * @return array<string,mixed> The patch, empty when the row said nothing.
	 */
	private function policyPatch(array $policy): array {
		$patch = [];

		foreach (['ancillaryPositionDisclosureDefault', 'giftThresholdAmount', 'giftsPublic', 'integrityNotificationGroup'] as $key) {
			$value = ($policy[$key] ?? null);
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$patch[$key] = $value;
		}

		return $patch;

	}//end policyPatch()

	/**
	 * Rows already copied, keyed by the source they came from.
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
	 * Copy one row across, resolving its references.
	 *
	 * Nothing is renamed: the properties were already in plain words, only the
	 * schema was not. `id` and `uuid` are dropped so the copy gets its own.
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

			if (isset(self::REFERENCES[$key]) === true && is_string($value) === true) {
				$value = $this->resolveReference(
					objectService: $objectService,
					schema: self::REFERENCES[$key],
					reference: $value
				);
			}

			if ($value === null || $value === '') {
				continue;
			}

			$payload[$key] = $value;
		}

		$payload[self::ORIGIN_KEY] = $origin;

		return $payload;

	}//end mapRow()
}//end class
