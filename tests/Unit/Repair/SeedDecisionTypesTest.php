<?php

/**
 * Unit tests for the SeedDecisionTypes repair step.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Repair\SeedDecisionTypes;
use OCA\Decidiq\Service\DecisionTypeRegistry;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The seed writes the shipped vocabulary once and then never touches the
 * stored row again, so admin edits survive every upgrade.
 *
 * @covers \OCA\Decidiq\Repair\SeedDecisionTypes
 * @uses \OCA\Decidiq\Service\DecisionTypeRegistry
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
class SeedDecisionTypesTest extends TestCase {

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Step under test.
	 *
	 * @var SeedDecisionTypes
	 */
	private SeedDecisionTypes $step;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->step = new SeedDecisionTypes(appConfig: $this->appConfig);
	}//end setUp()

	/**
	 * An absent vocabulary row is seeded with the shipped list.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testRunSeedsWhenNoVocabularyStored(): void {
		$this->appConfig->method('getValueArray')
			->with(Application::APP_ID, DecisionTypeRegistry::CONFIG_KEY, [])
			->willReturn([]);

		$this->appConfig->expects(self::once())
			->method('setValueArray')
			->with(
				Application::APP_ID,
				DecisionTypeRegistry::CONFIG_KEY,
				DecisionTypeRegistry::DEFAULT_TYPES
			);

		$this->step->run($this->createMock(IOutput::class));

	}//end testRunSeedsWhenNoVocabularyStored()

	/**
	 * A stored vocabulary is never overwritten, however it differs from the
	 * shipped seed. After the first seed the store is the only authority.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testRunNeverOverwritesStoredVocabulary(): void {
		$this->appConfig->method('getValueArray')
			->with(Application::APP_ID, DecisionTypeRegistry::CONFIG_KEY, [])
			->willReturn(['motion', 'subsidy-award']);

		$this->appConfig->expects(self::never())->method('setValueArray');

		$this->step->run($this->createMock(IOutput::class));

	}//end testRunNeverOverwritesStoredVocabulary()

	/**
	 * The step announces itself with a stable name.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testGetNameDescribesTheStep(): void {
		self::assertStringContainsString('decision-type', $this->step->getName());

	}//end testGetNameDescribesTheStep()
}//end class
