<?php

/**
 * Decidiq GovernanceBodyRequestedListener
 *
 * Maps an inbound cross-app GovernanceBodyRequestedEvent onto
 * GovernanceBodyCommandService, writes the resolved id back onto the dispatched
 * instance for the synchronous producer, and announces the outcome as
 * GovernanceBodyCreatedEvent so a producer that dispatched asynchronously can
 * pick it up by correlation.
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
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Event\GovernanceBodyCreatedEvent;
use OCA\Decidiq\Event\GovernanceBodyRequestedEvent;
use OCA\Decidiq\Service\GovernanceBodyCommandService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles GovernanceBodyRequestedEvent by delegating to the command service.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */
class GovernanceBodyRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param GovernanceBodyCommandService $commandService The idempotent upsert engine.
	 * @param IEventDispatcher $dispatcher Dispatcher for the conclusion event.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly GovernanceBodyCommandService $commandService,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a GovernanceBodyRequestedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof GovernanceBodyRequestedEvent === false) {
			return;
		}

		try {
			$result = $this->commandService->upsert(
				sourceApp: $event->getSourceApp(),
				externalReference: $event->getExternalReference(),
				body: ($event->getAttributes() + [
					'name' => $event->getName(),
					'bodyType' => $event->getBodyType(),
					'domain' => $event->getDomain(),
					'active' => $event->isActive(),
				]),
				members: $event->getMembers(),
			);
		} catch (Throwable $e) {
			// The producer sees isHandled() === false and decides what to do.
			// Nothing is rethrown: an exception out of handle() aborts the whole
			// dispatch, so an unrelated listener on the same event would stop
			// running because this one failed.
			$this->logger->error(
				'Decidiq: GovernanceBodyRequestedEvent not handled',
				[
					'sourceApp' => $event->getSourceApp(),
					'externalReference' => $event->getExternalReference(),
					'exception' => $e,
				]
			);
			return;
		}//end try

		$event->setGovernanceBodyId($result['id']);
		$event->setCreated($result['created']);
		$event->setHandled(true);

		$this->logger->info(
			'Decidiq: handled GovernanceBodyRequestedEvent',
			[
				'sourceApp' => $event->getSourceApp(),
				'externalReference' => $event->getExternalReference(),
				'governanceBodyId' => $result['id'],
				'created' => $result['created'],
			]
		);

		$this->dispatcher->dispatchTyped(
			new GovernanceBodyCreatedEvent(
				governanceBodyId: $result['id'],
				sourceApp: $event->getSourceApp(),
				externalReference: $event->getExternalReference(),
				created: $result['created'],
				correlationId: $event->getCorrelationId(),
			)
		);

	}//end handle()

}//end class
