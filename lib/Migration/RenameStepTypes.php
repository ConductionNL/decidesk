<?php
/**
 * Decidiq RenameStepTypes.
 *
 * Rewrites the `swearing-in` onboarding step type to `installation` on stored
 * member onboarding records.
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
 * Moves one step type out of a council's ceremony and into plain words.
 *
 * 🔴 WHY THIS IS NOT IN RenameDutchDecidiqValues, WHICH EXISTS FOR EXACTLY THIS.
 * That step rewrites by DATABASE COLUMN: `DbValueMigrationGateway::columnsOf()`
 * reads `information_schema.columns`, and `plannedRewrites()` only plans a
 * rewrite for a value-map key that IS a column. `stepType` is not a column. It
 * lives inside the `steps` array of an object payload, so adding it to
 * `VALUE_MAP` would have planned nothing, rewritten nothing, and reported
 * success — the silent no-op this app keeps meeting.
 *
 * @spec openspec/changes/plain-words-for-groups-and-the-installation-step/specs/plain-words-for-groups-and-the-installation-step/spec.md
 */
class RenameStepTypes implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The schemas whose steps carry these values.
	 *
	 * @var array<int,string>
	 */
	private const SCHEMAS = ['member-onboarding', 'member-offboarding'];

	/**
	 * Stored step type => the value the schema declares today.
	 *
	 * 🔴 THESE TEN PAIRS USED TO LIVE IN RenameDutchDecidiqValues::VALUE_MAP,
	 * WHERE THEY COULD NEVER HAVE RUN. That step rewrites by DATABASE COLUMN:
	 * `DbValueMigrationGateway::columnsOf()` reads `information_schema.columns`
	 * and `plannedRewrites()` only plans a rewrite for a value-map key that IS a
	 * column. `stepType` is not a column — it lives inside the `steps` array of
	 * an object payload — so the block planned nothing, rewrote nothing, and
	 * reported success. It read as done work that had never been done.
	 *
	 * `beediging` maps straight to `installation` rather than through the
	 * intermediate `swearing-in`: an instance that still holds the Dutch value
	 * never saw the intermediate one, and two hops would only add a state that
	 * no schema accepts.
	 *
	 * @var array<string,string>
	 */
	private const RENAMES = [
		'account-koppeling' => 'account-linking',
		'beediging' => 'installation',
		'exit-bevestiging' => 'exit-confirmation',
		'fractie-toewijzing' => 'body-group-assignment',
		'groepen-intrekken' => 'revoke-groups',
		'groepen-toewijzen' => 'assign-groups',
		'introductiepakket' => 'induction-pack',
		'lidmaatschap-beeindigen' => 'end-membership',
		'nevenfuncties-intake' => 'ancillary-positions-intake',
		'persoonsgegevens-notitie' => 'personal-data-note',
		'political-group-assignment' => 'body-group-assignment',
		'swearing-in' => 'installation',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface $container       Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface    $logger          Records what was rewritten.
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
		return 'Rename the Decidiq swearing-in onboarding step to installation';

	}//end getName()

	/**
	 * Rewrite the value wherever a stored step still carries it.
	 *
	 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/plain-words-for-groups-and-the-installation-step/specs/plain-words-for-groups-and-the-installation-step/spec.md#requirement-stored-steps-follow-the-renamed-value
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
		// the actor as 'Anonymous' and refuses the write, and this step reports
		// that as a warning, which does not fail an upgrade.
		$objectService->runAsSystem(
			function () use ($objectService, $output): void {
				$this->rewriteAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Walk the schema's rows and save the ones that changed.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/plain-words-for-groups-and-the-installation-step/specs/plain-words-for-groups-and-the-installation-step/spec.md#requirement-stored-steps-follow-the-renamed-value
	 */
	private function rewriteAll(object $objectService, IOutput $output): void {
		$rewritten = 0;

		foreach (self::SCHEMAS as $schema) {
			$rewritten += $this->rewriteSchema(
				objectService: $objectService,
				output: $output,
				schema: $schema
			);
		}

		$output->info('Decidiq step-type rename complete: ' . $rewritten . ' record(s).');

	}//end rewriteAll()

	/**
	 * Rewrite every stored step of one schema.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 * @param string  $schema        The schema to walk.
	 *
	 * @return int How many records were rewritten.
	 */
	private function rewriteSchema(object $objectService, IOutput $output, string $schema): int {
		$rewritten = 0;

		foreach ($this->readRows(objectService: $objectService, schema: $schema, limit: 10000) as $row) {
			$identifier = $this->identifierOf(object: $row);
			$steps      = ($row['steps'] ?? null);
			if ($identifier === '' || is_array($steps) === false) {
				continue;
			}

			$changed = false;
			foreach ($steps as $index => $step) {
				if (is_array($step) === false) {
					continue;
				}

				$type = (string)($step['stepType'] ?? '');
				if (isset(self::RENAMES[$type]) === true) {
					$steps[$index]['stepType'] = self::RENAMES[$type];
					$changed                   = true;
				}
			}

			if ($changed === false) {
				continue;
			}

			try {
				$payload          = $row;
				$payload['steps'] = $steps;
				unset($payload['@self']);

				$objectService->setRegister(self::REGISTER);
				$objectService->setSchema($schema);
				$objectService->saveObject(
					register: self::REGISTER,
					schema: $schema,
					object: $payload,
					uuid: $identifier,
				);
				$rewritten++;
			} catch (Throwable $e) {
				$output->warning('Failed to rewrite a step type: ' . $e->getMessage());
				$this->logger->warning(
					'Decidiq: swearing-in step rename failed for one record',
					['error' => $e->getMessage(), 'object' => $identifier]
				);
			}//end try
		}//end foreach

		return $rewritten;

	}//end rewriteSchema()
}//end class
