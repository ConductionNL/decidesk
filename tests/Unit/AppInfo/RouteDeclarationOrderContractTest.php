<?php

/**
 * Tests for the route table's literal-before-wildcard declaration order.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud matches routes in DECLARATION order, so a wildcard declared before
 * a literal route it can match swallows that literal route forever — no error,
 * no log line, just the wildcard's answer where the literal's should be.
 *
 * Measured on the decision-type registry: `decisionTypes#index`
 * (GET /api/v1/decision-types) was declared with its integration-hub group
 * BELOW the `api#index` wildcard (GET /api/v1/{resource}), so the wildcard
 * captured every request and answered 404 "Unknown resource". The picker then
 * fell back silently to its 13 shipped types, and an admin-added type never
 * appeared anywhere. routes.php's own comment documents the identical trap for
 * the write routes; this test makes the whole table's ordering mechanical
 * rather than a per-entry comment.
 *
 * The table under test is the REAL one: `appinfo/routes.php` is required and
 * its returned array inspected. In a unit run the AppHost class is absent, so
 * the file's openregister-absent fallback builds the table — byte-equivalent
 * in ordering to `Routes::standard($extra)`, which also appends canonical
 * routes first, `$extra` second, catch-all last.
 */
class RouteDeclarationOrderContractTest extends TestCase {

	/**
	 * Every literal route must be declared before any wildcard that matches it.
	 *
	 * A "literal" route has no `{placeholder}` in its URL; a wildcard route
	 * has at least one. For every literal/wildcard pair sharing a verb where
	 * the wildcard's pattern matches the literal URL, the literal must come
	 * first — otherwise it is dead the day the table loads.
	 *
	 * @return void
	 */
	public function testEveryLiteralRoutePrecedesEveryWildcardThatMatchesIt(): void {
		$routes = $this->routes();

		$shadowed = [];
		$pairsChecked = 0;

		foreach ($routes as $literalIndex => $literal) {
			if (str_contains((string)$literal['url'], '{') === true) {
				continue;
			}

			foreach ($routes as $wildcardIndex => $wildcard) {
				$url = (string)$wildcard['url'];
				if (str_contains($url, '{') === false) {
					continue;
				}

				if (strtoupper((string)($wildcard['verb'] ?? 'GET')) !== strtoupper((string)($literal['verb'] ?? 'GET'))) {
					continue;
				}

				if (preg_match($this->patternOf(route: $wildcard), (string)$literal['url']) !== 1) {
					continue;
				}

				$pairsChecked++;
				if ($wildcardIndex < $literalIndex) {
					$shadowed[] = sprintf(
						'%s %s (%s, position %d) is shadowed by %s (%s, position %d)',
						(string)$literal['verb'],
						(string)$literal['url'],
						(string)$literal['name'],
						$literalIndex,
						(string)$wildcard['url'],
						(string)$wildcard['name'],
						$wildcardIndex
					);
				}
			}
		}//end foreach

		// Positive control: the SPA catch-all alone matches every literal GET,
		// so a zero here means the table was not inspected at all.
		$this->assertGreaterThan(
			0,
			$pairsChecked,
			'No literal/wildcard pair was checked — the route table was not loaded, so the green means nothing.'
		);

		$this->assertSame(
			[],
			$shadowed,
			"Nextcloud matches in declaration order, so each of these routes is a silent 404:\n  - "
			. implode("\n  - ", $shadowed)
		);
	}//end testEveryLiteralRoutePrecedesEveryWildcardThatMatchesIt()

	/**
	 * The measured victim specifically: the decision-type registry endpoint.
	 *
	 * The generic sweep above would go green if either route were RENAMED
	 * away; this pins the pair that shipped dead, so a regression names
	 * itself.
	 *
	 * @return void
	 */
	public function testDecisionTypesIndexPrecedesTheApiV1Wildcard(): void {
		$routes = $this->routes();

		$positions = [];
		foreach ($routes as $index => $route) {
			$positions[(string)$route['name']] = $index;
		}

		$this->assertArrayHasKey('decisionTypes#index', $positions, 'The registry endpoint left the table entirely.');
		$this->assertArrayHasKey('api#index', $positions, 'The v1 wildcard left the table entirely.');
		$this->assertLessThan(
			$positions['api#index'],
			$positions['decisionTypes#index'],
			'GET /api/v1/decision-types must be declared before the /api/v1/{resource} wildcard, '
			. 'or the wildcard answers 404 "Unknown resource" and the picker silently falls back '
			. 'to the shipped types.'
		);
	}//end testDecisionTypesIndexPrecedesTheApiV1Wildcard()

	/**
	 * The declared route table, loaded from the real file.
	 *
	 * @return array<int, array<string, mixed>> The routes, in declaration order.
	 */
	private function routes(): array {
		$table = require __DIR__ . '/../../../appinfo/routes.php';

		$this->assertIsArray($table['routes'] ?? null, 'appinfo/routes.php must return a routes table.');

		return array_values($table['routes']);
	}//end routes()

	/**
	 * The anchored regex a wildcard route's URL compiles to.
	 *
	 * A `{placeholder}` becomes its declared requirement pattern, or `[^/]+`
	 * when the route declares none — the same default the Nextcloud router
	 * applies.
	 *
	 * @param array<string, mixed> $route The wildcard route.
	 *
	 * @return string The pattern, delimited and anchored.
	 */
	private function patternOf(array $route): string {
		$pattern = preg_quote((string)$route['url'], '#');

		foreach ((array)($route['requirements'] ?? []) as $name => $requirement) {
			$pattern = str_replace('\{' . (string)$name . '\}', '(?:' . (string)$requirement . ')', $pattern);
		}

		$pattern = (string)preg_replace('#\\\\\{[A-Za-z0-9_]+\\\\\}#', '[^/]+', $pattern);

		return '#^' . $pattern . '$#';
	}//end patternOf()
}//end class
