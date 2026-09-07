<?php

/**
 * Decidiq repair step — backfill labels onto NULL-label decision stages.
 *
 * Cleans up after the parafering-seam label defect: instantiate() wrote '' for
 * steps without labels, OpenRegister stored NULL, and the required `label`
 * property then 400'd the patch recording every advance — a route held over
 * the cross-app seam (dossiq's routes carry no step labels) wedged on its
 * FIRST sign-off, appending an orphan approval-action row per attempt.
 *
 * The backfill itself lives in
 * {@see \OCA\Decidiq\Service\DecisionStageLabelRepair}, which derives every
 * label through the ONE shared mapper instantiate() uses, so the two cannot
 * drift. This step only supplies the repair context. The orphan action rows
 * are deliberately KEPT — they are the audit record of what the signer did;
 * see that class's docblock.
 *
 * Idempotent: a stage with a label is left alone, so a re-run repairs nothing.
 * Registered under <post-migration> only — a fresh install has no stages yet,
 * let alone broken ones.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
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
 * Backfills derived labels onto stages stored with a NULL label. Idempotent.
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class RepairDecisionStageLabels implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * The repair service is resolved LAZILY through the container, never
	 * constructor-injected: its store takes OpenRegister's facade by type, so
	 * injecting it here would make this step — and with it `occ upgrade` —
	 * fatal on an instance without openregister.
	 *
	 * @param SettingsService    $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface $container       Resolves the repair service and OR's ObjectService.
	 * @param LoggerInterface    $logger          Records what was repaired.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step label.
	 *
	 * @return string The label.
	 *
	 * @spec exclude Trivial repair-step label accessor.
	 */
	public function getName(): string {
		return 'Backfill labels onto Decidiq decision stages stored without one';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * FAIL SOFT: a repair step that throws fails the whole `occ upgrade`, so
	 * every failure here is logged and reported, never raised. RUN AS SYSTEM:
	 * a repair step executes with no session, so without the scope
	 * OpenRegister refuses the patches as 'Anonymous' — same measured trap as
	 * the sibling migrations.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->info('OpenRegister unavailable — no stages to repair.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$repairService = $this->container->get('OCA\Decidiq\Service\DecisionStageLabelRepair');
		} catch (Throwable $e) {
			$output->warning('Could not resolve the stage-label repair: ' . $e->getMessage());
			return;
		}

		try {
			// One system scope around the whole traversal. The repair's store
			// resolves the SAME shared ObjectService instance through the
			// container alias, so the scope set here covers its patches.
			$repaired = $objectService->runAsSystem(
				static fn (): int => (int)$repairService->repair()
			);
			$output->info(sprintf('Backfilled labels onto %d decision stage(s).', (int)$repaired));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: decision-stage label backfill skipped',
				['exception' => $e->getMessage()]
			);
			$output->warning('Decision-stage label backfill skipped: ' . $e->getMessage());
		}
	}//end run()
}//end class
