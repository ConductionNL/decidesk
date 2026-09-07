<?php

/**
 * Decidiq Decision Type Registry
 *
 * The single runtime authority for the decisionType vocabulary. The list of
 * valid decision types is configuration, not code: it lives in the
 * `decision_types` app-config value, seeded on install with the shipped
 * vocabulary. An administrator extends it with one occ command, no release.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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

namespace OCA\Decidiq\Service;

use OCA\Decidiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and validates the configured decisionType vocabulary.
 *
 * Before this class the vocabulary was a closed list mirrored in FOUR homes:
 * a service constant (`DecisionIntegrationService::ALLOWED_TYPES`), the
 * Decision enum in `decidesk_register.json`, its copy in
 * `decidiq_mock_register.json`, and the DecisionTemplate narrowing in
 * `register.d/68-unified-decision-templates.json`. Adding one type cost a
 * release touching all four, which is how dossiq's `advice` stalled and the
 * pending `woo-decision` need stalled after it. Now there is exactly ONE
 * authority: the stored `decision_types` app-config value. The schema
 * declarations carry no enum; validation is referential, against this
 * registry, at the write path.
 *
 * `DEFAULT_TYPES` is not a second authority. It is the shipped seed
 * (written into app config by {@see \OCA\Decidiq\Repair\SeedDecisionTypes})
 * and the fallback for the window before that seed has run, so a fresh
 * install never fails closed on the whole vocabulary.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
class DecisionTypeRegistry {

	/**
	 * App-config key holding the decisionType vocabulary as a JSON array.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'decision_types';

	/**
	 * The shipped decision-type seed.
	 *
	 * This is the bootstrap vocabulary, not a validation home: it is written
	 * into app config once by the SeedDecisionTypes repair step and consulted
	 * at runtime only while that row is still absent. It carries every type a
	 * fleet caller sends today: dossiq raises `contract-renewal`,
	 * `report-adoption`, `advice`, `bezwaar-decision` and `woo-decision`;
	 * stackiq raises `contract` and `contract-renewal`. The parity test
	 * (DecisionIntegrationServiceTest) pins that coverage.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_TYPES = [
		'motion',
		'amendment',
		'resolution',
		'contract',
		'contract-renewal',
		'report-adoption',
		'appointment',
		'management-point',
		'policy',
		'meeting-outcome',
		'advice',
		'bezwaar-decision',
		'woo-decision',
	];

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
	 * Return the configured decisionType vocabulary.
	 *
	 * Reads the stored `decision_types` app-config array, keeping only
	 * non-empty strings. Falls back to the shipped seed when the row is
	 * absent or holds nothing usable, so validation never fails closed on
	 * the entire vocabulary because a seed has not run yet.
	 *
	 * @return list<string> The valid decisionType values
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function getTypes(): array {
		$stored = $this->appConfig->getValueArray(Application::APP_ID, self::CONFIG_KEY, []);

		$types = [];
		foreach ($stored as $type) {
			if (is_string($type) === true && trim($type) !== '') {
				$types[] = trim($type);
			}
		}

		$types = array_values(array_unique($types));
		if (count($types) === 0) {
			return self::DEFAULT_TYPES;
		}

		return $types;
	}//end getTypes()

	/**
	 * Whether a decisionType is part of the configured vocabulary.
	 *
	 * @param string $decisionType The decisionType value to check
	 *
	 * @return bool True when the type is configured
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function isAllowed(string $decisionType): bool {
		return in_array($decisionType, $this->getTypes(), true);
	}//end isAllowed()
}//end class
