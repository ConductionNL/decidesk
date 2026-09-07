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

use OCA\Decidiq\Service\ApprovalStageTaskProjector;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the task-inbox projection of active approval stages.
 *
 * The invariant worth pinning is the DIRECTION of dependence: the stages are
 * the engine and the tasks are a mirror. An instance with no task surface at
 * all must see every route conclude identically, so the projector's absence,
 * refusal or partial failure may cost visibility and nothing else.
 */
class ApprovalStageTaskProjectorTest extends TestCase {

	/**
	 * A recording fake of OR's flow-task service.
	 *
	 * @var object
	 */
	private object $tasks;

	/**
	 * Stage patches written back to the store.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: string|null}>
	 */
	private array $stagePatches = [];

	/**
	 * Build the fake task surface.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->tasks = new class {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $imported = [];

			/**
			 * @var array<int, string>
			 */
			public array $consumed = [];

			/**
			 * Import a task.
			 *
			 * @param array<string, mixed> $data The task data.
			 * @param string|null $actor The actor.
			 *
			 * @return object A task answering getUuid().
			 */
			public function import(array $data, ?string $actor): object {
				$this->imported[] = $data;

				return new class {
					/**
					 * The uuid.
					 *
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return 'task-1';
					}
				};
			}

			/**
			 * Consume a task.
			 *
			 * @param string $uuid The task uuid.
			 * @param string $source The consuming source.
			 * @param string $reason The reason.
			 *
			 * @return void
			 */
			public function consume(string $uuid, string $source, string $reason): void {
				$this->consumed[] = $uuid;
			}
		};
	}

	/**
	 * The projector under test.
	 *
	 * @param bool $withTaskService Whether the container resolves the surface.
	 *
	 * @return ApprovalStageTaskProjector The projector.
	 */
	private function projector(bool $withTaskService = true): ApprovalStageTaskProjector {
		$container = $this->createMock(ContainerInterface::class);
		if ($withTaskService === true) {
			$container->method('get')->willReturn($this->tasks);
		} else {
			$container->method('get')->willThrowException(new RuntimeException('no task surface on this release'));
		}

		$store = $this->createMock(RegisterObjectStore::class);
		$store->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid) : array {
				$this->stagePatches[] = [$schema, $data, $uuid];

				return ($data + ['id' => $uuid]);
			}
		);

		return new ApprovalStageTaskProjector($store, $container, $this->createMock(LoggerInterface::class));
	}

	/**
	 * An active person-assigned stage opens a task and records the linkage.
	 *
	 * @return void
	 */
	public function testAnActiveAssignedStageOpensATask(): void {
		$this->projector()->sync(
			subject: 'v-1',
			stages: [
				['id' => 's-1', 'sequence' => 1, 'status' => 'active', 'stageType' => 'endorsement', 'assignedPerson' => 'alice', 'label' => 'Paraaf', 'taskUuid' => ''],
			],
		);

		$this->assertCount(1, $this->tasks->imported);
		$imported = $this->tasks->imported[0];
		$this->assertSame('alice', $imported['assignee']);
		$this->assertSame('v-1', $imported['objectUuid']);
		$this->assertSame(ApprovalStageTaskProjector::TEMPLATE_ID, $imported['templateId']);
		$this->assertSame('s-1', $imported['nodeId']);

		$this->assertSame([['decision-stage', ['taskUuid' => 'task-1'], 's-1']], $this->stagePatches);
	}

	/**
	 * A stage that stopped waiting has its task consumed and the linkage cleared.
	 *
	 * @return void
	 */
	public function testADecidedStageClosesItsTask(): void {
		$this->projector()->sync(
			subject: 'v-1',
			stages: [
				['id' => 's-1', 'sequence' => 1, 'status' => 'decided', 'assignedPerson' => 'alice', 'taskUuid' => 'task-1'],
			],
		);

		$this->assertSame(['task-1'], $this->tasks->consumed);
		$this->assertSame([['decision-stage', ['taskUuid' => ''], 's-1']], $this->stagePatches);
	}

	/**
	 * A stage whose task already matches its state is left alone.
	 *
	 * @return void
	 */
	public function testAMatchingStageIsLeftAlone(): void {
		$this->projector()->sync(
			subject: 'v-1',
			stages: [
				['id' => 's-1', 'sequence' => 1, 'status' => 'active', 'assignedPerson' => 'alice', 'taskUuid' => 'task-1'],
				['id' => 's-2', 'sequence' => 2, 'status' => 'pending', 'assignedPerson' => 'bob', 'taskUuid' => ''],
			],
		);

		$this->assertSame([], $this->tasks->imported);
		$this->assertSame([], $this->tasks->consumed);
		$this->assertSame([], $this->stagePatches);
	}

	/**
	 * A stage naming no person gets no task.
	 *
	 * @return void
	 */
	public function testAnUnassignedStageGetsNoTask(): void {
		$this->projector()->sync(
			subject: 'v-1',
			stages: [
				['id' => 's-1', 'sequence' => 1, 'status' => 'active', 'assignedPerson' => '', 'taskUuid' => ''],
			],
		);

		$this->assertSame([], $this->tasks->imported);
	}

	/**
	 * No task surface means a silent no-op, never a failure.
	 *
	 * @return void
	 */
	public function testNoTaskSurfaceIsASilentNoOp(): void {
		$this->projector(withTaskService: false)->sync(
			subject: 'v-1',
			stages: [
				['id' => 's-1', 'sequence' => 1, 'status' => 'active', 'assignedPerson' => 'alice', 'taskUuid' => ''],
			],
		);

		$this->assertSame([], $this->stagePatches);
	}
}
