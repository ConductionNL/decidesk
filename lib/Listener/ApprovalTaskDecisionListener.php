<?php

/**
 * Decidiq approval-task decision listener.
 *
 * Closes the loop from the task inbox: when an approver answers a PROJECTED
 * approval-stage task (marked `decidiq:approval-stage` by the projector), the
 * answer becomes an engine action, so signing from the inbox and signing from
 * the route surface are the same signature through the same rules.
 *
 * 🔴 THE ENGINE STILL JUDGES. The task's completer is handed to
 * `ApprovalRouteService::record()` as the actor, with the task's onBehalfOf, and
 * every refusal the engine would give the REST path it gives here too. A task
 * completion the engine refuses stays a completed task — the task surface
 * already committed it and this listener only observed — so the refusal is
 * logged loudly and the stage keeps waiting, which mirrors dossiq's
 * ParaafResumeListener posture: the write stands, the ADVANCE is withheld.
 *
 * The OpenRegister event class is registered by FQN string and only when it
 * exists, so decidiq carries no hard compile-time dependency on a release that
 * ships the task surface.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
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

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\ApprovalStageTaskProjector;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an answered approval-stage task into an engine action.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class ApprovalTaskDecisionListener implements IEventListener {

	/**
	 * Task outcomes that send the subject back rather than onward.
	 *
	 * Mirrors the task surface's own rejecting-outcome vocabulary; matched
	 * case-insensitively the way the surface matches them.
	 *
	 * @var array<int, string>
	 */
	private const REJECTING_OUTCOMES = ['rejected', 'returned', 'refused', 'denied'];

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteService $engine The route engine every rule lives in.
	 * @param ApprovalRouteConclusionAnnouncer $announcer The one door a conclusion leaves by.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ApprovalRouteService $engine,
		private readonly ApprovalRouteConclusionAnnouncer $announcer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the engine action an answered projected task means.
	 *
	 * @param Event $event The dispatched OpenRegister TaskTerminalEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function handle(Event $event): void {
		$task = $this->projectedDecisionFrom(event: $event);
		if ($task === null) {
			return;
		}

		$subject = (string)$this->read(object: $task, getter: 'getObjectUuid');
		$actor = (string)$this->read(object: $task, getter: 'getCompletedBy');
		if ($subject === '' || $actor === '') {
			return;
		}

		$action = $this->actionFrom(task: $task, subject: $subject, actor: $actor);
		if ($action === null) {
			return;
		}

		try {
			$this->engine->record(action: $action);
		} catch (Throwable $e) {
			// The task is already terminal; what is withheld is the ADVANCE.
			$this->logger->warning(
				'Decidiq: a completed approval task did not advance the route — the engine refused it',
				[
					'subject' => $subject,
					'actor' => $actor,
					'reason' => $e->getMessage(),
				]
			);

			return;
		}

		$this->announcer->announceIfConcluded(subject: $subject);
	}//end handle()

	/**
	 * The task this event reports, when it is a projected stage DECISION.
	 *
	 * Duck-typed throughout: the event and task classes are OpenRegister's and
	 * optional at runtime. Filters, in order: the event carries a task; the
	 * task carries the projector's template marker (the contract that makes it
	 * ours); the task ended as a completed decision — a consumed, cancelled or
	 * terminated task decided nothing, and the projector's own consume() run
	 * lands here too and must not echo back into the engine.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return object|null The task, or null when this event is not ours.
	 */
	private function projectedDecisionFrom(Event $event): ?object {
		if (method_exists($event, 'getTask') === false) {
			return null;
		}

		$task = $event->getTask();
		if (is_object($task) === false) {
			return null;
		}

		if ((string)$this->read(object: $task, getter: 'getTemplateId') !== ApprovalStageTaskProjector::TEMPLATE_ID) {
			return null;
		}

		if ((string)$this->read(object: $task, getter: 'getState') !== 'completed') {
			return null;
		}

		return $task;
	}//end projectedDecisionFrom()

	/**
	 * The engine action an answered task means.
	 *
	 * A rejecting outcome is a terugsturen: `returned` with the task's comment
	 * as the reason, concluding the route back to its sender. Any other
	 * outcome completes the stage with the verb the stage's TYPE asks for —
	 * looked up from the live stage, because the engine refuses a mismatched
	 * verb and the inbox only says "done", not which vocabulary word this
	 * stage's signature uses.
	 *
	 * @param object $task The completed task.
	 * @param string $subject The subject uuid.
	 * @param string $actor The completing identity.
	 *
	 * @return array<string, mixed>|null The action, or null when no stage awaits.
	 */
	private function actionFrom(object $task, string $subject, string $actor): ?array {
		$outcome = strtolower(trim((string)$this->read(object: $task, getter: 'getOutcome')));
		$comment = (string)$this->read(object: $task, getter: 'getComment');
		$onBehalfOf = (string)$this->read(object: $task, getter: 'getOnBehalfOf');

		$action = [
			'subject' => $subject,
			'actor' => $actor,
			'comment' => $comment,
		];

		if ($onBehalfOf !== '') {
			$action['actorType'] = 'delegate';
			$action['onBehalfOf'] = $onBehalfOf;
			$action['mandate'] = (string)$this->read(object: $task, getter: 'getMandate');
		}

		if (in_array($outcome, self::REJECTING_OUTCOMES, true) === true) {
			$action['action'] = 'returned';
			if (trim($comment) === '') {
				$action['comment'] = 'Returned from the task inbox without a stated reason.';
			}

			return $action;
		}

		$verb = $this->completingVerbFor(subject: $subject, taskUuid: (string)$this->read(object: $task, getter: 'getUuid'));
		if ($verb === '') {
			return null;
		}

		$action['action'] = $verb;
		if ($verb === 'advised' && trim((string)$this->read(object: $task, getter: 'getResultText')) !== '') {
			$action['advice'] = (string)$this->read(object: $task, getter: 'getResultText');
		}

		if ($verb === 'advised' && isset($action['advice']) === false) {
			$action['advice'] = $comment;
		}

		return $action;
	}//end actionFrom()

	/**
	 * The completing verb the awaiting stage's type asks for.
	 *
	 * Resolved from the stage the task points at (its uuid is on the stage's
	 * `taskUuid`), asking the ENGINE for the stages — never this class's own
	 * query of route state.
	 *
	 * @param string $subject The subject uuid.
	 * @param string $taskUuid The answered task's uuid.
	 *
	 * @return string The verb, or '' when no active stage carries this task.
	 */
	private function completingVerbFor(string $subject, string $taskUuid): string {
		if ($taskUuid === '') {
			return '';
		}

		foreach ($this->engine->stagesFor(subject: $subject) as $stage) {
			$isOurs = ((string)($stage['taskUuid'] ?? '') === $taskUuid);
			if ($isOurs === false || (string)($stage['status'] ?? '') !== 'active') {
				continue;
			}

			return match ((string)($stage['stageType'] ?? '')) {
				'advisory' => 'advised',
				'endorsement' => 'endorsed',
				'decisive' => 'approved',
				default => 'approved',
			};
		}

		return '';
	}//end completingVerbFor()

	/**
	 * Read a duck-typed getter, degrading to '' rather than erroring.
	 *
	 * @param object $object The task or event.
	 * @param string $getter The zero-argument getter.
	 *
	 * @return string The stringified value, or ''.
	 */
	private function read(object $object, string $getter): string {
		if (method_exists($object, $getter) === false) {
			return '';
		}

		$value = $object->$getter();
		if ($value === null || is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end read()

}//end class
