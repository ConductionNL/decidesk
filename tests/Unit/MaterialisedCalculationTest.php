<?php

/**
 * Materialised-calculation conformance for the decidiq registers.
 *
 * A `materialise: true` calculation is PERSISTED on save, not computed on read.
 * `CalculationOnSaveListener` patches the value into the payload before
 * persistence and `RenderObject::applyVirtualCalculations()` deliberately skips
 * it. The value therefore has to land in a column, and
 * `MagicMapper::prepareObjectDataForTable()` is a whitelist by omission over the
 * schema's DECLARED properties: it copies out the properties the schema
 * declares and never reads anything else, with no JSON blob column to fall back
 * on. A materialised calculation the schema does not declare as a property is
 * computed correctly, written onto the entity, and then thrown away at the
 * database boundary, with a warning in nextcloud.log and a 200 on the wire.
 *
 * That is how `Decision.routeComplete` was discarded 60 times in one install,
 * and how `Meeting.quorumWith` left MeetingTransitionGuard reading an absent
 * value as false on every meeting.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts every materialised calculation can actually be stored and read.
 *
 * @coversNothing
 */
class MaterialisedCalculationTest extends TestCase {

	/**
	 * The operator vocabulary `CalculationEvaluator::evaluateNode()` implements,
	 * which is also what `CalculationAnnotationValidator::VALID_OPS` accepts.
	 *
	 * Anything else throws "Unknown operator" at evaluation time, which the
	 * listener and the render path both swallow: the field is silently absent
	 * rather than wrong, and nothing surfaces where a user would see it. Three
	 * plausible-sounding names have been written into this app's schemas and
	 * none of them exist: `add` is `+`, `mul`/`div` are `*` and `/`, and
	 * `switch`, `size` and `firstRelated` have no equivalent at all.
	 *
	 * @var array<int, string>
	 */
	private const OPERATORS = [
		'prop',
		'lit',
		'concat',
		'if',
		'not',
		'and',
		'or',
		'+',
		'-',
		'*',
		'/',
		'%',
		'eq',
		'ne',
		'lt',
		'lte',
		'gt',
		'gte',
		'now',
		'diffDays',
		'formatDate',
		'dateDiff',
		'dateAdd',
		'sequence',
		'max',
		'min',
		'coalesce',
		'abs',
		'round',
		'year',
		'monthsElapsed',
		'sha256',
	];

	/**
	 * Every register document this app ships, base plus `register.d` fragments.
	 *
	 * `RegisterConfigurationLocator` deep-merges every `lib/Settings/register.d`
	 * fragment over the base document, so a fragment's schemas are imported just
	 * as the base file's are. Listing only the base files is how three broken
	 * calculations on `Goal` shipped unmeasured.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function registerFiles(): array {
		$settings = __DIR__ . '/../../lib/Settings/';
		$files = [$settings . 'decidesk_register.json', $settings . 'decidiq_mock_register.json'];
		foreach ((glob($settings . 'register.d/*.json') ?: []) as $fragment) {
			$files[] = $fragment;
		}

		return $files;
	}//end registerFiles()

	/**
	 * Load every schema in every shipped register, keyed by file and name.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function schemas(): array {
		$out = [];
		foreach ($this->registerFiles() as $path) {
			$file = basename($path);
			$data = json_decode(file_get_contents($path), true);
			$this->assertIsArray(actual: $data, message: $file . ' must parse as JSON');

			foreach (($data['components']['schemas'] ?? []) as $name => $schema) {
				if (is_array($schema) === true) {
					$out[] = [$file, (string)$name, $schema];
				}
			}
		}

		$this->assertNotEmpty(actual: $out, message: 'the registers must carry schemas');

		return $out;
	}//end schemas()

	/**
	 * Every schema that declares calculations, with its calculations block.
	 *
	 * Asserts the sweep FOUND annotations. A sweep that silently matched nothing
	 * passes every rule below it and proves nothing at all.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}>
	 */
	private function declaredCalculations(): array {
		$out = [];
		foreach ($this->schemas() as [$file, $name, $schema]) {
			$calcs = ($schema['x-openregister-calculations'] ?? null);
			if (is_array($calcs) === false || $calcs === []) {
				continue;
			}

			$out[] = [$file, $name, $schema, $calcs];
		}

		$this->assertNotEmpty(
			actual: $out,
			message: 'no schema declares x-openregister-calculations; the sweep below would assert nothing'
		);

		return $out;
	}//end declaredCalculations()

	/**
	 * Every materialised calculation is declared as a property of its schema.
	 *
	 * @return void
	 */
	public function testMaterialisedCalculationsAreDeclaredProperties(): void {
		$findings = [];

		foreach ($this->schemas() as [$file, $name, $schema]) {
			$properties = ($schema['properties'] ?? []);
			foreach (($schema['x-openregister-calculations'] ?? []) as $calc => $spec) {
				if (is_array($spec) === false || ($spec['materialise'] ?? false) !== true) {
					continue;
				}

				if (array_key_exists((string)$calc, $properties) === false) {
					$findings[] = $file . ' / ' . $name . ': calculation "' . $calc
						. '" is materialise: true but the schema declares no such property,'
						. ' so every write discards it';
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $findings,
			message: "A materialised calculation is persisted on save, and only a DECLARED\n"
			. "property gets a column to be persisted into. Declare it, or make the\n"
			. "calculation virtual (materialise: false) so it is computed on read.\n\n"
			. implode("\n", $findings)
		);

	}//end testMaterialisedCalculationsAreDeclaredProperties()

	/**
	 * Calculations read aggregate counts through `@aggregate`, never bare.
	 *
	 * `CalculationPayloadBuilder::build()` resolves `x-openregister-aggregate-refs`
	 * and injects each result under `@aggregate.<name>`. It does NOT read
	 * `x-openregister-aggregations`, which is the reporting annotation
	 * `AggregationController` serves. A calculation naming an aggregation as a
	 * bare `prop` therefore resolves to null, and the surrounding comparison
	 * quietly answers false rather than throwing: `routeComplete` came out false
	 * on every decision whatever its route, and `quorumPercentage` came out 0.
	 *
	 * @return void
	 */
	public function testCalculationsReadAggregatesThroughTheAggregatePrefix(): void {
		$findings = [];

		foreach ($this->schemas() as [$file, $name, $schema]) {
			$aggregations = array_keys(($schema['x-openregister-aggregations'] ?? []));
			$refs = array_keys(($schema['x-openregister-aggregate-refs'] ?? []));
			if ($aggregations === []) {
				continue;
			}

			foreach ($this->propTokens(node: ($schema['x-openregister-calculations'] ?? [])) as $token) {
				if (in_array($token, $aggregations, true) === true) {
					$findings[] = $file . ' / ' . $name . ': calculation reads "' . $token
						. '" as a bare prop, which resolves to null. Declare it under'
						. ' x-openregister-aggregate-refs and read it as "@aggregate.' . $token . '"';
					continue;
				}

				if (str_starts_with($token, '@aggregate.') === false) {
					continue;
				}

				$referenced = explode('.', substr($token, strlen('@aggregate.')))[0];
				if (in_array($referenced, $refs, true) === false) {
					$findings[] = $file . ' / ' . $name . ': calculation reads "' . $token
						. '" but x-openregister-aggregate-refs declares no "' . $referenced . '"';
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $findings,
			message: "Calculations must read aggregate counts through @aggregate, backed by an\n"
			. "x-openregister-aggregate-refs declaration. A bare aggregation name is null.\n\n"
			. implode("\n", $findings)
		);

	}//end testCalculationsReadAggregatesThroughTheAggregatePrefix()

	/**
	 * An aggregate-reference filters with `filters`, not `filter`.
	 *
	 * `AggregateReferenceResolver::resolveOne()` reads `$spec['filters']`, while
	 * the reporting annotation next door uses `filter`. A block copied across
	 * without the rename resolves unfiltered and counts every row in the schema.
	 *
	 * @return void
	 */
	public function testAggregateReferencesUseTheFiltersKey(): void {
		$findings = [];

		foreach ($this->schemas() as [$file, $name, $schema]) {
			foreach (($schema['x-openregister-aggregate-refs'] ?? []) as $ref => $spec) {
				if (is_array($spec) === false) {
					continue;
				}

				if (array_key_exists('filter', $spec) === true) {
					$findings[] = $file . ' / ' . $name . ': aggregate-reference "' . $ref
						. '" uses "filter"; the resolver reads "filters"';
				}
			}
		}

		$this->assertSame(expected: [], actual: $findings, message: implode("\n", $findings));

	}//end testAggregateReferencesUseTheFiltersKey()

	/**
	 * Every aggregate-reference names a target that actually RESOLVES.
	 *
	 * `AggregateReferenceResolver::resolveOne()` hands `schema` straight to
	 * `AggregationRunner::runAdhocByRef()`, which resolves it through
	 * `RegisterScopedSchemaResolver` and finally `SchemaMapper::findInIds()`.
	 * That query matches a **uuid**, a **case-insensitive slug**, and — only
	 * when the ref is numeric — the primary key. It never matches the key the
	 * register document files the schema under, and never the schema `title`.
	 *
	 * A target that does not resolve throws inside the resolver, which catches
	 * it, logs `Aggregate-reference resolution failed`, and injects **null**.
	 * The save still succeeds. `Decision.routeComplete` named `DecisionStage`
	 * where the slug is `decision-stage`, so all three of its counts were null,
	 * `null > 0` was false, the `and` short-circuited, and routeComplete came
	 * out false on every decision whatever its route: 129 warnings on one fresh
	 * rig, and #1124 declared the property and corrected the annotation key
	 * without the reference inside it ever resolving.
	 *
	 * The annotation validator cannot catch this. It checks the shape of the
	 * block and that `@aggregate.<name>` names a declared reference; it never
	 * loads the target, so an unresolvable target passes every check there is.
	 *
	 * @return void
	 */
	public function testAggregateReferenceTargetsResolveToAShippedSlug(): void {
		$slugsByKey = [];
		$slugs = [];
		foreach ($this->schemas() as [, $name, $schema]) {
			$slug = ($schema['slug'] ?? null);
			if (is_string($slug) === false || $slug === '') {
				continue;
			}

			$slugs[strtolower($slug)] = $slug;
			$slugsByKey[$name] = $slug;
		}

		$this->assertNotEmpty(actual: $slugs, message: 'the registers must declare schema slugs to resolve against');

		$checked = 0;
		$findings = [];
		foreach ($this->schemas() as [$file, $name, $schema]) {
			foreach (($schema['x-openregister-aggregate-refs'] ?? []) as $ref => $spec) {
				if (is_array($spec) === false) {
					continue;
				}

				$target = ($spec['schema'] ?? null);
				if (is_string($target) === false || $target === '') {
					$findings[] = $file . ' / ' . $name . ': aggregate-reference "' . $ref . '" names no target schema';
					continue;
				}

				$checked++;
				if (array_key_exists(strtolower($target), $slugs) === true) {
					continue;
				}

				$hint = ' and no shipped schema carries that slug';
				if (array_key_exists($target, $slugsByKey) === true) {
					$hint = ', which is the register document KEY of the schema whose slug is "'
						. $slugsByKey[$target] . '". The resolver matches slugs, never keys';
				}

				$findings[] = $file . ' / ' . $name . ': aggregate-reference "' . $ref . '" targets "'
					. $target . '"' . $hint;
			}
		}

		$this->assertGreaterThan(
			expected: 0,
			actual: $checked,
			message: 'no aggregate-reference target was checked; the sweep would assert nothing'
		);
		$this->assertSame(
			expected: [],
			actual: $findings,
			message: "An aggregate-reference whose target does not resolve injects null and logs a\n"
			. "warning. The calculation reading it then answers false or zero, and the save\n"
			. "succeeds. Name the schema's SLUG.\n\n" . implode("\n", $findings)
		);

	}//end testAggregateReferenceTargetsResolveToAShippedSlug()

	/**
	 * Every shipped calculation uses operators the engine implements.
	 *
	 * `CalculationEvaluator::evaluateNode()` is a `match` with a default that
	 * throws. `CalculationOnSaveListener` catches the throw and moves on, and
	 * `RenderObject::applyVirtualCalculations()` catches it and writes a debug
	 * line. Either way an invented operator costs the field its value with no
	 * error anywhere a user or an operator would look.
	 *
	 * @return void
	 */
	public function testShippedCalculationsUseOperatorsTheEngineImplements(): void {
		$findings = [];

		foreach ($this->declaredCalculations() as [$file, $name, , $calcs]) {
			foreach ($calcs as $calc => $spec) {
				if (is_array($spec) === false) {
					continue;
				}

				foreach ($this->expressionOperators(node: ($spec['expression'] ?? null)) as $op) {
					if (in_array($op, self::OPERATORS, true) === true) {
						continue;
					}

					$findings[] = $file . ' / ' . $name . ': calculation "' . $calc . '" uses operator "'
						. $op . '", which CalculationEvaluator does not implement';
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $findings,
			message: "An operator the evaluator does not implement throws, both callers swallow the\n"
			. "throw, and the field is silently absent. The vocabulary is: "
			. implode(', ', self::OPERATORS) . ".\n\n" . implode("\n", $findings)
		);

	}//end testShippedCalculationsUseOperatorsTheEngineImplements()

	/**
	 * No calculation reads the property it is named after.
	 *
	 * A calculation and a property of the same name are the same key in the
	 * rendered object, so `{"prop": "<own name>"}` reads the calculation, not the
	 * stored value: `CalculationAnnotationValidator` rejects it as a cycle. It is
	 * the shape you reach for when you want a calculation to fall back to what an
	 * actor wrote, and it cannot be written. `DecisionStage.outcome` tried, and
	 * the answer is that such a field belongs to the code that writes it.
	 *
	 * @return void
	 */
	public function testNoCalculationReadsItsOwnName(): void {
		$findings = [];

		foreach ($this->declaredCalculations() as [$file, $name, , $calcs]) {
			foreach ($calcs as $calc => $spec) {
				if (is_array($spec) === false) {
					continue;
				}

				if (in_array((string)$calc, $this->propTokens(node: ($spec['expression'] ?? null)), true) === false) {
					continue;
				}

				$findings[] = $file . ' / ' . $name . ': calculation "' . $calc
					. '" reads "' . $calc . '", which is itself: a cycle, not a fallback to the stored value';
			}
		}

		$this->assertSame(expected: [], actual: $findings, message: implode("\n", $findings));

	}//end testNoCalculationReadsItsOwnName()

	/**
	 * A virtual calculation never takes the name of a declared property.
	 *
	 * `RenderObject::applyVirtualCalculations()` assigns `$data[$name] = $value`
	 * unconditionally, so a `materialise: false` calculation named after a stored
	 * property replaces that property on every read. A property an actor writes
	 * and a calculation of the same name cannot both be right.
	 *
	 * @return void
	 */
	public function testVirtualCalculationsDoNotShadowADeclaredProperty(): void {
		$findings = [];

		foreach ($this->declaredCalculations() as [$file, $name, $schema, $calcs]) {
			$properties = ($schema['properties'] ?? []);
			foreach ($calcs as $calc => $spec) {
				if (is_array($spec) === false || ($spec['materialise'] ?? false) === true) {
					continue;
				}

				if (array_key_exists((string)$calc, $properties) === false) {
					continue;
				}

				$findings[] = $file . ' / ' . $name . ': virtual calculation "' . $calc
					. '" has the name of a declared property, so it replaces that property on every read';
			}
		}

		$this->assertSame(expected: [], actual: $findings, message: implode("\n", $findings));

	}//end testVirtualCalculationsDoNotShadowADeclaredProperty()

	/**
	 * A calculation reading `@ref` or `@aggregate` is materialised.
	 *
	 * Only the save-time path builds the enriched payload:
	 * `CalculationOnSaveListener` calls `CalculationPayloadBuilder::build()`,
	 * which resolves `x-openregister-references` into `@ref` and
	 * `x-openregister-aggregate-refs` into `@aggregate`.
	 * `RenderObject::applyVirtualCalculations()` builds its own payload with
	 * `@self` alone. A `materialise: false` calculation reading either prefix
	 * therefore resolves it to null, and the comparison around it quietly
	 * answers false instead of throwing.
	 *
	 * @return void
	 */
	public function testCalculationsReadingReferencesOrAggregatesAreMaterialised(): void {
		$findings = [];

		foreach ($this->declaredCalculations() as [$file, $name, , $calcs]) {
			foreach ($calcs as $calc => $spec) {
				if (is_array($spec) === false || ($spec['materialise'] ?? false) === true) {
					continue;
				}

				foreach ($this->propTokens(node: ($spec['expression'] ?? null)) as $token) {
					if (str_starts_with($token, '@ref.') === false
						&& str_starts_with($token, '@aggregate.') === false
					) {
						continue;
					}

					$findings[] = $file . ' / ' . $name . ': calculation "' . $calc . '" reads "' . $token
						. '" but is materialise: false, and the render path resolves neither prefix';
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $findings,
			message: "Only CalculationOnSaveListener builds @ref and @aggregate. A virtual\n"
			. "calculation reading either gets null, silently.\n\n" . implode("\n", $findings)
		);

	}//end testCalculationsReadingReferencesOrAggregatesAreMaterialised()

	/**
	 * Collect every operator key used in one calculation expression.
	 *
	 * Mirrors `CalculationAnnotationValidator::walk()`: an expression node is a
	 * single-key array whose key is the operator, `dateDiff` alone takes a named
	 * `{from, to, unit}` dict, and a bare scalar is a literal.
	 *
	 * @param mixed $node The expression, or any node inside it.
	 *
	 * @return array<int, string> The operator keys, in encounter order.
	 */
	private function expressionOperators(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		if (array_is_list($node) === true) {
			$ops = [];
			foreach ($node as $item) {
				$ops = array_merge($ops, $this->expressionOperators(node: $item));
			}

			return $ops;
		}

		if (count($node) !== 1) {
			return [];
		}

		$op = (string)array_key_first($node);
		$args = $node[$op];
		if ($op === 'prop' || $op === 'lit') {
			return [$op];
		}

		if ($op === 'dateDiff' && is_array($args) === true) {
			return array_merge(
				['dateDiff'],
				$this->expressionOperators(node: ($args['from'] ?? null)),
				$this->expressionOperators(node: ($args['to'] ?? null))
			);
		}

		return array_merge([$op], $this->expressionOperators(node: $args));
	}//end expressionOperators()

	/**
	 * Collect every `prop` token used anywhere in a calculations block.
	 *
	 * @param mixed $node The calculations block, or any node inside it.
	 *
	 * @return array<int, string> The prop tokens, in encounter order.
	 */
	private function propTokens(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$tokens = [];
		foreach ($node as $key => $value) {
			if ($key === 'prop') {
				if (is_string($value) === true) {
					$tokens[] = $value;
				} elseif (is_array($value) === true && is_string(($value[0] ?? null)) === true) {
					$tokens[] = $value[0];
				}

				continue;
			}

			$tokens = array_merge($tokens, $this->propTokens(node: $value));
		}

		return $tokens;
	}//end propTokens()
}//end class
