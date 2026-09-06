<?php

/**
 * Contract tests for ReadsLegacyRows::coerceToTarget().
 *
 * WHY THIS EXISTS. Every supersession migration in this app carries a
 * hand-written `REFERENCES` map saying which of its properties are references.
 * Measured on 2026-09-05 by forcing an `occ upgrade`, that map was missing an
 * entry in seven source/target pairs across five migrations, and each omission
 * had the same consequence: a seeded slug was copied verbatim into a property
 * the target declares as `format: uuid`, OpenRegister refused the whole record,
 * and the step reported it through `$output->warning()` — which does not fail
 * an upgrade, so it had gone unseen since the migrations were written.
 *
 * `coerceToTarget()` reads what the target schema already declares (`format`
 * and `$ref`) instead of a second, drifting copy of the same knowledge. These
 * tests pin the three behaviours that make that safe.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Shared migration helper; the behaviour it guards is the shipped
 *       schema's own `format`/`$ref` declaration, not a separate spec.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Migration;

use OCA\Decidiq\Migration\ReadsLegacyRows;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The shared coercion resolves what the target declares, once.
 */
class CoerceToTargetTest extends TestCase {

	/**
	 * The properties a target might declare, covering all three shapes.
	 *
	 * @var array<string,mixed>
	 */
	private const PROPERTIES = [
		'goal' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'Goal'],
		'deadline' => ['type' => 'string', 'format' => 'date-time'],
		'subject' => ['type' => 'string'],
		'orphan' => ['type' => 'string', 'format' => 'uuid'],
	];

	/**
	 * The trait under test, with a fake slug resolver.
	 *
	 * @return object The subject.
	 */
	private function subject(): object {
		return new class {
			use ReadsLegacyRows;

			/**
			 * The register these schemas belong to.
			 *
			 * @var string
			 */
			private const REGISTER = 'decidiq';

			/**
			 * Expose the protected helper.
			 *
			 * @param array<string,mixed> $properties Declared properties.
			 * @param array<string,mixed> $payload    The payload.
			 * @param array<int,string>   $skip       Keys already resolved.
			 *
			 * @return array<string,mixed> The coerced payload.
			 */
			public function call(array $properties, array $payload, array $skip = []): array {
				return $this->coerceToTarget(
					objectService: new \stdClass(),
					properties: $properties,
					payload: $payload,
					alreadyResolved: $skip
				);
			}

			/**
			 * The logger the trait reports through.
			 *
			 * @return LoggerInterface The logger.
			 */
			protected function migrationLogger(): LoggerInterface {
				return new \Psr\Log\NullLogger();
			}

			/**
			 * Stand in for the OpenRegister lookup, so the test needs no server.
			 *
			 * @param object $objectService Unused.
			 * @param string $schema        The schema the value points at.
			 * @param string $slug          The slug to resolve.
			 *
			 * @return string|null The fake uuid.
			 */
			protected function uuidForSlug(object $objectService, string $schema, string $slug): ?string {
				return 'uuid-for-' . $schema . '-' . $slug;
			}
		};
	}//end subject()

	/**
	 * 🔴 THE DEFECT THIS WAS BUILT FOR. A slug in a `format: uuid` property is
	 * resolved against the schema its own `$ref` names.
	 *
	 * @return void
	 */
	public function testASlugInAUuidPropertyIsResolvedAgainstItsRef(): void {
		$out = $this->subject()->call(
			self::PROPERTIES,
			['goal' => 'goal-amsterdam-klimaatneutraal-2050']
		);

		$this->assertSame(
			'uuid-for-goal-goal-amsterdam-klimaatneutraal-2050',
			$out['goal'],
			'A uuid property must be resolved against the schema its $ref names'
		);
	}//end testASlugInAUuidPropertyIsResolvedAgainstItsRef()

	/**
	 * A bare date in a date-time property is widened rather than refused.
	 *
	 * @return void
	 */
	public function testABareDateInADateTimePropertyIsWidened(): void {
		$out = $this->subject()->call(self::PROPERTIES, ['deadline' => '2026-02-16']);

		$this->assertSame('2026-02-16T00:00:00+00:00', $out['deadline']);
	}//end testABareDateInADateTimePropertyIsWidened()

	/**
	 * 🔴 THE REGRESSION GUARD. A key the caller already resolved is left alone.
	 *
	 * Without this, a second pass turned `uuid-of-gemeenteraad` into
	 * `uuid-of-uuid-of-gemeenteraad`. In production the first pass yields a real
	 * uuid, which resolveReference() returns untouched, so the damage was
	 * invisible there and only the unit tests' fake ids exposed it.
	 *
	 * @return void
	 */
	public function testAKeyTheCallerAlreadyResolvedIsNotResolvedAgain(): void {
		$out = $this->subject()->call(
			self::PROPERTIES,
			['goal' => 'already-a-resolved-id'],
			['goal']
		);

		$this->assertSame(
			'already-a-resolved-id',
			$out['goal'],
			'A key named in alreadyResolved must survive untouched'
		);
	}//end testAKeyTheCallerAlreadyResolvedIsNotResolvedAgain()

	/**
	 * Values the target says nothing about, or cannot resolve, are left alone.
	 *
	 * `orphan` declares `format: uuid` with no `$ref`, so there is nothing to
	 * resolve against and guessing would be worse than leaving it.
	 *
	 * @return void
	 */
	public function testWhatTheTargetDoesNotDescribeIsLeftAlone(): void {
		$out = $this->subject()->call(
			self::PROPERTIES,
			['subject' => 'a plain string', 'orphan' => 'some-slug', 'undeclared' => 'x']
		);

		$this->assertSame('a plain string', $out['subject']);
		$this->assertSame('some-slug', $out['orphan'], 'format:uuid with no $ref has nothing to resolve against');
		$this->assertSame('x', $out['undeclared']);
	}//end testWhatTheTargetDoesNotDescribeIsLeftAlone()
}//end class
