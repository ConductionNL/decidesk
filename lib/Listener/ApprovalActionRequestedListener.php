<?php

/**
 * Decidiq ApprovalActionRequestedListener
 *
 * Maps an inbound cross-app ApprovalActionRequestedEvent onto
 * ApprovalRouteCommandService, writes the outcome back onto the dispatched
 * instance, and announces a finished route as ApprovalRouteConcludedEvent.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Event\ApprovalActionRequestedEvent;
use OCA\Decidiq\Service\ApprovalRouteCommandService;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles ApprovalActionRequestedEvent by delegating to the command service.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class ApprovalActionRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteCommandService       $commandService The delegating command engine.
	 * @param ApprovalRouteConclusionAnnouncer $announcer      The one door a conclusion leaves by.
	 * @param LoggerInterface                   $logger         Logger.
	 */
	public function __construct(
		private readonly ApprovalRouteCommandService $commandService,
		private readonly ApprovalRouteConclusionAnnouncer $announcer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ApprovalActionRequestedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ApprovalActionRequestedEvent === false) {
			return;
		}

		try {
			$result = $this->commandService->recordAction(action: $this->actionFrom(event: $event));
		} catch (Throwable $e) {
			// The engine's REASON is carried back, not just the refusal. "You are
			// not the named actor" and "there is nothing to act on" call for
			// different handling by the producer, and a bare false collapses them.
			$event->setRefusal($e->getMessage());
			$this->logger->warning(
				'Decidiq: ApprovalActionRequestedEvent refused',
				[
					'sourceApp' => $event->getSourceApp(),
					'subject' => $event->getSubject(),
					'actor' => $event->getActor(),
					'reason' => $e->getMessage(),
				]
			);
			return;
		}//end try

		$event->setRecorded($result['recorded']);
		$event->setCompleted($result['completed']);
		$event->setHandled(true);

		if ($result['completed'] === false) {
			return;
		}

		// Announced through the shared announcer, not dispatched inline: the
		// REST surface and the task inbox conclude routes too, and three
		// dispatch sites is how payloads drift apart.
		$this->announcer->announceIfConcluded(
			subject: $event->getSubject(),
			correlationId: $event->getCorrelationId(),
		);

	}//end handle()

	/**
	 * Build the engine's action shape from the event.
	 *
	 * The `step` the engine records is NOT taken from the producer. The engine
	 * decides which stage is active, and a producer-supplied step number would
	 * let an action be filed against a stage nobody is waiting on.
	 *
	 * @param ApprovalActionRequestedEvent $event The command.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function actionFrom(ApprovalActionRequestedEvent $event): array {
		$action = [
			'subject' => $event->getSubject(),
			'actor' => $event->getActor(),
			'action' => $event->getAction(),
			'actorType' => $event->getActorType(),
		];

		foreach (
			[
				'comment' => $event->getComment(),
				'advice' => $event->getAdvice(),
				'onBehalfOf' => $event->getOnBehalfOf(),
				'mandate' => $event->getMandate(),
			] as $key => $value
		) {
			if ($value !== '') {
				$action[$key] = $value;
			}
		}

		if ($event->getReturnToStep() > 0) {
			$action['returnToStep'] = $event->getReturnToStep();
		}

		return $action;

	}//end actionFrom()

}//end class
