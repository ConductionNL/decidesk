<?php

/**
 * Decidiq approval-stage task projector.
 *
 * Mirrors a subject's ACTIVE approval stages onto OpenRegister's task surface,
 * so a sign-off lands in the work queue the approver already reads instead of
 * in a screen they would have to know to open. This is the ride on the task
 * surface that flow-approval-consolidation shipped — the tasks are ordinary OR
 * flow-tasks, visible in the task inbox, person-assigned, with the sequence's
 * own reminder and due-date machinery available to them.
 *
 * WHY THE STAGES STAY THE ENGINE. OpenRegister's TaskSequence is deliberately
 * ordinal: no parallelism, no return, and every position is offered to a GROUP
 * ('single-role'). A parafering route needs the three things it excludes —
 * per-person assignment, parallel co-signing, and terugsturen — and OR's own
 * design note says an approval needing more than a straight line belongs to a
 * richer driver. So the DecisionStage rows keep the truth, and this projector
 * keeps the task inbox agreeing with them: one task per active assigned stage,
 * closed when the stage no longer waits.
 *
 * EVERYTHING HERE IS BEST EFFORT. The projection changes where an ask is SEEN,
 * never whether the route advances. The task classes are resolved through the
 * container because older OpenRegister releases do not ship them, and every
 * failure is logged and swallowed — a route must conclude identically on an
 * instance with no task surface at all.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the OR task inbox agreeing with a subject's approval stages.
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class ApprovalStageTaskProjector {

	/**
	 * OpenRegister's flow-task service, resolved by name so decidiq does not
	 * hard-depend on a release that ships it.
	 *
	 * ⚠️ NOT `OCA\OpenRegister\Service\TaskService` — that is the CalDAV VTODO
	 * service. The flow-task one lives under Service\Task.
	 *
	 * @var string
	 */
	private const TASK_SERVICE = 'OCA\\OpenRegister\\Service\\Task\\TaskService';

	/**
	 * The provenance marker a projected task carries in its templateId.
	 *
	 * The decision listener trusts this marker to know which terminal tasks
	 * are approval-stage asks, so it is a contract, not a label.
	 *
	 * @var string
	 */
	public const TEMPLATE_ID = 'decidiq:approval-stage';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Writes the task linkage onto the stage.
	 * @param ContainerInterface $container Resolves the task service at runtime.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Reconcile the subject's projected tasks with its stages.
	 *
	 * Idempotent by design: an active assigned stage without a task gets one,
	 * a no-longer-active stage with an open task has it consumed, and a stage
	 * whose state already matches is left alone. Reconciliation beats
	 * choreography here because the engine mutates several stages per action
	 * (a parallel group, a rewind), and a projector that replayed individual
	 * transitions would have to reproduce the engine's rules to stay right.
	 *
	 * @param string $subject The subject uuid.
	 * @param array<int, array<string, mixed>> $stages The subject's stages, engine-ordered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function sync(string $subject, array $stages): void {
		$tasks = $this->taskService();
		if ($tasks === null) {
			return;
		}

		foreach ($stages as $stage) {
			try {
				$this->syncStage(tasks: $tasks, subject: $subject, stage: $stage);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Decidiq: could not mirror an approval stage onto the task surface',
					[
						'subject' => $subject,
						'stage' => ($stage['id'] ?? null),
						'error' => $e->getMessage(),
					]
				);
			}
		}
	}//end sync()

	/**
	 * Bring one stage's task in line with its status.
	 *
	 * @param object $tasks The task service.
	 * @param string $subject The subject uuid.
	 * @param array<string, mixed> $stage The stage.
	 *
	 * @return void
	 */
	private function syncStage(object $tasks, string $subject, array $stage): void {
		$status = (string)($stage['status'] ?? '');
		$taskUuid = trim((string)($stage['taskUuid'] ?? ''));

		if ($status === 'active' && $taskUuid === '') {
			$this->openTask(tasks: $tasks, subject: $subject, stage: $stage);

			return;
		}

		if ($status !== 'active' && $taskUuid !== '') {
			$this->closeTask(tasks: $tasks, stage: $stage, taskUuid: $taskUuid);
		}
	}//end syncStage()

	/**
	 * Open the ask: one person-assigned task for an active stage.
	 *
	 * A stage naming no person gets no task — the task surface assigns to
	 * identities, and inventing a pool from a role token here would restate
	 * the role-resolution question the engine deliberately leaves to the
	 * consuming context.
	 *
	 * @param object $tasks The task service.
	 * @param string $subject The subject uuid.
	 * @param array<string, mixed> $stage The active stage.
	 *
	 * @return void
	 */
	private function openTask(object $tasks, string $subject, array $stage): void {
		$assignee = trim((string)($stage['assignedPerson'] ?? ''));
		if ($assignee === '' || method_exists($tasks, 'import') === false) {
			return;
		}

		$label = (string)($stage['label'] ?? '');
		$task = $tasks->import(
			data: [
				'title' => trim('Sign-off: ' . $label),
				'description' => sprintf(
					'Approval stage %d (%s) for subject %s.',
					(int)($stage['sequence'] ?? 0),
					(string)($stage['stageType'] ?? ''),
					$subject
				),
				'state' => 'enabled',
				'performerType' => 'user',
				'assignee' => $assignee,
				'objectUuid' => $subject,
				'templateId' => self::TEMPLATE_ID,
				'nodeId' => (string)($stage['id'] ?? ''),
			],
			actor: $assignee,
		);

		$uuid = '';
		if (is_object($task) === true && method_exists($task, 'getUuid') === true) {
			$uuid = (string)$task->getUuid();
		}

		if ($uuid === '') {
			$this->logger->warning(
				'Decidiq: the task surface returned a task with no uuid, so the stage cannot reference it',
				['subject' => $subject, 'stage' => ($stage['id'] ?? null)]
			);

			return;
		}

		$this->store->patch(schema: 'decision-stage', data: ['taskUuid' => $uuid], uuid: (string)$stage['id']);
	}//end openTask()

	/**
	 * Close the ask: the stage no longer waits, so neither should the inbox.
	 *
	 * `consume()` is the surface's own verb for "the thing this task asked for
	 * happened elsewhere" — which is exactly what a stage decided through the
	 * engine is. A task already terminal (the approver answered THROUGH the
	 * task) refuses the consume, and that refusal is fine: terminal is
	 * terminal. The linkage is cleared either way so a rewound stage opens a
	 * fresh ask instead of pointing at a spent one.
	 *
	 * @param object $tasks The task service.
	 * @param array<string, mixed> $stage The no-longer-active stage.
	 * @param string $taskUuid The projected task.
	 *
	 * @return void
	 */
	private function closeTask(object $tasks, array $stage, string $taskUuid): void {
		if (method_exists($tasks, 'consume') === true) {
			try {
				$tasks->consume(
					uuid: $taskUuid,
					source: self::TEMPLATE_ID,
					reason: 'The approval stage this task asked about is no longer awaiting an answer.'
				);
			} catch (Throwable $e) {
				$this->logger->info(
					'Decidiq: a projected approval task was not consumed (already terminal is the ordinary cause): '
					. $e->getMessage(),
					['task' => $taskUuid]
				);
			}
		}

		$this->store->patch(schema: 'decision-stage', data: ['taskUuid' => ''], uuid: (string)$stage['id']);
	}//end closeTask()

	/**
	 * OpenRegister's flow-task service, or null when this release lacks it.
	 *
	 * @return object|null The task service.
	 */
	private function taskService(): ?object {
		try {
			return $this->container->get(self::TASK_SERVICE);
		} catch (Throwable) {
			return null;
		}
	}//end taskService()

}//end class
