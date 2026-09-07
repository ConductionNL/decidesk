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

use OCA\Decidiq\Service\GovernanceBodyCommandService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the cross-app governance-body command engine.
 *
 * Every idempotency assertion here calls the seam TWICE and then COUNTS rows.
 * A test that calls it once cannot tell an idempotent write from a duplicating
 * one — it sees a body either way — which is exactly how a fan-out migration
 * ships looking correct and mints a second Person per member on the re-run.
 */
class GovernanceBodyCommandServiceTest extends TestCase {

	/**
	 * In-memory OpenRegister stand-in.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build the fake register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new class {
			/**
			 * Stored rows, keyed by schema then uuid.
			 *
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $rows = ['governance-body' => [], 'person' => [], 'membership' => []];

			/**
			 * Schemas whose next write must throw, keyed by schema slug.
			 *
			 * @var array<string, int>
			 */
			public array $failWriteAt = [];

			/**
			 * Writes seen per schema.
			 *
			 * @var array<string, int>
			 */
			public array $writes = [];

			/**
			 * Uuid counter.
			 *
			 * @var int
			 */
			private int $counter = 0;

			/**
			 * Create or patch a row.
			 *
			 * @param array<string, mixed> $object The object or patch.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->writes[$schema] = (($this->writes[$schema] ?? 0) + 1);
				if (($this->failWriteAt[$schema] ?? null) === $this->writes[$schema]) {
					throw new RuntimeException('simulated write failure on ' . $schema);
				}

				if ($uuid === null) {
					$this->counter++;
					$uuid = $schema . '-' . $this->counter;
					$this->rows[$schema][$uuid] = ($object + ['id' => $uuid]);

					return $this->rows[$schema][$uuid];
				}

				$this->rows[$schema][$uuid] = (array_merge(($this->rows[$schema][$uuid] ?? []), $object) + ['id' => $uuid]);

				return $this->rows[$schema][$uuid];
			}

			/**
			 * Filter the stored rows.
			 *
			 * @param array<string, mixed> $config The query config.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);

				if (isset($filters['id']) === true || isset($filters['uuid']) === true) {
					// Live OpenRegister matches filters against the object's own
					// JSON properties; identity lives in @self, so a top-level
					// id/uuid filter matches NOTHING. A fake resolving it would
					// agree with the caller's bug and could not fail (dossiq#1686).
					return [];
				}

				$out = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					$matches = true;
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						$out[] = $row;
					}
				}

				return $out;
			}
		};

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return GovernanceBodyCommandService The service.
	 */
	private function service(): GovernanceBodyCommandService {
		$state = $this->objectService;
		$facade = $this->createMock(ObjectServiceInterface::class);
		$facade->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use ($state): ObjectEntityInterface {
				$row = $state->saveObject($object, (string)$register, (string)$schema, $uuid);

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);
		$facade->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => $state->findAll($config)
		);

		return new GovernanceBodyCommandService(new RegisterObjectStore($facade));

	}//end service()

	/**
	 * A committee command body.
	 *
	 * @param boolean $active The active flag.
	 *
	 * @return array<string, mixed> The body fields.
	 */
	private function body(bool $active = true): array {
		return [
			'name' => 'Bezwaarcommissie sociaal domein',
			'bodyType' => 'advisory-body',
			'domain' => 'social_domain',
			'active' => $active,
			'quorum' => 3,
			'statutoryBasis' => 'Awb 7:13',
		];

	}//end body()

	/**
	 * A three-seat roster.
	 *
	 * @param string $aliceRole Alice's role.
	 *
	 * @return array<int, array<string, mixed>> The roster.
	 */
	private function roster(string $aliceRole = 'chair'): array {
		return [
			['uid' => 'alice', 'role' => $aliceRole],
			['uid' => 'bob', 'role' => 'member'],
			['uid' => 'carol', 'role' => 'secretary', 'external' => true],
		];

	}//end roster()

	/**
	 * Count stored rows of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return int The count.
	 */
	private function countRows(string $schema): int {
		return count($this->objectService->rows[$schema]);

	}//end countRows()

	/**
	 * REQ-GBE-002: a command raises a body and reports its id.
	 *
	 * @return void
	 */
	public function testCommandRaisesBodyAndReportsId(): void {
		$result = $this->service()->upsert('dossiq', 'cmte-1', $this->body(), $this->roster());

		$this->assertTrue($result['created']);
		$this->assertNotSame('', $result['id']);
		$this->assertSame(1, $this->countRows('governance-body'));

		$stored = $this->objectService->rows['governance-body'][$result['id']];
		$this->assertSame('dossiq', $stored['sourceApp']);
		$this->assertSame('cmte-1', $stored['externalReference']);
		$this->assertSame('Awb 7:13', $stored['statutoryBasis']);
		$this->assertSame(3, $stored['quorum']);

	}//end testCommandRaisesBodyAndReportsId()

	/**
	 * REQ-GBE-003: a second identical command creates one body, not two.
	 *
	 * @return void
	 */
	public function testSecondIdenticalCommandCreatesOneBody(): void {
		$service = $this->service();
		$first = $service->upsert('dossiq', 'cmte-1', $this->body(), $this->roster());
		$second = $service->upsert('dossiq', 'cmte-1', $this->body(), $this->roster());

		$this->assertSame(1, $this->countRows('governance-body'));
		$this->assertSame($first['id'], $second['id']);
		$this->assertTrue($first['created']);
		$this->assertFalse($second['created']);

	}//end testSecondIdenticalCommandCreatesOneBody()

	/**
	 * REQ-GBE-003: the roster fan-out is idempotent too.
	 *
	 * @return void
	 */
	public function testSecondIdenticalCommandCreatesOneSeatPerMember(): void {
		$service = $this->service();
		$service->upsert('dossiq', 'cmte-1', $this->body(), $this->roster());
		$service->upsert('dossiq', 'cmte-1', $this->body(), $this->roster());

		$this->assertSame(3, $this->countRows('person'));
		$this->assertSame(3, $this->countRows('membership'));

	}//end testSecondIdenticalCommandCreatesOneSeatPerMember()

	/**
	 * REQ-GBE-003: a changed role updates the seat rather than adding one.
	 *
	 * @return void
	 */
	public function testChangedRoleUpdatesTheSeat(): void {
		$service = $this->service();
		$service->upsert('dossiq', 'cmte-1', $this->body(), $this->roster('member'));
		$service->upsert('dossiq', 'cmte-1', $this->body(), $this->roster('chair'));

		$this->assertSame(3, $this->countRows('membership'));

		$alice = null;
		foreach ($this->objectService->rows['person'] as $row) {
			if ($row['nextcloudUserId'] === 'alice') {
				$alice = $row['id'];
			}
		}

		$roles = [];
		foreach ($this->objectService->rows['membership'] as $row) {
			if ($row['person'] === $alice) {
				$roles[] = $row['role'];
			}
		}

		$this->assertSame(['chair'], $roles);

	}//end testChangedRoleUpdatesTheSeat()

	/**
	 * REQ-GBE-003: a different externalReference is a different body.
	 *
	 * @return void
	 */
	public function testDifferentReferenceIsADifferentBody(): void {
		$service = $this->service();
		$service->upsert('dossiq', 'cmte-1', $this->body(), []);
		$service->upsert('dossiq', 'cmte-2', $this->body(), []);

		$this->assertSame(2, $this->countRows('governance-body'));

	}//end testDifferentReferenceIsADifferentBody()

	/**
	 * REQ-GBE-004: a crash mid-fan-out leaves a body the next run completes.
	 *
	 * @return void
	 */
	public function testCrashMidRosterLeavesACompletableBody(): void {
		// Writes to `membership`: 1 = alice, 2 = bob. Fail on bob.
		$this->objectService->failWriteAt['membership'] = 2;

		$service = $this->service();
		try {
			$service->upsert('dossiq', 'cmte-1', $this->body(), $this->roster());
			$this->fail('the simulated write failure should have propagated');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('simulated write failure', $e->getMessage());
		}

		$this->assertSame(1, $this->countRows('governance-body'), 'the body is written before the roster');
		$this->assertSame(1, $this->countRows('membership'));

		$this->objectService->failWriteAt = [];
		$result = $service->upsert('dossiq', 'cmte-1', $this->body(), $this->roster());

		$this->assertFalse($result['created'], 'the re-run matches the half-written body');
		$this->assertSame(1, $this->countRows('governance-body'));
		$this->assertSame(3, $this->countRows('membership'));
		$this->assertSame(3, $this->countRows('person'));

	}//end testCrashMidRosterLeavesACompletableBody()

	/**
	 * REQ-GBE-005: an omitted `active` is refused, not defaulted.
	 *
	 * @return void
	 */
	public function testOmittedActiveIsRefused(): void {
		$body = $this->body();
		unset($body['active']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/never defaulted/');

		try {
			$this->service()->upsert('dossiq', 'cmte-1', $body, $this->roster());
		} finally {
			$this->assertSame(0, $this->countRows('governance-body'), 'nothing is written on a refusal');
		}

	}//end testOmittedActiveIsRefused()

	/**
	 * REQ-GBE-005: an archived committee stays archived across a re-run.
	 *
	 * @return void
	 */
	public function testArchivedBodyStaysArchivedAcrossRerun(): void {
		$service = $this->service();
		$first = $service->upsert('dossiq', 'cmte-1', $this->body(active: false), []);
		$service->upsert('dossiq', 'cmte-1', $this->body(active: false), []);

		$this->assertFalse($this->objectService->rows['governance-body'][$first['id']]['active']);

	}//end testArchivedBodyStaysArchivedAcrossRerun()

	/**
	 * A producer cannot overwrite the provenance pair through the attribute bag.
	 *
	 * @return void
	 */
	public function testProducerCannotOverwriteTheProvenancePair(): void {
		$body = ($this->body() + ['sourceApp' => 'evil', 'externalReference' => 'other']);
		$result = $this->service()->upsert('dossiq', 'cmte-1', $body, []);

		$stored = $this->objectService->rows['governance-body'][$result['id']];
		$this->assertSame('dossiq', $stored['sourceApp']);
		$this->assertSame('cmte-1', $stored['externalReference']);

	}//end testProducerCannotOverwriteTheProvenancePair()

	/**
	 * An unknown membership role is refused rather than stored.
	 *
	 * @return void
	 */
	public function testUnknownRoleIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Unknown membership role/');

		$this->service()->upsert(
			'dossiq',
			'cmte-1',
			$this->body(),
			[['uid' => 'alice', 'role' => 'grand-vizier']]
		);

	}//end testUnknownRoleIsRefused()

	/**
	 * A command without its provenance pair is refused.
	 *
	 * @return void
	 */
	public function testMissingProvenanceIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/sourceApp and externalReference/');

		$this->service()->upsert('', 'cmte-1', $this->body(), []);

	}//end testMissingProvenanceIsRefused()

	/**
	 * Awb 7:13(2): the secretary's external flag survives the fan-out.
	 *
	 * @return void
	 */
	public function testExternalFlagIsCarriedOntoTheMembership(): void {
		$this->service()->upsert('dossiq', 'cmte-1', $this->body(), $this->roster());

		$carol = null;
		foreach ($this->objectService->rows['person'] as $row) {
			if ($row['nextcloudUserId'] === 'carol') {
				$carol = $row['id'];
			}
		}

		$external = null;
		foreach ($this->objectService->rows['membership'] as $row) {
			if ($row['person'] === $carol) {
				$external = $row['external'];
			}
		}

		$this->assertTrue($external);

	}//end testExternalFlagIsCarriedOntoTheMembership()

}//end class
