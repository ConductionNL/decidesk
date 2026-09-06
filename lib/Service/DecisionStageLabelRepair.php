<?php

/**
 * Decidiq decision-stage label repair.
 *
 * Backfills a derived label onto every stage stored without one — the cleanup
 * half of the parafering-seam label defect. instantiate() used to write ''
 * for route steps without labels, OpenRegister stored NULL, and the required
 * `label` property then 400'd the patch recording every advance: a cross-app
 * route (dossiq's routes carry no step labels) wedged on its FIRST sign-off,
 * appending an orphan approval-action row per attempt.
 *
 * NOT part of the route engine: this writes no route STATE — no status, no
 * outcome, no advancement — so REQ-ARE-004's one-engine rule is not in play.
 * The thing that must not drift is the label derivation, and that stays in
 * the one shared {@see ApprovalRouteStepMapper::labelOf()} the engine's
 * instantiate() uses too.
 *
 * THE ORPHAN ACTION ROWS ARE KEPT. The same defect appended approval-action
 * rows whose stage write was refused; they record what the signer actually
 * did, and the trail is the audit record — a repair that deleted sign-off
 * rows would be a repair that edits history. The signer's successful retry
 * appends its own row; a duplicate verb in the trail is honest, a missing
 * one is not.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Backfills derived labels onto stages stored with a NULL label. Idempotent.
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class DecisionStageLabelRepair {

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and patches the stage rows.
	 * @param ApprovalRouteStepMapper $mapper The ONE label derivation, shared with instantiate().
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ApprovalRouteStepMapper $mapper,
	) {
	}//end __construct()

	/**
	 * Backfill a derived label onto every stage stored without one.
	 *
	 * Idempotent: a stage with a label is left alone, so a re-run repairs
	 * nothing.
	 *
	 * @return int How many stages were repaired.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function repair(): int {
		$repaired = 0;
		foreach ($this->store->findAll(schema: 'decision-stage', filters: []) as $stage) {
			if (trim((string)($stage['label'] ?? '')) !== '') {
				continue;
			}

			$this->store->patch(
				schema: 'decision-stage',
				data: [
					'label' => $this->mapper->labelOf(
						step: ['stageType' => (string)($stage['stageType'] ?? '')],
						sequence: (int)($stage['sequence'] ?? 0),
					),
				],
				uuid: (string)$stage['id'],
			);
			$repaired++;
		}

		return $repaired;
	}//end repair()
}//end class
