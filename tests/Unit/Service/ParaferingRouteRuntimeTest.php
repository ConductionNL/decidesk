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

use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\ApprovalRouteStepMapper;
use OCA\Decidiq\Service\ApprovalStageGuard;
use OCA\Decidiq\Service\MandateDirectory;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the parafering runtime the engine absorbed from dossiq.
 *
 * Every test here is a guard someone could delete without any other suite
 * noticing, and each has been mutation-checked by hand: dropping the stage
 * vocabulary map, the delegate mandate check, the terminal-return conclusion
 * or the parallel-group hold each turns at least one of these red. A
 * mandate/onBehalfOf check that vanishes is an open door, and an open door
 * that stays green is the failure mode this file exists to prevent.
 */
class ParaferingRouteRuntimeTest extends TestCase {
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
			public array $rows = [
				'decision-stage' => [],
				'approval-action' => [],
				'bevoegdheidstoedeling' => [],
			];

			/**
			 * Required properties per schema, mirroring lib/Settings — the fake
			 * refuses a write exactly where live OpenRegister does.
			 *
			 * @var array<string, array<int, string>>
			 */
			private const REQUIRED = [
				'decision-stage' => ['sequence', 'stageType', 'status', 'decisionMakerType', 'label'],
				'approval-action' => ['subject', 'step', 'actor', 'action'],
				'bevoegdheidstoedeling' => ['type', 'subject', 'decision', 'validFrom', 'status'],
			];

			/**
			 * @var int
			 */
			private int $counter = 0;

			/**
			 * Create or fully replace a row, validating as live OR does.
			 *
			 * @param array<string, mixed> $object The COMPLETE object.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				if ($uuid === null) {
					$this->counter++;
					$uuid = $schema . '-' . $this->counter;
				}

				// A uuid-bearing save REPLACES and validates whole, as live
				// OpenRegister does — a partial payload must refuse here the way
				// it 400s on the rig, or this fake cannot fail.
				$this->assertRequired(schema: $schema, object: $object);
				$this->rows[$schema][$uuid] = ($object + ['id' => $uuid]);

				return $this->rows[$schema][$uuid];
			}

			/**
			 * Merge a partial payload, as OR's patchObject() does (RFC 7386
			 * shaped): absent keys are preserved, an explicit null removes the
			 * key, and the MERGED result is validated against the schema.
			 *
			 * @param string $objectId The uuid.
			 * @param array<string, mixed> $data The partial data.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The patched row.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				$merged = ($this->rows[$schema][$objectId] ?? []);
				foreach ($data as $key => $value) {
					if ($value === null) {
						unset($merged[$key]);
						continue;
					}

					$merged[$key] = $value;
				}

				$this->assertRequired(schema: $schema, object: $merged);
				$this->rows[$schema][$objectId] = ($merged + ['id' => $objectId]);

				return $this->rows[$schema][$objectId];
			}

			/**
			 * Refuse a payload missing required schema properties.
			 *
			 * @param string $schema The schema.
			 * @param array<string, mixed> $object The payload to validate.
			 *
			 * @return void
			 *
			 * @throws RuntimeException When required properties are missing.
			 */
			private function assertRequired(string $schema, array $object): void {
				$missing = [];
				foreach ((self::REQUIRED[$schema] ?? []) as $property) {
					if (array_key_exists($property, $object) === false) {
						$missing[] = $property;
					}
				}

				if ($missing !== []) {
					throw new RuntimeException('required properties (' . implode(', ', $missing) . ') are missing');
				}
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
	}

	/**
	 * The service under test, backed by the stateful fake.
	 *
	 * @return ApprovalRouteService The service.
	 */
	private function service(): ApprovalRouteService {
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
		$facade->method('patchObject')->willReturnCallback(
			function (
				string $objectId,
				array $data,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use ($state): ObjectEntityInterface {
				$row = $state->patchObject($objectId, $data, (string)$register, (string)$schema);
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);
		$facade->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => $state->findAll($config)
		);
		// find() resolves by uuid, as live OpenRegister does — the resolving
		// form a top-level 'id' filter never was.
		$facade->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use ($state): ?ObjectEntityInterface {
				$row = ($state->rows[(string)$schema][(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);

		$store = new RegisterObjectStore($facade);

		return new ApprovalRouteService($store, new ApprovalStageGuard(new MandateDirectory($store)), new ApprovalRouteStepMapper());
	}

	/**
	 * A parafering-shaped route: advies, paraaf (alice), accordering (carol).
	 *
	 * @return array<string, mixed> The route.
	 */
	private function route(): array {
		return [
			'id' => 'route-77',
			'steps' => [
				['order' => 1, 'stageType' => 'advisory', 'actorType' => 'role', 'actor' => 'adviseur', 'label' => 'Advies'],
				['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice', 'label' => 'Parafering'],
				['order' => 3, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'carol', 'label' => 'Accordering'],
			],
		];
	}

	/**
	 * The stage statuses, in sequence order.
	 *
	 * @return array<int, string> The statuses.
	 */
	private function statuses(): array {
		$rows = array_values($this->objectService->rows['decision-stage']);
		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));

		return array_map(static fn (array $s): string => (string)$s['status'], $rows);
	}

	/**
	 * Advance past the advisory stage.
	 *
	 * @param ApprovalRouteService $service The engine.
	 *
	 * @return void
	 */
	private function passAdvies(ApprovalRouteService $service): void {
		$service->record(['subject' => 'v-1', 'actor' => 'erik', 'action' => 'advised', 'advice' => 'Akkoord']);
	}

	/**
	 * The stage vocabulary: an approved on an advisory stage is refused.
	 *
	 * @return void
	 */
	public function testAnApprovalOnAnAdvisoryStageIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');

		try {
			$service->record(['subject' => 'v-1', 'actor' => 'erik', 'action' => 'approved']);
			$this->fail('An advisory stage must not accept approved.');
		} catch (RuntimeException) {
			$this->assertSame([], $this->objectService->rows['approval-action'], 'The refused verb must not be recorded.');
		}
	}

	/**
	 * A return without a reason is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAReturnWithoutAReasonIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');

		try {
			$service->record(['subject' => 'v-1', 'actor' => 'erik', 'action' => 'returned']);
			$this->fail('A return without a reason must be refused.');
		} catch (RuntimeException) {
			$this->assertSame([], $this->objectService->rows['approval-action']);
			$this->assertSame(['active', 'pending', 'pending'], $this->statuses());
		}
	}

	/**
	 * An advisory sign-off without the advice text is refused.
	 *
	 * @return void
	 */
	public function testAnAdviceLessAdvisorySignOffIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');

		$this->expectException(RuntimeException::class);
		$service->record(['subject' => 'v-1', 'actor' => 'erik', 'action' => 'advised']);
	}

	/**
	 * A mandated delegate signs the stage their principal is named on.
	 *
	 * The mandate reference resolves to nothing locally, which is the
	 * cross-app shape: dossiq's mandateringsbesluit rows live in dossiq's
	 * register, and the reference travels verbatim onto the record.
	 *
	 * @return void
	 */
	public function testAMandatedDelegateMaySign(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');
		$this->passAdvies($service);

		$service->record([
			'subject' => 'v-1',
			'actor' => 'bob',
			'action' => 'endorsed',
			'actorType' => 'delegate',
			'onBehalfOf' => 'alice',
			'mandate' => 'dossiq-mandaat-14',
		]);

		$this->assertSame(['decided', 'decided', 'active'], $this->statuses());
		$actions = array_values($this->objectService->rows['approval-action']);
		$this->assertSame('bob', $actions[1]['actor']);
		$this->assertSame('alice', $actions[1]['onBehalfOf']);
		$this->assertSame('dossiq-mandaat-14', $actions[1]['mandate']);
	}

	/**
	 * A delegate whose principal is not the assignee is refused.
	 *
	 * @return void
	 */
	public function testADelegateForTheWrongPrincipalIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');
		$this->passAdvies($service);

		$this->expectException(RuntimeException::class);
		$service->record([
			'subject' => 'v-1',
			'actor' => 'bob',
			'action' => 'endorsed',
			'onBehalfOf' => 'dave',
			'mandate' => 'dossiq-mandaat-14',
		]);
	}

	/**
	 * A delegate without a mandate reference is refused.
	 *
	 * @return void
	 */
	public function testADelegateWithoutAMandateIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');
		$this->passAdvies($service);

		$this->expectException(RuntimeException::class);
		$service->record([
			'subject' => 'v-1',
			'actor' => 'bob',
			'action' => 'endorsed',
			'onBehalfOf' => 'alice',
		]);
	}

	/**
	 * A LOCAL mandate that is not effective refuses the delegate.
	 *
	 * Resolvable-and-wrong is a refusal: the register knows this mandate and
	 * the register says it grants nothing.
	 *
	 * @return void
	 */
	public function testAWithdrawnLocalMandateRefusesTheDelegate(): void {
		$this->objectService->rows['bevoegdheidstoedeling']['toedeling-1'] = [
			'id' => 'toedeling-1',
			'status' => 'withdrawn',
			'delegatePerson' => 'bob',
		];

		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');
		$this->passAdvies($service);

		$this->expectException(RuntimeException::class);
		$service->record([
			'subject' => 'v-1',
			'actor' => 'bob',
			'action' => 'endorsed',
			'onBehalfOf' => 'alice',
			'mandate' => 'toedeling-1',
		]);
	}

	/**
	 * A LOCAL mandate naming a different delegate refuses this one.
	 *
	 * @return void
	 */
	public function testALocalMandateForSomeoneElseRefusesTheDelegate(): void {
		$this->objectService->rows['bevoegdheidstoedeling']['toedeling-1'] = [
			'id' => 'toedeling-1',
			'status' => 'effective',
			'delegatePerson' => 'mallory',
		];

		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');
		$this->passAdvies($service);

		$this->expectException(RuntimeException::class);
		$service->record([
			'subject' => 'v-1',
			'actor' => 'bob',
			'action' => 'endorsed',
			'onBehalfOf' => 'alice',
			'mandate' => 'toedeling-1',
		]);
	}

	/**
	 * An effective, in-window LOCAL mandate naming the delegate passes.
	 *
	 * @return void
	 */
	public function testAnEffectiveLocalMandatePassesTheDelegate(): void {
		$this->objectService->rows['bevoegdheidstoedeling']['toedeling-1'] = [
			'id' => 'toedeling-1',
			'status' => 'effective',
			'delegatePerson' => 'bob',
			'validFrom' => '2020-01-01',
		];

		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');
		$this->passAdvies($service);

		$service->record([
			'subject' => 'v-1',
			'actor' => 'bob',
			'action' => 'endorsed',
			'onBehalfOf' => 'alice',
			'mandate' => 'toedeling-1',
		]);

		$this->assertSame(['decided', 'decided', 'active'], $this->statuses());
	}

	/**
	 * An expired LOCAL mandate refuses the delegate.
	 *
	 * @return void
	 */
	public function testAnExpiredLocalMandateRefusesTheDelegate(): void {
		$this->objectService->rows['bevoegdheidstoedeling']['toedeling-1'] = [
			'id' => 'toedeling-1',
			'status' => 'effective',
			'delegatePerson' => 'bob',
			'validTo' => '2021-01-01',
		];

		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');
		$this->passAdvies($service);

		$this->expectException(RuntimeException::class);
		$service->record([
			'subject' => 'v-1',
			'actor' => 'bob',
			'action' => 'endorsed',
			'onBehalfOf' => 'alice',
			'mandate' => 'toedeling-1',
		]);
	}

	/**
	 * A return naming no step concludes the route back to its sender.
	 *
	 * The addressed stage records `returned`; the never-reached stages go back
	 * to pending, NOT skipped — nobody chose to skip them. No stage stays
	 * active, and a further action is refused: the route is over.
	 *
	 * @return void
	 */
	public function testATerminalReturnConcludesTheRoute(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');
		$this->passAdvies($service);

		$service->record([
			'subject' => 'v-1',
			'actor' => 'alice',
			'action' => 'returned',
			'comment' => 'Stuk is onvolledig; terug naar de steller.',
		]);

		$this->assertSame(['decided', 'decided', 'pending'], $this->statuses());
		$this->assertNotContains('active', $this->statuses());

		$rows = array_values($this->objectService->rows['decision-stage']);
		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));
		$this->assertSame('returned', $rows[1]['outcome']);
		$this->assertArrayNotHasKey('outcome', array_filter($rows[2], static fn ($v) => $v !== null), 'A never-reached stage carries no outcome.');

		$this->expectException(RuntimeException::class);
		$service->record(['subject' => 'v-1', 'actor' => 'carol', 'action' => 'approved']);
	}

	/**
	 * Two steps declaring the same order sign side by side.
	 *
	 * Both are active at once; the group advances only when its LAST live
	 * member completes, and each actor's action lands on their own stage.
	 *
	 * @return void
	 */
	public function testParallelStepsSignSideBySide(): void {
		$service = $this->service();
		$service->instantiate(
			route: [
				'id' => 'route-78',
				'steps' => [
					['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice', 'label' => 'Paraaf A'],
					['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'bob', 'label' => 'Paraaf B'],
					['order' => 2, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'carol', 'label' => 'Accordering'],
				],
			],
			subject: 'v-1',
			subjectSchema: 'proposal',
		);

		$this->assertSame(['active', 'active', 'pending'], $this->statuses());

		$service->record(['subject' => 'v-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$this->assertSame(
			['decided', 'active', 'pending'],
			$this->statuses(),
			'One signature does not advance a group whose sibling still signs.'
		);

		$service->record(['subject' => 'v-1', 'actor' => 'bob', 'action' => 'endorsed']);
		$this->assertSame(['decided', 'decided', 'active'], $this->statuses());

		$actions = array_values($this->objectService->rows['approval-action']);
		$this->assertSame(['alice', 'bob'], [(string)$actions[0]['actor'], (string)$actions[1]['actor']]);
	}

	/**
	 * A terminal return from inside a parallel group stops the siblings too.
	 *
	 * @return void
	 */
	public function testATerminalReturnStopsTheParallelSiblings(): void {
		$service = $this->service();
		$service->instantiate(
			route: [
				'steps' => [
					['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice'],
					['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'bob'],
					['order' => 2, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'carol'],
				],
			],
			subject: 'v-1',
			subjectSchema: 'proposal',
		);

		$service->record(['subject' => 'v-1', 'actor' => 'alice', 'action' => 'returned', 'comment' => 'Terug.']);

		$this->assertNotContains('active', $this->statuses(), 'A returned route asks nobody else.');
	}

	/**
	 * A stage's sequence is the route step's OWN order.
	 *
	 * A route numbered 10 and 20 projects those numbers, because the
	 * parafering surfaces read the step number and it must mean what the
	 * route meant.
	 *
	 * @return void
	 */
	public function testTheSequenceIsTheStepsOwnOrder(): void {
		$this->service()->instantiate(
			route: [
				'steps' => [
					['order' => 10, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice'],
					['order' => 20, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'carol'],
				],
			],
			subject: 'v-1',
			subjectSchema: 'proposal',
		);

		$rows = array_values($this->objectService->rows['decision-stage']);
		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));
		$this->assertSame([10, 20], array_map(static fn (array $s): int => (int)$s['sequence'], $rows));
		$this->assertSame(['active', 'pending'], $this->statuses());
	}

	/**
	 * A stage back-references the route it was instantiated from.
	 *
	 * That reference is what lets a conclusion announced from ANY path
	 * resolve the producer's provenance pair.
	 *
	 * @return void
	 */
	public function testStagesCarryTheirRoute(): void {
		$this->service()->instantiate(route: $this->route(), subject: 'v-1', subjectSchema: 'proposal');

		foreach ($this->objectService->rows['decision-stage'] as $stage) {
			$this->assertSame('route-77', $stage['route']);
		}
	}
}
