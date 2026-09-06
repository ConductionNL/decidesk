<?php

/**
 * Unit tests for DecisionTypeRegistry.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\DecisionTypeRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the single decision-type vocabulary authority: the stored
 * app-config list wins, the shipped seed only bridges the unseeded window,
 * and malformed entries never widen the vocabulary.
 *
 * @covers \OCA\Decidiq\Service\DecisionTypeRegistry
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
class DecisionTypeRegistryTest extends TestCase {

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Registry under test.
	 *
	 * @var DecisionTypeRegistry
	 */
	private DecisionTypeRegistry $registry;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->registry = new DecisionTypeRegistry(appConfig: $this->appConfig);
	}//end setUp()

	/**
	 * Point the mocked store at a stored vocabulary.
	 *
	 * @param array<int|string, mixed> $stored The stored decision_types value
	 *
	 * @return void
	 */
	private function store(array $stored): void {
		$this->appConfig->method('getValueArray')
			->with(Application::APP_ID, DecisionTypeRegistry::CONFIG_KEY, [])
			->willReturn($stored);
	}//end store()

	/**
	 * The stored vocabulary is served as-is, trimmed and de-duplicated.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testGetTypesServesStoredVocabulary(): void {
		$this->store(['motion', ' subsidy-award ', 'motion']);

		self::assertSame(['motion', 'subsidy-award'], $this->registry->getTypes());

	}//end testGetTypesServesStoredVocabulary()

	/**
	 * An absent row falls back to the shipped seed, so a fresh install never
	 * refuses the whole vocabulary before the seed step has run.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testGetTypesFallsBackToSeedWhenUnset(): void {
		$this->store([]);

		self::assertSame(DecisionTypeRegistry::DEFAULT_TYPES, $this->registry->getTypes());

	}//end testGetTypesFallsBackToSeedWhenUnset()

	/**
	 * A row holding only unusable entries also falls back to the seed.
	 *
	 * A vocabulary of empty strings and non-strings validates nothing; serving
	 * it would fail every create closed on a config typo.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testGetTypesFallsBackToSeedWhenOnlyMalformedEntries(): void {
		$this->store(['', '   ', 42, null, ['nested']]);

		self::assertSame(DecisionTypeRegistry::DEFAULT_TYPES, $this->registry->getTypes());

	}//end testGetTypesFallsBackToSeedWhenOnlyMalformedEntries()

	/**
	 * Malformed entries are dropped without discarding the usable ones.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testGetTypesDropsMalformedEntriesOnly(): void {
		$this->store(['motion', 42, '', 'advice']);

		self::assertSame(['motion', 'advice'], $this->registry->getTypes());

	}//end testGetTypesDropsMalformedEntriesOnly()

	/**
	 * isAllowed() answers from the stored vocabulary, in both directions.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testIsAllowedConsultsStoredVocabulary(): void {
		$this->store(['motion', 'subsidy-award']);

		self::assertTrue($this->registry->isAllowed(decisionType: 'subsidy-award'));
		// `resolution` sits in the seed, but the STORE is the authority.
		self::assertFalse($this->registry->isAllowed(decisionType: 'resolution'));

	}//end testIsAllowedConsultsStoredVocabulary()

	/**
	 * isAllowed() matches strictly: no case folding, no partial matches.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 *
	 * @return void
	 */
	public function testIsAllowedMatchesStrictly(): void {
		$this->store(['motion']);

		self::assertTrue($this->registry->isAllowed(decisionType: 'motion'));
		self::assertFalse($this->registry->isAllowed(decisionType: 'Motion'));
		self::assertFalse($this->registry->isAllowed(decisionType: 'motio'));
		self::assertFalse($this->registry->isAllowed(decisionType: ''));

	}//end testIsAllowedMatchesStrictly()
}//end class
