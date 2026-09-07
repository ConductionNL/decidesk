<?php

/**
 * The Decision schema carries the five BRC Besluit fields, after the real merge.
 *
 * @category  Tests
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-decision-carries-the-brc-besluit-fields/specs/decision-management/spec.md#requirement-the-decision-carries-the-brc-besluit-fields-req-dm-040
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Asserts on the MERGED register, not on the fragment.
 *
 * A fragment overlays by key and the merge unions, so a fragment naming a
 * schema the monolith does not declare creates one instead of failing — the
 * overlay then applies perfectly, to nothing. That is not hypothetical: stackiq
 * shipped exactly that for eight months, keying its review-moderation overlay on
 * the schema's TITLE while the slug had moved, and every pending review stayed
 * world-readable behind a rule that reached no schema.
 *
 * So this merges the real fragments over the real monolith and reads the result.
 */
final class DecisionCarriesBrcFieldsTest extends TestCase {

	/**
	 * The fields the VNG BRC serves that the governance model lacked.
	 *
	 * `case` is deliberately absent: cases and decisions are already linked, and
	 * a case reference here would be a second, competing link.
	 *
	 * @var array<int, string>
	 */
	private const BRC_FIELDS = [
		'deliveryDate',
		'expiryDate',
		'publicationDate',
		'responsibleOrganisation',
		'governingBody',
	];

	/**
	 * The register JSON with every `register.d` fragment merged over it.
	 *
	 * @return array<mixed> The merged configuration.
	 */
	private function mergedRegister(): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode(
			(string)file_get_contents($root.'/lib/Settings/decidesk_register.json'),
			true
		);
		$this->assertIsArray($base, 'the register JSON must parse');

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		$this->assertNotEmpty($fragments, 'there must be fragments to merge');

		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$this->assertIsArray($overlay, basename($fragment).' must parse');
			$base = $merge->invoke(null, $base, $overlay);
		}

		return $base;

	}//end mergedRegister()

	/**
	 * The merged Decision carries all five, each as a usable BRC field.
	 *
	 * @return void
	 */
	public function testTheMergedDecisionCarriesTheBrcFields(): void {
		$decision = $this->mergedRegister()['components']['schemas']['Decision'];
		$properties = ($decision['properties'] ?? []);

		foreach (self::BRC_FIELDS as $field) {
			$this->assertArrayHasKey($field, $properties, $field.' must reach the merged schema');
			$this->assertSame('string', $properties[$field]['type'], $field.' type');
		}

		foreach (['deliveryDate', 'expiryDate', 'publicationDate'] as $field) {
			$this->assertSame('date', $properties[$field]['format'], $field.' is a date');
		}

		$this->assertArrayNotHasKey(
			'format',
			$properties['responsibleOrganisation'],
			'an RSIN is not a uuid and must not be formatted as one'
		);

		// `governingBody` is a bestuursorgaan name and NOT a uuid. The
		// distinction is the reason it needed a field of its own: the only
		// field already shaped like it, `targetBody`, is format uuid, so a
		// besluit carrying 'college' was rejected on write and did not move
		// at all. Asserting both sides keeps the two from being collapsed.
		$this->assertArrayNotHasKey(
			'format',
			$properties['governingBody'],
			'a bestuursorgaan is not a uuid and must not be formatted as one'
		);

		$this->assertSame(
			'uuid',
			$properties['targetBody']['format'],
			'targetBody stays a uuid; it is the body an appointment is made FOR, '
			.'not the body that took the decision'
		);

	}//end testTheMergedDecisionCarriesTheBrcFields()

	/**
	 * The BASE register does NOT carry them, so the test above cannot pass by
	 * accident if the fragment stops being merged.
	 *
	 * @return void
	 */
	public function testTheBaseRegisterDoesNotCarryThem(): void {
		$root = dirname(__DIR__, 3);
		$base = json_decode(
			(string)file_get_contents($root.'/lib/Settings/decidesk_register.json'),
			true
		);
		$properties = ($base['components']['schemas']['Decision']['properties'] ?? []);

		$this->assertNotEmpty($properties, 'the base Decision must have properties at all');
		foreach (self::BRC_FIELDS as $field) {
			$this->assertArrayNotHasKey(
				$field,
				$properties,
				$field.' is expected to arrive via the fragment; if the base now declares it, '
				.'this test and the fragment are redundant'
			);
		}

	}//end testTheBaseRegisterDoesNotCarryThem()

	/**
	 * `case` is NOT added. Cases and decisions are already linked, and a second
	 * link would let the two disagree with nothing to say which is right.
	 *
	 * @return void
	 */
	public function testNoCaseReferenceIsAdded(): void {
		$properties = $this->mergedRegister()['components']['schemas']['Decision']['properties'];

		$this->assertArrayNotHasKey('case', $properties);

	}//end testNoCaseReferenceIsAdded()

	/**
	 * The fields the governance model already had are untouched, and are NOT the
	 * same fields under other names.
	 *
	 * `effectiveDate` says when a decision takes effect and `expiryDate` when it
	 * lapses; `publishedAt` records when THIS system published it and
	 * `publicationDate` is the date the decision itself carries. Collapsing
	 * either pair would look like tidying and would lose a distinction the BRC
	 * makes.
	 *
	 * @return void
	 */
	public function testTheExistingDateFieldsSurvive(): void {
		$properties = $this->mergedRegister()['components']['schemas']['Decision']['properties'];

		foreach (['decisionDate', 'effectiveDate', 'publishedAt', 'isPublished'] as $field) {
			$this->assertArrayHasKey($field, $properties, $field.' must survive the merge');
		}

	}//end testTheExistingDateFieldsSurvive()

}//end class
