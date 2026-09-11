<?php

/**
 * Unit tests pinning every declarative lifecycle to the one shape OpenRegister
 * can read.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Lifecycle;

use OCA\Decidiq\Service\ParticipationLifecycleService;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * OpenRegister's LifecycleValidationListener reads each entry of
 * `x-openregister-lifecycle.transitions` as `{ from, to }`. An entry of any
 * other shape matches nothing, so the listener refuses EVERY change of the
 * lifecycle field while the schema still reads as if it declared a graph.
 *
 * That is how PublicConsultation shipped: its transitions were written as
 * `{ "draft": ["open", ...], ... }`, a map from a state to its targets. Every
 * status change on a consultation answered 422 "No transition allows moving
 * status from draft to open", through the object API and through decidiq's
 * own transition endpoint alike, so no consultation could be opened, closed or
 * awarded. The first test fails on that shape in any register file; the second
 * pins the edges decidiq's own transition endpoint relies on.
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 */
class LifecycleTransitionShapeTest extends TestCase {

	/**
	 * Every register definition file: both registers and every fragment.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function registerFileProvider(): array {
		$settings = (__DIR__ . '/../../../lib/Settings/');
		$files = array_merge(
			[$settings . 'decidesk_register.json', $settings . 'decidiq_mock_register.json'],
			(glob($settings . 'register.d/*.json') ?: [])
		);

		$cases = [];
		foreach ($files as $file) {
			$cases[basename($file)] = [$file];
		}

		return $cases;
	}//end registerFileProvider()

	/**
	 * Decode one register definition file.
	 *
	 * @param string $file Absolute path.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private function load(string $file): array {
		$raw = file_get_contents($file);
		self::assertIsString($raw, "{$file} must be readable");

		$decoded = json_decode($raw, true);
		self::assertIsArray($decoded, "{$file} must be valid JSON");

		return $decoded;
	}//end load()

	/**
	 * Every declared transition is a `{ from, to }` entry.
	 *
	 * @dataProvider registerFileProvider
	 *
	 * @param string $file Absolute path of the register file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function testEveryDeclaredTransitionHasFromAndTo(string $file): void {
		$schemas = ($this->load(file: $file)['components']['schemas'] ?? []);
		self::assertIsArray($schemas, basename($file) . ': components.schemas must be an object');

		foreach ($schemas as $name => $schema) {
			$transitions = ($schema['x-openregister-lifecycle']['transitions'] ?? null);
			if ($transitions === null) {
				continue;
			}

			self::assertIsArray($transitions, "{$name}: transitions must be a list");
			foreach ($transitions as $key => $transition) {
				$where = basename($file) . " {$name} transition {$key}";
				self::assertIsArray($transition, "{$where} must be an object, not " . json_encode($transition));
				self::assertIsString($transition['to'] ?? null, "{$where} must name a `to` state");

				$from = ($transition['from'] ?? null);
				if (is_string($from) === true) {
					$from = [$from];
				}

				self::assertIsArray($from, "{$where} must name a `from` state or list of states");
				self::assertNotSame([], $from, "{$where} must name at least one `from` state");
				foreach ($from as $state) {
					self::assertIsString($state, "{$where} has a non-string `from` state");
				}
			}//end foreach
		}//end foreach
	}//end testEveryDeclaredTransitionHasFromAndTo()

	/**
	 * Both registers the consultation lifecycle must be declared in.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function consultationRegisterProvider(): array {
		return [
			'canonical register' => ['decidesk_register.json'],
			'mock register' => ['decidiq_mock_register.json'],
		];
	}//end consultationRegisterProvider()

	/**
	 * Every edge ParticipationLifecycleService allows on a consultation is an
	 * edge the PublicConsultation schema declares, or OpenRegister refuses the
	 * save the service makes after its own check passed.
	 *
	 * @dataProvider consultationRegisterProvider
	 *
	 * @param string $file Register JSON basename under lib/Settings/.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function testEveryServiceConsultationEdgeIsDeclared(string $file): void {
		$register = $this->load(file: (__DIR__ . '/../../../lib/Settings/' . $file));
		$transitions = ($register['components']['schemas']['PublicConsultation']['x-openregister-lifecycle']['transitions'] ?? []);

		$declared = [];
		foreach ($transitions as $transition) {
			foreach ((array)($transition['from'] ?? []) as $from) {
				$declared[] = ($from . '->' . ($transition['to'] ?? ''));
			}
		}

		$serviceEdges = (new ReflectionClassConstant(ParticipationLifecycleService::class, 'CONSULTATION_TRANSITIONS'))->getValue();
		self::assertIsArray($serviceEdges);
		self::assertNotSame([], $serviceEdges);

		foreach ($serviceEdges as $from => $targets) {
			foreach ($targets as $to) {
				self::assertContains(
					"{$from}->{$to}",
					$declared,
					"{$file}: PublicConsultation must declare {$from} -> {$to}, which the transition endpoint allows"
				);
			}
		}
	}//end testEveryServiceConsultationEdgeIsDeclared()
}//end class
