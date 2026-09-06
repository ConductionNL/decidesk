<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\ApprovalTaskDecisionListener;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the inbox side of the absorbed runtime.
 *
 * The listener's whole worth is its filters. Every terminal task on the
 * instance flows past it — consumed tasks, cancelled tasks, other apps'
 * tasks, and the projector's own close() run — and the one kind it may act on
 * is a projected approval-stage task somebody COMPLETED. An echo of the
 * projector's consume back into the engine would advance a route nobody
 * signed.
 */
class ApprovalTaskDecisionListenerTest extends TestCase {

	/**
	 * A fake terminal event over a fake task.
	 *
	 * @param array<string, mixed> $fields Task getter values, by getter suffix.
	 *
	 * @return Event The event.
	 */
	private function terminalEvent(array $fields): Event {
		// Explicit getters, not __call: the listener duck-types with
		// method_exists(), which a __call fake would silently fail — the fake
		// would test a listener that never sees a field.
		$task = new class ($fields) {
			/**
			 * @param array<string, mixed> $fields The canned values.
			 */
			public function __construct(private array $fields) {
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getTemplateId(): mixed {
				return ($this->fields['getTemplateId'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getState(): mixed {
				return ($this->fields['getState'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getObjectUuid(): mixed {
				return ($this->fields['getObjectUuid'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getCompletedBy(): mixed {
				return ($this->fields['getCompletedBy'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getOutcome(): mixed {
				return ($this->fields['getOutcome'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getComment(): mixed {
				return ($this->fields['getComment'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getUuid(): mixed {
				return ($this->fields['getUuid'] ?? null);
			}
		};

		return new class ($task) extends Event {
			/**
			 * @param object $task The task.
			 */
			public function __construct(private object $task) {
				parent::__construct();
			}

			/**
			 * The task.
			 *
			 * @return object The task.
			 */
			public function getTask(): object {
				return $this->task;
			}
		};
	}

	/**
	 * A completed, projected approval-stage task.
	 *
	 * @param string $outcome The task outcome.
	 *
	 * @return Event The event.
	 */
	private function completedProjectedTask(string $outcome = 'approved'): Event {
		return $this->terminalEvent(fields: [
			'getTemplateId' => 'decidiq:approval-stage',
			'getState' => 'completed',
			'getObjectUuid' => 'v-1',
			'getCompletedBy' => 'carol',
			'getOutcome' => $outcome,
			'getComment' => 'Akkoord.',
			'getUuid' => 'task-9',
		]);
	}

	/**
	 * A completed projected task becomes an engine action and an announcement.
	 *
	 * @return void
	 */
	public function testACompletedProjectedTaskAdvancesTheRoute(): void {
		$seen = [];
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->method('stagesFor')->willReturn(
			[['id' => 's-2', 'sequence' => 2, 'status' => 'active', 'stageType' => 'decisive', 'taskUuid' => 'task-9']]
		);
		$engine->method('record')->willReturnCallback(
			static function (array $action) use (&$seen): array {
				$seen = $action;

				return $action;
			}
		);

		$announcer = $this->createMock(ApprovalRouteConclusionAnnouncer::class);
		$announcer->expects($this->once())->method('announceIfConcluded')->with('v-1');

		$listener = new ApprovalTaskDecisionListener($engine, $announcer, $this->createMock(LoggerInterface::class));
		$listener->handle($this->completedProjectedTask());

		$this->assertSame('v-1', $seen['subject']);
		$this->assertSame('carol', $seen['actor'], 'The COMPLETER signs, never a body-supplied identity.');
		$this->assertSame('approved', $seen['action'], 'A decisive stage completes with approved.');
	}

	/**
	 * A rejecting task outcome is a terugsturen, reason and all.
	 *
	 * @return void
	 */
	public function testARejectingOutcomeReturnsTheSubject(): void {
		$seen = [];
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->method('record')->willReturnCallback(
			static function (array $action) use (&$seen): array {
				$seen = $action;

				return $action;
			}
		);

		$listener = new ApprovalTaskDecisionListener(
			$engine,
			$this->createMock(ApprovalRouteConclusionAnnouncer::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->completedProjectedTask(outcome: 'rejected'));

		$this->assertSame('returned', $seen['action']);
		$this->assertSame('Akkoord.', $seen['comment']);
	}

	/**
	 * A task without the projector's marker is not ours and does nothing.
	 *
	 * @return void
	 */
	public function testAForeignTaskIsIgnored(): void {
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->expects($this->never())->method('record');

		$listener = new ApprovalTaskDecisionListener(
			$engine,
			$this->createMock(ApprovalRouteConclusionAnnouncer::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->terminalEvent(fields: [
			'getTemplateId' => 'someone-elses-template',
			'getState' => 'completed',
			'getObjectUuid' => 'v-1',
			'getCompletedBy' => 'carol',
			'getOutcome' => 'approved',
		]));
	}

	/**
	 * A consumed task decided nothing and must not echo into the engine.
	 *
	 * The projector consumes a projected task when the stage was decided
	 * through another surface; replaying that back as a decision would sign
	 * the NEXT stage with nobody's hand.
	 *
	 * @return void
	 */
	public function testAConsumedTaskDoesNotEchoIntoTheEngine(): void {
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->expects($this->never())->method('record');

		$listener = new ApprovalTaskDecisionListener(
			$engine,
			$this->createMock(ApprovalRouteConclusionAnnouncer::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->terminalEvent(fields: [
			'getTemplateId' => 'decidiq:approval-stage',
			'getState' => 'consumed',
			'getObjectUuid' => 'v-1',
			'getCompletedBy' => 'carol',
			'getOutcome' => 'approved',
		]));
	}

	/**
	 * An engine refusal is absorbed: no announcement, no exception.
	 *
	 * The task is already terminal; what is withheld is the advance.
	 *
	 * @return void
	 */
	public function testAnEngineRefusalIsAbsorbed(): void {
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->method('stagesFor')->willReturn(
			[['id' => 's-2', 'sequence' => 2, 'status' => 'active', 'stageType' => 'decisive', 'taskUuid' => 'task-9']]
		);
		$engine->method('record')->willThrowException(new RuntimeException('This stage is assigned to someone else.'));

		$announcer = $this->createMock(ApprovalRouteConclusionAnnouncer::class);
		$announcer->expects($this->never())->method('announceIfConcluded');

		$listener = new ApprovalTaskDecisionListener($engine, $announcer, $this->createMock(LoggerInterface::class));
		$listener->handle($this->completedProjectedTask());

		$this->addToAssertionCount(1);
	}

	/**
	 * The completing verb follows the awaiting stage's type.
	 *
	 * @return void
	 */
	public function testTheVerbFollowsTheStageType(): void {
		$seen = [];
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->method('stagesFor')->willReturn(
			[['id' => 's-1', 'sequence' => 1, 'status' => 'active', 'stageType' => 'endorsement', 'taskUuid' => 'task-9']]
		);
		$engine->method('record')->willReturnCallback(
			static function (array $action) use (&$seen): array {
				$seen = $action;

				return $action;
			}
		);

		$listener = new ApprovalTaskDecisionListener(
			$engine,
			$this->createMock(ApprovalRouteConclusionAnnouncer::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->completedProjectedTask());

		$this->assertSame('endorsed', $seen['action']);
	}

	/**
	 * A task no active stage carries resolves to no action at all.
	 *
	 * @return void
	 */
	public function testATaskNoStageCarriesDoesNothing(): void {
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->method('stagesFor')->willReturn([]);
		$engine->expects($this->never())->method('record');

		$listener = new ApprovalTaskDecisionListener(
			$engine,
			$this->createMock(ApprovalRouteConclusionAnnouncer::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->completedProjectedTask());

		$this->addToAssertionCount(1);
	}
}
