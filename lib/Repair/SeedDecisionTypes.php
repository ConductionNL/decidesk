<?php

/**
 * Decidiq Seed Decision Types Repair Step
 *
 * Writes the shipped decisionType vocabulary into the `decision_types`
 * app-config value, once. After this runs the vocabulary is data an
 * administrator edits, never code.
 *
 * @category Repair
 * @package  OCA\Decidiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Repair;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\DecisionTypeRegistry;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Seeds the decision-type vocabulary into app config on install and upgrade.
 *
 * Idempotent and non-destructive: a stored vocabulary is NEVER overwritten,
 * whatever it holds. An administrator who removed a type on purpose keeps
 * that removal across every later upgrade; a later release that grows
 * DecisionTypeRegistry::DEFAULT_TYPES only reaches fresh installs. That
 * asymmetry is the point of the change: after the first seed the store is
 * the only authority, and code stops deciding the vocabulary.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
class SeedDecisionTypes implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration store
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function getName(): string {
		return 'Seed the decidiq decision-type vocabulary into app config';
	}//end getName()

	/**
	 * Seed the vocabulary when, and only when, none is stored yet.
	 *
	 * @param IOutput $output Progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function run(IOutput $output): void {
		$existing = $this->appConfig->getValueArray(
			Application::APP_ID,
			DecisionTypeRegistry::CONFIG_KEY,
			[]
		);

		if (count($existing) > 0) {
			$output->info(
				'Decision types already configured (' . count($existing) . ' entries), leaving them untouched.'
			);
			return;
		}

		$this->appConfig->setValueArray(
			Application::APP_ID,
			DecisionTypeRegistry::CONFIG_KEY,
			DecisionTypeRegistry::DEFAULT_TYPES
		);

		$output->info(
			'Seeded ' . count(DecisionTypeRegistry::DEFAULT_TYPES) . ' decision types into the decision_types app setting.'
		);
	}//end run()
}//end class
