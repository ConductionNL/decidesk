<?php

/**
 * Decidiq ApprovalRouteRequestedListener
 *
 * Maps an inbound cross-app ApprovalRouteRequestedEvent onto
 * ApprovalRouteCommandService and writes the result back onto the dispatched
 * instance for the synchronous producer.
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

use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Decidiq\Service\ApprovalRouteCommandService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles ApprovalRouteRequestedEvent by delegating to the command service.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class ApprovalRouteRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteCommandService $commandService The idempotent upsert engine.
	 * @param LoggerInterface             $logger         Logger.
	 */
	public function __construct(
		private readonly ApprovalRouteCommandService $commandService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ApprovalRouteRequestedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ApprovalRouteRequestedEvent === false) {
			return;
		}

		try {
			$result = $this->commandService->holdRoute(
				sourceApp: $event->getSourceApp(),
				externalReference: $event->getExternalReference(),
				template: [
					'name' => $event->getName(),
					'steps' => $event->getSteps(),
					'subjectType' => $event->getSubjectType(),
					'description' => $event->getDescription(),
					'isDefault' => $event->isDefault(),
				],
				subject: $event->getSubject(),
				subjectSchema: $event->getSubjectSchema(),
			);
		} catch (Throwable $e) {
			// Never rethrown: an exception out of handle() aborts the whole
			// dispatch, so an unrelated listener on the same event would stop
			// running because this one failed. The producer sees isHandled().
			$this->logger->error(
				'Decidiq: ApprovalRouteRequestedEvent not handled',
				[
					'sourceApp' => $event->getSourceApp(),
					'externalReference' => $event->getExternalReference(),
					'exception' => $e,
				]
			);
			return;
		}//end try

		$event->setRouteId($result['id']);
		$event->setCreated($result['created']);
		$event->setStageCount($result['stageCount']);
		$event->setHandled(true);

		$this->logger->info(
			'Decidiq: handled ApprovalRouteRequestedEvent',
			[
				'sourceApp' => $event->getSourceApp(),
				'externalReference' => $event->getExternalReference(),
				'routeId' => $result['id'],
				'created' => $result['created'],
				'stageCount' => $result['stageCount'],
			]
		);

	}//end handle()

}//end class
