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
use OCA\Decidiq\Service\DecisionStageLabelRepair;
use OCA\Decidiq\Service\MandateDirectory;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the approval-route engine.
 *
 * The assertions are deliberately about the ADVANCE and the REFUSALS rather
 * than about rows being written. A route engine that stores an action and does
 * not move is the exact failure this capability exists to replace: the
 * consuming app had one API whose every call returned 400 and one bridge that
 * short-circuited on a property nothing ever set, and both reported success.
 */
class ApprovalRouteServiceTest extends TestCase {
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
			 * Required properties per schema, mirroring lib/Settings — the fake
			 * refuses a write exactly where live OpenRegister does. The previous
			 * fake MERGED a uuid-bearing save, which live OR does not: it
			 * validates the payload as a FULL REPLACE and 400s on every missing
			 * required property. That fake could not fail, and the suite stayed
			 * green while every route advance 400'd in production.
			 *
			 * @var array<string, array<int, string>>
			 */
			private const REQUIRED = [
				'decision-stage' => ['sequence', 'stageType', 'status', 'decisionMakerType', 'label'],
				'approval-action' => ['subject', 'step', 'actor', 'action'],
				'bevoegdheidstoedeling' => ['type', 'subject', 'decision', 'validFrom', 'status'],
			];

			/**
			 * Stored rows, keyed by schema then uuid.
			 *
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $rows = ['decision-stage' => [], 'approval-action' => [], 'bevoegdheidstoedeling' => []];

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

				// A uuid-bearing save REPLACES, as live OpenRegister does, and
				// validates the payload whole — a partial payload must blow up
				// here the way it blows up on the rig, or this fake cannot fail.
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
	 * The service under test.
	 *
	 * @return ApprovalRouteService The service.
	 */
	private function service(): ApprovalRouteService {
		$store = $this->store();

		return new ApprovalRouteService($store, new ApprovalStageGuard(new MandateDirectory($store)), new ApprovalRouteStepMapper());
	}

	/**
	 * A store over the stateful fake.
	 *
	 * @return RegisterObjectStore The store.
	 */
	private function store(): RegisterObjectStore {
		// The store takes OpenRegister's facade by TYPE (ADR-083 rule 1), so the
		// stateful fake cannot be passed straight in. A typed mock is backed BY
		// that fake instead: the type satisfies the constructor, and the state
		// still lives, which is what lets these tests assert the route ADVANCING
		// rather than merely that a call was made.
		$state = $this->objectService;
		$facade = $this->createMock(ObjectServiceInterface::class);
		// The callback mirrors ObjectServiceInterface::saveObject()'s REAL
		// signature, whose second parameter is `?array $extend` — not the
		// register. PHPUnit forwards every argument positionally, so a callback
		// shaped like the call site (which uses named arguments and skips
		// $extend) receives the wrong values in the wrong order.
		$facade->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use ($state): ObjectEntityInterface {
				$row = $state->saveObject($object, (string)$register, (string)$schema, $uuid);

				// saveObject returns an ENTITY, not an array. The store already
				// collapses it via jsonSerialize(), so the fake has to hand back
				// something that serialises — returning the raw array would type-
				// error before the store ever normalised it.
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);
		// patchObject() mirrors the contract's real signature for the same
		// positional-forwarding reason as saveObject() above.
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
	}

	/**
	 * A three-step route.
	 *
	 * @param bool $thirdMandatory Whether the third step is mandatory.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function route(bool $thirdMandatory = true): array {
		return [
			'name' => 'Test route',
			'steps' => [
				['order' => 1, 'stageType' => 'advisory', 'actorType' => 'role', 'actor' => 'adviseur', 'label' => 'Advies'],
				['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice', 'label' => 'Parafering'],
				['order' => 3, 'stageType' => 'decisive', 'actorType' => 'role', 'actor' => 'secretaris', 'label' => 'Accordering', 'mandatory' => $thirdMandatory],
			],
		];
	}

	/**
	 * The subject's stages, ordered.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function stages(): array {
		$rows = array_values($this->objectService->rows['decision-stage']);
		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));

		return $rows;
	}

	/**
	 * The stage statuses, in sequence order.
	 *
	 * @return array<int, string> The statuses.
	 */
	private function statuses(): array {
		return array_map(static fn (array $s): string => (string)$s['status'], $this->stages());
	}

	/**
	 * Instantiating makes the first stage live.
	 *
	 * @return void
	 */
	public function testInstantiateMakesTheFirstStageActive(): void {
		$this->service()->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertSame(['active', 'pending', 'pending'], $this->statuses());
		$this->assertSame([1, 2, 3], array_map(static fn (array $s): int => (int)$s['sequence'], $this->stages()));
	}

	/**
	 * Instantiating twice does not produce a second route.
	 *
	 * @return void
	 */
	public function testInstantiateIsIdempotent(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertCount(3, $this->stages());
	}

	/**
	 * A route with no steps is refused rather than instantiated empty.
	 *
	 * @return void
	 */
	public function testARouteWithNoStepsIsRefused(): void {
		$this->expectException(RuntimeException::class);

		$this->service()->instantiate(route: ['name' => 'Empty', 'steps' => []], subject: 'subj-1', subjectSchema: 'proposal');
	}

	/**
	 * An approval completes the stage and activates the next.
	 *
	 * @return void
	 */
	public function testAnApprovalAdvancesTheRoute(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$service->record(['subject' => 'subj-1', 'step' => 1, 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

		$this->assertSame(['decided', 'active', 'pending'], $this->statuses());
		$this->assertSame('advised', $this->stages()[0]['outcome']);
	}

	/**
	 * The final approval leaves NO stage active.
	 *
	 * A completed route still showing an active stage keeps inviting actions on
	 * a decision already taken.
	 *
	 * @return void
	 */
	public function testCompletingTheLastStageLeavesNothingActive(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'approved']);

		$this->assertSame(['decided', 'decided', 'decided'], $this->statuses());
		$this->assertNotContains('active', $this->statuses());
	}

	/**
	 * A further action on a finished route is refused.
	 *
	 * @return void
	 */
	public function testAnActionOnAFinishedRouteIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'approved']);

		$this->expectException(RuntimeException::class);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'approved']);
	}

	/**
	 * A return re-opens the named step and resets the ones after it.
	 *
	 * @return void
	 */
	public function testAReturnRewindsTheRoute(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);

		// Now on step 3; send it back to step 2.
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'returned', 'returnToStep' => 2, 'comment' => 'Onvolledig']);

		$this->assertSame(['decided', 'active', 'pending'], $this->statuses());
		$this->assertSame('advised', $this->stages()[0]['outcome'], 'Steps before the target keep their outcome.');
		$this->assertNull($this->stages()[1]['outcome'] ?? null, 'A re-opened stage must not still read as decided.');
	}

	/**
	 * A return does NOT delete the actions it undid.
	 *
	 * The stages say where the route is; the actions say what happened. A
	 * return that erased the trail would remove exactly the history that makes
	 * a sign-off auditable.
	 *
	 * @return void
	 */
	public function testAReturnPreservesTheActionTrail(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'returned', 'returnToStep' => 2, 'comment' => 'Onvolledig']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);

		$actions = array_values($this->objectService->rows['approval-action']);
		$this->assertCount(4, $actions, 'Every action is a new row; none is an edit of an earlier one.');
		$verbs = array_map(static fn (array $a): string => (string)$a['action'], $actions);
		$this->assertSame(['advised', 'endorsed', 'returned', 'endorsed'], $verbs);
	}

	/**
	 * A return may not point forwards.
	 *
	 * @return void
	 */
	public function testAReturnCannotPointForwards(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'returned', 'returnToStep' => 3, 'comment' => 'Terug']);
			$this->fail('A forward return must be refused.');
		} catch (RuntimeException) {
			$this->assertSame($before, $this->statuses(), 'A refused return must change nothing.');
		}
	}

	/**
	 * A mandatory stage cannot be skipped.
	 *
	 * @return void
	 */
	public function testAMandatoryStageCannotBeSkipped(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'skipped']);
			$this->fail('Skipping a mandatory stage must be refused.');
		} catch (RuntimeException) {
			$this->assertSame($before, $this->statuses());
			$this->assertSame([], $this->objectService->rows['approval-action'], 'A refused action is not recorded.');
		}
	}

	/**
	 * An optional stage can be skipped, and the route advances past it.
	 *
	 * @return void
	 */
	public function testAnOptionalStageCanBeSkipped(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(thirdMandatory: false), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'skipped']);

		$this->assertSame(['decided', 'decided', 'skipped'], $this->statuses());
	}

	/**
	 * An actor the stage does not name is refused, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testTheWrongActorIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

		// Step 2 is assigned to alice.
		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'mallory', 'action' => 'endorsed']);
			$this->fail('An unassigned actor must be refused.');
		} catch (RuntimeException) {
			$this->assertSame($before, $this->statuses());
			$this->assertCount(1, $this->objectService->rows['approval-action'], 'The refused action must not be stored.');
		}
	}

	/**
	 * A step naming a ROLE accepts any actor, and does not store the role as a person.
	 *
	 * Writing a role token into `assignedPerson` would make every actor check
	 * compare a uid against a role name — and refuse everyone.
	 *
	 * @return void
	 */
	public function testARoleStepDoesNotStoreTheRoleAsAPerson(): void {
		$this->service()->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertSame('', $this->stages()[0]['assignedPerson']);
		$this->assertSame('alice', $this->stages()[1]['assignedPerson'], 'A person step DOES name its person.');
	}

	/**
	 * A delegate's action records the principal and the mandate.
	 *
	 * @return void
	 */
	public function testADelegateActionRecordsThePrincipal(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$service->record([
			'subject' => 'subj-1',
			'actor' => 'bob',
			'action' => 'advised',
			'advice' => 'Namens Dave: akkoord',
			'actorType' => 'delegate',
			'onBehalfOf' => 'dave',
			'mandate' => 'mandaat-2026-14',
		]);

		$action = array_values($this->objectService->rows['approval-action'])[0];
		$this->assertSame('delegate', $action['actorType']);
		$this->assertSame('dave', $action['onBehalfOf']);
		$this->assertSame('mandaat-2026-14', $action['mandate']);
	}

	/**
	 * An unknown verb is refused rather than treated as a completion.
	 *
	 * @return void
	 */
	public function testAnUnknownActionIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->expectException(RuntimeException::class);
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'teleported']);
	}

	/**
	 * A route whose steps carry no labels still instantiates valid stages.
	 *
	 * The cross-app case: dossiq's held routes declare no step labels, and the
	 * decision-stage schema REQUIRES one. Writing '' stored NULL, and the
	 * patch recording the FIRST sign-off then 400'd — every cross-app route
	 * wedged on its first signature. The label is derived instead, and the
	 * route must actually advance.
	 *
	 * @return void
	 */
	public function testAnUnlabeledRouteGetsDerivedLabelsAndAdvances(): void {
		$route = [
			'name' => 'Cross-app route',
			'steps' => [
				['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice'],
				['order' => 2, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'carol'],
			],
		];

		$service = $this->service();
		$service->instantiate(route: $route, subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertSame(
			['Endorsement (step 1)', 'Decisive (step 2)'],
			array_map(static fn (array $s): string => (string)$s['label'], $this->stages()),
			'A step without a label gets one derived from its stage type and step number.'
		);

		// The first sign-off is the one that 400'd; it must advance now.
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$this->assertSame(['decided', 'active'], $this->statuses());
	}

	/**
	 * A stage write that fails leaves NO action row behind.
	 *
	 * The old order appended the action FIRST and patched the stage after, so
	 * a refused stage write left an orphan action row claiming a sign-off the
	 * route never took — and every retry appended another. The stage write
	 * comes first now; this pins the no-orphan property on exactly the shape
	 * that produced the orphans: a legacy stage stored without its required
	 * label, whose re-validation refuses the patch.
	 *
	 * @return void
	 */
	public function testAFailedStageWriteAppendsNoActionRow(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		// A legacy NULL-label stage: stored before the label fix.
		foreach ($this->objectService->rows['decision-stage'] as $uuid => $row) {
			unset($this->objectService->rows['decision-stage'][$uuid]['label']);
		}

		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
			$this->fail('The stage patch must refuse a stage that no longer validates.');
		} catch (RuntimeException) {
			$this->assertSame(
				[],
				$this->objectService->rows['approval-action'],
				'A failed stage write must leave no orphan action row.'
			);
			$this->assertSame($before, $this->statuses(), 'The refused advance changes no stage.');
		}
	}

	/**
	 * The label repair backfills legacy NULL-label stages, once.
	 *
	 * Idempotent: the second run finds every stage labeled and repairs
	 * nothing. Existing action rows are never touched — they are the audit
	 * record. The derivation is the mapper's own labelOf(), so the repaired
	 * label and a freshly instantiated one cannot differ.
	 *
	 * @return void
	 */
	public function testTheLabelRepairBackfillsAndIsIdempotent(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

		// Two legacy NULL-label stages among a healthy one.
		unset($this->objectService->rows['decision-stage']['decision-stage-2']['label']);
		unset($this->objectService->rows['decision-stage']['decision-stage-3']['label']);

		$repair = new DecisionStageLabelRepair($this->store(), new ApprovalRouteStepMapper());

		$this->assertSame(2, $repair->repair(), 'Exactly the unlabeled stages are repaired.');
		$this->assertSame(
			['Advies', 'Endorsement (step 2)', 'Decisive (step 3)'],
			array_map(static fn (array $s): string => (string)$s['label'], $this->stages()),
			'A stored label survives; a missing one is derived.'
		);
		$this->assertCount(1, $this->objectService->rows['approval-action'], 'The action trail is never edited by the repair.');

		$this->assertSame(0, $repair->repair(), 'A re-run repairs nothing.');
	}
}
