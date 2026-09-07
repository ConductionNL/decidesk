<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MandateDirectory;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the mandate judgement's edges the engine tests do not reach.
 *
 * The engine-level tests (ParaferingRouteRuntimeTest) already prove a
 * withdrawn, expired or misassigned LOCAL mandate refuses a delegate through
 * record(); this file pins the directory's own boundary decisions — the
 * not-yet-in-force window, the unreadable date, and the deliberate pass for a
 * reference this register cannot resolve.
 */
class MandateDirectoryTest extends TestCase {

	/**
	 * A directory over the given toedeling rows.
	 *
	 * The store fake answers `find()` BY UUID, the way live OpenRegister
	 * resolves an object — and `findAll()` with a top-level 'id' filter
	 * answers nothing, because live OR matches such a filter against the
	 * object's own JSON properties where no id lives. The previous fake
	 * handed the canned rows back for that dead filter form, so it agreed
	 * with the caller's bug and could not fail (dossiq#1686's class).
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 *
	 * @return MandateDirectory The directory.
	 */
	private function directory(array $rows): MandateDirectory {
		$store = $this->createMock(RegisterObjectStore::class);
		$store->method('find')->willReturnCallback(
			static function (string $schema, string $uuid) use ($rows): ?array {
				foreach ($rows as $row) {
					if ((string)($row['id'] ?? '') === $uuid) {
						return $row;
					}
				}

				return null;
			}
		);
		// The dead filter form answers nothing, exactly like live OR: a
		// top-level 'id'/'uuid' filter runs against the object's own JSON
		// properties, where no identity lives.
		$store->method('findAll')->willReturn([]);

		return new MandateDirectory($store);
	}

	/**
	 * An unresolvable reference is the producer's mandate and passes.
	 *
	 * @return void
	 */
	public function testAnUnresolvableReferencePasses(): void {
		$this->directory(rows: [])->assertMayActUnder(mandate: 'dossiq-mandaat-14', actor: 'bob');

		$this->addToAssertionCount(1);
	}

	/**
	 * A mandate whose validity has not begun refuses.
	 *
	 * @return void
	 */
	public function testANotYetEffectiveWindowRefuses(): void {
		$row = [
			'id' => 'm-1',
			'status' => 'effective',
			'delegatePerson' => 'bob',
			'validFrom' => '2999-01-01',
		];

		$this->expectException(RuntimeException::class);
		$this->directory(rows: [$row])->assertMayActUnder(mandate: 'm-1', actor: 'bob');
	}

	/**
	 * An unreadable validity date refuses rather than passes.
	 *
	 * A window that cannot be read is not evidence of validity.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateRefuses(): void {
		$row = [
			'id' => 'm-1',
			'status' => 'effective',
			'delegatePerson' => 'bob',
			'validFrom' => 'not-a-date-at-all-#',
		];

		$this->expectException(RuntimeException::class);
		$this->directory(rows: [$row])->assertMayActUnder(mandate: 'm-1', actor: 'bob');
	}

	/**
	 * An unrelated row is never handed back as this reference's mandate.
	 *
	 * The directory resolves by uuid through find(), so a register holding
	 * only somebody else's toedeling answers null for this reference — an
	 * external reference, which passes; the withdrawn row is not it.
	 *
	 * @return void
	 */
	public function testAnUnrelatedRowIsNotThisMandate(): void {
		$row = [
			'id' => 'some-other-mandate',
			'status' => 'withdrawn',
			'delegatePerson' => 'bob',
		];

		$this->directory(rows: [$row])->assertMayActUnder(mandate: 'm-1', actor: 'bob');

		$this->addToAssertionCount(1);
	}
}
