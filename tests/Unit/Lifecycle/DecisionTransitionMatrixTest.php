<?php

/**
 * Unit tests pinning the parity between DecisionTransitionGuard's executable
 * transition map and the declarative x-openregister-lifecycle grammar on the
 * Decision schema.
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
 * @spec openspec/specs/decision-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Lifecycle;

use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use PHPUnit\Framework\TestCase;

/**
 * The accepted grammar must be the executable one: OpenRegister's
 * LifecycleValidationListener rejects any lifecycle write whose from→to edge
 * is not declared in the schema's `x-openregister-lifecycle.transitions`, so
 * every edge DecisionTransitionGuard can permit MUST also be declared there.
 * A guard edge the schema does not carry is dead auth code — the guard says
 * yes, OR then rejects the save ("No transition allows moving lifecycle from
 * deliberating to decided", observed live on the decide-without-vote path).
 *
 * The reverse containment is deliberately NOT asserted for `withdrawn`: the
 * withdraw edges are declarative-only (the guard has no `withdraw` action;
 * withdrawal is not routed through the action map).
 *
 * @covers \OCA\Decidiq\Lifecycle\DecisionTransitionGuard
 *
 * @spec openspec/specs/decision-management/spec.md
 */
class DecisionTransitionMatrixTest extends TestCase {

	/**
	 * Guard under test.
	 *
	 * @var DecisionTransitionGuard
	 */
	private DecisionTransitionGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new DecisionTransitionGuard();

	}//end setUp()

	/**
	 * The two register definitions that must both carry the Decision lifecycle.
	 *
	 * The mock register is asserted alongside the canonical one deliberately:
	 * an edge added only to the canonical register makes every environment
	 * seeded from the mock reject transitions the guard permits.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function registerProvider(): array {
		return [
			'canonical register' => ['decidesk_register.json', 'Decision'],
			'mock register' => ['decidiq_mock_register.json', 'Decision'],
		];
	}//end registerProvider()

	/**
	 * Load the Decision schema's x-openregister-lifecycle block from a
	 * register definition file.
	 *
	 * @param string $file Register JSON basename under lib/Settings/
	 * @param string $schemaKey Component schema key holding the Decision schema
	 *
	 * @return array<string, mixed> The lifecycle annotation
	 */
	private function loadLifecycle(string $file, string $schemaKey): array {
		$path = (__DIR__ . '/../../../lib/Settings/' . $file);
		$raw = file_get_contents($path);
		self::assertIsString($raw, "Register definition {$file} must be readable");

		$register = json_decode($raw, true);
		self::assertIsArray($register, "Register definition {$file} must be valid JSON");

		$lifecycle = ($register['components']['schemas'][$schemaKey]['x-openregister-lifecycle'] ?? null);
		self::assertIsArray($lifecycle, "Schema {$schemaKey} in {$file} must declare x-openregister-lifecycle");

		return $lifecycle;
	}//end loadLifecycle()

	/**
	 * Every edge in the guard's transition map must be declared in the
	 * schema's lifecycle grammar — for every action, for every from-state.
	 *
	 * This walks the full matrix rather than a sample: the two edges this
	 * test was written to pin (deliberating→decided, the decide-without-vote
	 * path, and decided→archived, archiving a rejected decision that can
	 * never enact) were exactly the ones a sampled check missed.
	 *
	 * @dataProvider registerProvider
	 *
	 * @param string $file Register JSON basename
	 * @param string $schemaKey Component schema key
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testEveryGuardEdgeIsDeclaredInTheSchemaLifecycle(string $file, string $schemaKey): void {
		$lifecycle = $this->loadLifecycle(file: $file, schemaKey: $schemaKey);

		$declared = [];
		foreach (($lifecycle['transitions'] ?? []) as $transition) {
			$declared[] = (($transition['from'] ?? '') . '→' . ($transition['to'] ?? ''));
		}

		self::assertNotSame([], $declared, "{$file}: lifecycle must declare transitions");

		foreach ($this->guard->getKnownActions() as $action) {
			$transition = $this->guard->resolveTransition(action: $action);
			self::assertIsArray($transition);

			foreach ($transition['from'] as $fromState) {
				$edge = ($fromState . '→' . $transition['to']);
				self::assertContains(
					$edge,
					$declared,
					"{$file}: guard action '{$action}' permits {$edge}, but the schema lifecycle does not declare it — OR would reject every such transition at save time"
				);
			}
		}
	}//end testEveryGuardEdgeIsDeclaredInTheSchemaLifecycle()

	/**
	 * The schema's lifecycle states must be exactly the guard's states plus
	 * the declarative-only terminal `withdrawn`.
	 *
	 * @dataProvider registerProvider
	 *
	 * @param string $file Register JSON basename
	 * @param string $schemaKey Component schema key
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testSchemaStatesMatchGuardStatesPlusWithdrawn(string $file, string $schemaKey): void {
		$lifecycle = $this->loadLifecycle(file: $file, schemaKey: $schemaKey);

		$expected = array_merge(DecisionTransitionGuard::STATES, ['withdrawn']);
		sort($expected);

		$actual = ($lifecycle['states'] ?? []);
		sort($actual);

		self::assertSame($expected, $actual, "{$file}: schema lifecycle states must match the guard's states plus 'withdrawn'");
	}//end testSchemaStatesMatchGuardStatesPlusWithdrawn()

	/**
	 * Every schema edge that is not a withdraw edge must be reachable through
	 * the guard's action map: the schema must not silently accept a transition
	 * no action can perform.
	 *
	 * @dataProvider registerProvider
	 *
	 * @param string $file Register JSON basename
	 * @param string $schemaKey Component schema key
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testEveryNonWithdrawSchemaEdgeHasAGuardAction(string $file, string $schemaKey): void {
		$lifecycle = $this->loadLifecycle(file: $file, schemaKey: $schemaKey);

		$guardEdges = [];
		foreach ($this->guard->getKnownActions() as $action) {
			$transition = $this->guard->resolveTransition(action: $action);
			self::assertIsArray($transition);
			foreach ($transition['from'] as $fromState) {
				$guardEdges[] = ($fromState . '→' . $transition['to']);
			}
		}

		foreach (($lifecycle['transitions'] ?? []) as $transition) {
			$to = (string)($transition['to'] ?? '');
			if ($to === 'withdrawn') {
				continue;
			}

			$edge = ((string)($transition['from'] ?? '') . '→' . $to);
			self::assertContains(
				$edge,
				$guardEdges,
				"{$file}: schema lifecycle declares {$edge}, but no guard action performs it — the declarative grammar is wider than the executable one"
			);
		}
	}//end testEveryNonWithdrawSchemaEdgeHasAGuardAction()

	/**
	 * The decide-without-vote edge stays domain-gated on the guard even now
	 * that the schema declares it: permissive operational domains may take it,
	 * formal domains and the default-deny fallback may not.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testDecideWithoutVoteEdgeStaysDomainGated(): void {
		foreach (['operations', 'citizen'] as $domain) {
			self::assertTrue(
				$this->guard->isTransitionAllowed(domain: $domain, fromState: 'deliberating', toState: 'decided'),
				"Domain '{$domain}' allows decide-without-vote"
			);
		}

		foreach (['legislative', 'association', 'corporate', 'no-such-domain'] as $domain) {
			self::assertFalse(
				$this->guard->isTransitionAllowed(domain: $domain, fromState: 'deliberating', toState: 'decided'),
				"Domain '{$domain}' must reject decide-without-vote"
			);
		}
	}//end testDecideWithoutVoteEdgeStaysDomainGated()
}//end class
