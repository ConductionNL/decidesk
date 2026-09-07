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

use OCA\Decidiq\Service\ApprovalRouteCommandService;
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
 * Covers the cross-app approval-route command seam.
 *
 * The load-bearing test here is testSeamAndServiceCannotDiverge. Everything else
 * checks that the seam does its own small job; that one checks it did NOT grow a
 * second engine, which is the failure this design exists to avoid and the one
 * that would show up as the REST path and the event path disagreeing about the
 * same sign-off months later.
 */
class ApprovalRouteCommandServiceTest extends TestCase {

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
			public array $rows = ['decision-stage' => [], 'approval-action' => [], 'approval-route' => []];

			/**
			 * Required properties per schema, mirroring lib/Settings — the fake
			 * refuses a write exactly where live OpenRegister does.
			 *
			 * @var array<string, array<int, string>>
			 */
			private const REQUIRED = [
				'decision-stage' => ['sequence', 'stageType', 'status', 'decisionMakerType', 'label'],
				'approval-action' => ['subject', 'step', 'actor', 'action'],
				'approval-route' => ['name', 'steps'],
			];

			/**
			 * Uuid counter.
			 *
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

	}//end setUp()

	/**
	 * A store over the stateful fake.
	 *
	 * @return RegisterObjectStore The store.
	 */
	private function store(): RegisterObjectStore {
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

		return new RegisterObjectStore($facade);

	}//end store()

	/**
	 * The engine, over the same fake.
	 *
	 * @return ApprovalRouteService The engine.
	 */
	private function engine(): ApprovalRouteService {
		return new ApprovalRouteService($this->store(), new ApprovalStageGuard(new MandateDirectory($this->store())), new ApprovalRouteStepMapper());

	}//end engine()

	/**
	 * The service under test.
	 *
	 * @param ApprovalRouteService|null $engine An engine to share, or null for a fresh one.
	 *
	 * @return ApprovalRouteCommandService The service.
	 */
	private function service(?ApprovalRouteService $engine = null): ApprovalRouteCommandService {
		return new ApprovalRouteCommandService($this->store(), ($engine ?? $this->engine()));

	}//end service()

	/**
	 * A three-step template.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function template(): array {
		return [
			'name' => 'Collegeadvies parafering',
			'subjectType' => 'collegeadvies',
			'isDefault' => true,
			'steps' => [
				['order' => 1, 'stageType' => 'advisory', 'actorType' => 'role', 'actor' => 'adviseur', 'label' => 'Advies'],
				['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice', 'label' => 'Parafering'],
				['order' => 3, 'stageType' => 'decisive', 'actorType' => 'role', 'actor' => 'secretaris', 'label' => 'Accordering'],
			],
		];

	}//end template()

	/**
	 * The subject's stages, ordered.
	 *
	 * @param string $subject The subject.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function stagesOf(string $subject): array {
		$rows = [];
		foreach ($this->objectService->rows['decision-stage'] as $row) {
			if (($row['decision'] ?? null) === $subject) {
				$rows[] = $row;
			}
		}

		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));

		return $rows;

	}//end stagesOf()

	/**
	 * REQ-ARE-002: a command holds a route and answers with its id.
	 *
	 * @return void
	 */
	public function testCommandHoldsRouteAndReportsId(): void {
		$result = $this->service()->holdRoute('dossiq', 'pr-1', $this->template());

		$this->assertTrue($result['created']);
		$this->assertNotSame('', $result['id']);
		$this->assertCount(1, $this->objectService->rows['approval-route']);

		$stored = $this->objectService->rows['approval-route'][$result['id']];
		$this->assertSame('dossiq', $stored['sourceApp']);
		$this->assertSame('pr-1', $stored['externalReference']);
		$this->assertSame('collegeadvies', $stored['subjectType']);
		$this->assertTrue($stored['isDefault']);
		$this->assertCount(3, $stored['steps']);

	}//end testCommandHoldsRouteAndReportsId()

	/**
	 * REQ-ARE-002: a repeated command updates rather than duplicating.
	 *
	 * @return void
	 */
	public function testRepeatedCommandUpdatesOneRoute(): void {
		$service = $this->service();
		$first = $service->holdRoute('dossiq', 'pr-1', $this->template());
		$second = $service->holdRoute('dossiq', 'pr-1', $this->template());

		$this->assertCount(1, $this->objectService->rows['approval-route']);
		$this->assertSame($first['id'], $second['id']);
		$this->assertTrue($first['created']);
		$this->assertFalse($second['created']);

	}//end testRepeatedCommandUpdatesOneRoute()

	/**
	 * REQ-ARE-002: a route with no steps is refused before anything is written.
	 *
	 * @return void
	 */
	public function testRouteWithNoStepsIsRefused(): void {
		$template = $this->template();
		$template['steps'] = [];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/no steps/');

		try {
			$this->service()->holdRoute('dossiq', 'pr-1', $template);
		} finally {
			$this->assertCount(0, $this->objectService->rows['approval-route']);
		}

	}//end testRouteWithNoStepsIsRefused()

	/**
	 * REQ-ARE-002: the provenance pair is required.
	 *
	 * @return void
	 */
	public function testMissingProvenanceIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/sourceApp and externalReference/');

		$this->service()->holdRoute('', 'pr-1', $this->template());

	}//end testMissingProvenanceIsRefused()

	/**
	 * REQ-ARE-003: naming a subject materialises its stages.
	 *
	 * @return void
	 */
	public function testNamingASubjectMaterialisesStages(): void {
		$result = $this->service()->holdRoute('dossiq', 'pr-1', $this->template(), 'voorstel-1', 'proposal');

		$this->assertSame(3, $result['stageCount']);

		$statuses = array_map(
			static fn (array $s): string => (string)$s['status'],
			$this->stagesOf('voorstel-1')
		);
		$this->assertSame(['active', 'pending', 'pending'], $statuses);

	}//end testNamingASubjectMaterialisesStages()

	/**
	 * REQ-ARE-003: instantiating twice does not double the stages.
	 *
	 * @return void
	 */
	public function testInstantiatingTwiceDoesNotDoubleStages(): void {
		$engine = $this->engine();
		$service = $this->service($engine);
		$service->holdRoute('dossiq', 'pr-1', $this->template(), 'voorstel-1', 'proposal');
		$service->holdRoute('dossiq', 'pr-1', $this->template(), 'voorstel-1', 'proposal');

		$this->assertCount(3, $this->stagesOf('voorstel-1'));

	}//end testInstantiatingTwiceDoesNotDoubleStages()

	/**
	 * REQ-ARE-003: an edited template does not rewrite a sign-off in flight.
	 *
	 * @return void
	 */
	public function testEditedTemplateDoesNotRewriteASignOffInFlight(): void {
		$engine = $this->engine();
		$service = $this->service($engine);
		$service->holdRoute('dossiq', 'pr-1', $this->template(), 'voorstel-1', 'proposal');

		$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'anyone', 'action' => 'advised', 'advice' => 'Akkoord']);

		$grown = $this->template();
		$grown['steps'][] = ['order' => 4, 'stageType' => 'ratifying', 'actorType' => 'role', 'actor' => 'college', 'label' => 'Vaststelling'];
		$service->holdRoute('dossiq', 'pr-1', $grown);

		$stages = $this->stagesOf('voorstel-1');
		$this->assertCount(3, $stages, 'stages are materialised at instantiation, not re-read from the template');
		$this->assertSame('advised', (string)$stages[0]['outcome']);

	}//end testEditedTemplateDoesNotRewriteASignOffInFlight()

	/**
	 * REQ-ARE-004: an approval advances the route, the last one completes it.
	 *
	 * @return void
	 */
	public function testApprovalsAdvanceAndThenComplete(): void {
		$engine = $this->engine();
		$service = $this->service($engine);
		$service->holdRoute('dossiq', 'pr-1', $this->template(), 'voorstel-1', 'proposal');

		$first = $service->recordAction(['subject' => 'voorstel-1', 'actor' => 'anyone', 'action' => 'advised', 'advice' => 'Akkoord']);
		$this->assertTrue($first['recorded']);
		$this->assertFalse($first['completed']);

		$statuses = array_map(static fn (array $s): string => (string)$s['status'], $this->stagesOf('voorstel-1'));
		$this->assertSame(['decided', 'active', 'pending'], $statuses);

		$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$last = $service->recordAction(['subject' => 'voorstel-1', 'actor' => 'anyone', 'action' => 'approved']);

		$this->assertTrue($last['completed']);
		$statuses = array_map(static fn (array $s): string => (string)$s['status'], $this->stagesOf('voorstel-1'));
		$this->assertSame(['decided', 'decided', 'decided'], $statuses);

	}//end testApprovalsAdvanceAndThenComplete()

	/**
	 * REQ-ARE-004: an actor the stage does not name is refused by the engine.
	 *
	 * @return void
	 */
	public function testWrongActorIsRefusedByTheEngine(): void {
		$engine = $this->engine();
		$service = $this->service($engine);
		$service->holdRoute('dossiq', 'pr-1', $this->template(), 'voorstel-1', 'proposal');
		$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'anyone', 'action' => 'advised', 'advice' => 'Akkoord']);

		$before = count($this->objectService->rows['approval-action']);

		try {
			$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'mallory', 'action' => 'approved']);
			$this->fail('the engine should have refused an actor the stage does not name');
		} catch (RuntimeException $e) {
			$this->addToAssertionCount(1);
		}

		$this->assertCount($before, $this->objectService->rows['approval-action']);
		$statuses = array_map(static fn (array $s): string => (string)$s['status'], $this->stagesOf('voorstel-1'));
		$this->assertSame(['decided', 'active', 'pending'], $statuses);

	}//end testWrongActorIsRefusedByTheEngine()

	/**
	 * REQ-ARE-004: a return travels through the same engine.
	 *
	 * @return void
	 */
	public function testReturnGoesThroughTheEngine(): void {
		$engine = $this->engine();
		$service = $this->service($engine);
		$service->holdRoute('dossiq', 'pr-1', $this->template(), 'voorstel-1', 'proposal');
		$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'anyone', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'alice', 'action' => 'endorsed']);

		$service->recordAction([
			'subject' => 'voorstel-1',
			'actor' => 'anyone',
			'action' => 'returned',
			'returnToStep' => 2,
			'comment' => 'Onvolledig',
		]);

		$stages = $this->stagesOf('voorstel-1');
		$statuses = array_map(static fn (array $s): string => (string)$s['status'], $stages);
		$this->assertSame(['decided', 'active', 'pending'], $statuses);
		$this->assertSame('', (string)($stages[1]['outcome'] ?? ''));

	}//end testReturnGoesThroughTheEngine()

	/**
	 * REQ-ARE-005: the seam and the service produce the same state.
	 *
	 * @return void
	 */
	public function testSeamAndServiceCannotDiverge(): void {
		$engine = $this->engine();
		$service = $this->service($engine);

		$engine->instantiate(route: $this->template(), subject: 'via-service', subjectSchema: 'proposal');
		$service->holdRoute('dossiq', 'pr-1', $this->template(), 'via-seam', 'proposal');

		$engine->record(['subject' => 'via-service', 'actor' => 'anyone', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->recordAction(['subject' => 'via-seam', 'actor' => 'anyone', 'action' => 'advised', 'advice' => 'Akkoord']);

		$strip = static function (array $stages): array {
			return array_map(
				static function (array $s): array {
					// `route` and `taskUuid` are provenance LINKAGE, not route
					// state: the direct path instantiates from a bare template
					// (no stored id to link) where the seam links the stored
					// row. Stripping them keeps this test about what REQ-ARE-005
					// promises — the travelling state cannot diverge.
					unset($s['id'], $s['decision'], $s['route'], $s['taskUuid']);

					// `decidedAt` is a WALL CLOCK reading, stamped independently
					// by each path. The two calls above run microseconds apart,
					// so whenever they straddle a second boundary the arrays
					// differ by exactly one second and this test fails for a
					// reason that has nothing to do with divergence. Observed
					// 2026-09-03: 11:30:08 against 11:30:09.
					//
					// It is NORMALISED rather than unset, because whether a
					// decided stage records a decision time at all is part of
					// what must not diverge. Both paths still have to agree that
					// there IS one; they no longer have to agree on the second.
					if (isset($s['decidedAt']) === true && $s['decidedAt'] !== '') {
						$s['decidedAt'] = '<recorded>';
					}

					return $s;
				},
				$stages
			);
		};

		$this->assertEquals(
			$strip($this->stagesOf('via-service')),
			$strip($this->stagesOf('via-seam')),
			'the event path and the direct path must agree about the same action'
		);

	}//end testSeamAndServiceCannotDiverge()

	/**
	 * The final outcome is the one the last decided stage recorded.
	 *
	 * @return void
	 */
	public function testFinalOutcomeIsTheLastDecidedStages(): void {
		$engine = $this->engine();
		$service = $this->service($engine);
		$service->holdRoute('dossiq', 'pr-1', $this->template(), 'voorstel-1', 'proposal');
		$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'anyone', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->recordAction(['subject' => 'voorstel-1', 'actor' => 'anyone', 'action' => 'approved']);

		$this->assertSame('approved', $service->finalOutcomeOf('voorstel-1'));

	}//end testFinalOutcomeIsTheLastDecidedStages()

}//end class
