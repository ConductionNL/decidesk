<?php
/**
 * Decidiq MigrateKascommissieToAuditStatement.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * generic-audit-statement change: copies every `vve-configuration` row into
 * the generic `body-governance-configuration` schema.
 *
 * 🔴 PURELY ADDITIVE. The source rows are never edited and never deleted, and
 * `KascommissieVerklaring` keeps its definition (`active:false`, `hardDelete:false`),
 * so a rollback still finds its data. This mirrors the supersession
 * `unified-decision-templates` used for ProcessTemplate and VveDecisionTemplate.
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
 * Copies KascommissieVerklaring rows onto the generic AuditStatement.
 *
 * @spec openspec/changes/generic-audit-statement/specs/generic-audit-statement/spec.md
 */
class MigrateKascommissieToAuditStatement implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The legacy schema slug.
	 *
	 * @var string
	 */
	private const SOURCE_SCHEMA = 'kascommissie-verklaring';

	/**
	 * The generic target schema slug.
	 *
	 * @var string
	 */
	private const TARGET_SCHEMA = 'audit-statement';

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
		return 'Copy Decidiq kascommissie verklaringen onto the generic audit statement';

	}//end getName()

	/**
	 * Run the copy.
	 *
	 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`, so
	 * every failure here is logged and reported, never raised. That is also why
	 * this migration must be verifiable WITHOUT running it — see the
	 * setSchema-slug guard in RegisterJsonTest, which exists because exactly this
	 * shape hid a slug typo for the life of another repair step.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generic-audit-statement/specs/generic-audit-statement/spec.md#requirement-existing-statements-are-carried-across
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

		// 🔴 RUN AS SYSTEM, AND THE WHOLE TRAVERSAL IN ONE SCOPE.
		//
		// A repair step executes during `occ upgrade`, where there is no session,
		// so OpenRegister sees the actor as 'Anonymous' and refuses `create`.
		// Measured on a live instance before this line existed: both source rows
		// failed with "User 'Anonymous' does not have permission to 'create'
		// objects in schema 'AuditStatement'", and the step reported
		// each one as $output->warning() — which does not fail an upgrade. So the
		// upgrade would say "Update successful", the summary would say
		// "0 migrated, 2 skipped", and nothing anyone reads would say the
		// migration had not happened. The sibling template migration carries the
		// same comment for the same reason.
		//
		// One scope around the whole traversal, not per save: a per-save wrapper
		// re-enters for every row and leaves the index build outside it.
		$objectService->runAsSystem(
			function () use ($objectService, $output): void {
				$this->migrateAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Copy every legacy configuration, inside the caller's system scope.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generic-audit-statement/specs/generic-audit-statement/spec.md#requirement-existing-statements-are-carried-across
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$migrated = 0;
		$skipped  = 0;

		try {
			$existingBodies = $this->existingBodyIndex(objectService: $objectService);
			$sources        = $this->readSources(objectService: $objectService);
		} catch (Throwable $e) {
			$output->warning('KascommissieVerklaring migration could not read its inputs: ' . $e->getMessage());
			return;
		}

		foreach ($sources as $source) {
			// 🔴 RESOLVE BEFORE COMPARING, OR IDEMPOTENCY IS A LIE.
			//
			// A legacy row holds the body as a SLUG; the row this migration
			// writes holds the resolved UUID. Comparing the source's slug against
			// an index of UUIDs never matches, so a second run created a THIRD
			// configuration for a body that already had one — measured on a live
			// instance, and the exact defect this idempotency check exists to
			// prevent. Both sides must speak the same identifier.
			$bodyRef = $this->resolveBody(
				objectService: $objectService,
				reference: (string)($source['governanceBody'] ?? '')
			);
			if ($bodyRef === '') {
				$skipped++;
				continue;
			}

			// IDENTITY IS (BODY, FINANCIAL YEAR), NOT THE SOURCE UUID. A body
			// files one statement per year, so that pair is what makes two rows
			// the same record; keying on the source uuid would let a re-seeded
			// source produce a second statement for a year that already has one.
			// Same defect class as the duplicated decision templates, and the
			// same resolve-before-compare rule as the sibling body-configuration
			// migration: the body is resolved to a uuid FIRST, because the legacy
			// rows hold a slug while the rows this writes hold a uuid.
			$year = trim((string)($source['financialYear'] ?? ''));
			if ($year === '') {
				$skipped++;
				continue;
			}

			if (($existingBodies[$bodyRef . '|' . $year] ?? false) === true) {
				$skipped++;
				continue;
			}

			try {
				$objectService->setRegister(self::REGISTER);
				$objectService->setSchema(self::TARGET_SCHEMA);
				$objectService->saveObject(
					register: self::REGISTER,
					schema: self::TARGET_SCHEMA,
					object: $this->mapStatement(body: $bodyRef, source: $source),
				);
				$existingBodies[$bodyRef . '|' . $year] = true;
				$migrated++;
			} catch (Throwable $e) {
				$skipped++;
				$output->warning('Failed to migrate a kascommissie verklaring: ' . $e->getMessage());
				$this->logger->warning(
					'Decidiq: AuditStatement migration failed for one row',
					['error' => $e->getMessage(), 'governanceBody' => $bodyRef]
				);
			}//end try
		}//end foreach

		$output->info(
			'Decidiq audit-statement migration complete: ' . $migrated . ' migrated, ' . $skipped . ' skipped.'
		);

	}//end migrateAll()

	/**
	 * Governance bodies that already have a generic configuration.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Keyed '<governanceBody>|<financialYear>'.
	 */
	private function existingBodyIndex(object $objectService): array {
		$index = [];

		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema(self::TARGET_SCHEMA);
			$existing = $objectService->findAll(['limit' => 1000]);
		} catch (Throwable $e) {
			// The target schema has not been imported yet: nothing exists, so
			// everything below is a first migration.
			$this->logger->info(
				'Decidiq: no body-governance-configuration objects yet',
				['error' => $e->getMessage()]
			);
			return $index;
		}

		foreach ($existing as $entity) {
			$object = $this->toArray(entity: $entity);
			if ($object === null) {
				continue;
			}

			$body = trim((string)($object['governanceBody'] ?? ''));
			$year = trim((string)($object['financialYear'] ?? ''));
			if ($body !== '' && $year !== '') {
				$index[$body . '|' . $year] = true;
			}
		}

		return $index;

	}//end existingBodyIndex()

	/**
	 * Every legacy `vve-configuration` row, as arrays.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<int, array<string,mixed>> The source rows.
	 */
	private function readSources(object $objectService): array {
		return $this->readRows(objectService: $objectService, schema: self::SOURCE_SCHEMA, limit: 10000);

	}//end readSources()

	/**
	 * Map one legacy row onto the generic shape.
	 *
	 * The record was already generic: a financial year, a verdict, a body and
	 * two optional annotations. Only the schema it lives on changes.
	 *
	 * @param string              $body   The already-resolved governance-body identifier.
	 * @param array<string,mixed> $source The legacy row.
	 *
	 * @return array<string,mixed> The generic payload.
	 */
	private function mapStatement(string $body, array $source): array {
		$payload = [
			'governanceBody' => $body,
			'financialYear'  => (int)($source['financialYear'] ?? 0),
			'verdict'        => (string)($source['verdict'] ?? ''),
		];

		foreach (['notes', 'agendaItem'] as $optional) {
			$value = trim((string)($source[$optional] ?? ''));
			if ($value !== '') {
				$payload[$optional] = $value;
			}
		}

		return $payload;

	}//end mapStatement()
}//end class
