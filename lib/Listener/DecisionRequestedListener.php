<?php

/**
 * Decidiq DecisionRequestedListener
 *
 * Maps an inbound cross-app DecisionRequestedEvent to the existing
 * DecisionIntegrationService::createDecision() create logic (idempotent,
 * provenance-persisting). The in-process replacement for the broken
 * IntegrationService::getLeaf HTTP delegation path: any installed consumer app
 * dispatches DecisionRequestedEvent and Decidiq raises the Decision here,
 * writing the resolved decisionId back onto the event for the synchronous
 * producer.
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
 * @spec openspec/specs/decidesk-decision-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Handles DecisionRequestedEvent by delegating to DecisionIntegrationService.
 *
 * Reuses the existing create logic (ADR-022, no parallel CRUD): builds the
 * decision-data array from the event's subject reference + provenance + body
 * payload and calls createDecision() POSITIONALLY. On success the resolved
 * decisionId + handled flag are written back onto the event; on failure the
 * listener logs and leaves the event unhandled — no exception escapes into the
 * dispatcher.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/decidesk-decision-events/spec.md
 */
class DecisionRequestedListener implements IEventListener {

	/**
	 * Body payload keys forwarded onto the decision-data array.
	 *
	 * @var list<string>
	 */
	private const BODY_FIELDS = ['title', 'text', 'decisionDate', 'outcome'];

	/**
	 * Constructor.
	 *
	 * @param DecisionIntegrationService $integrationService The reused create-decision service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly DecisionIntegrationService $integrationService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a DecisionRequestedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof DecisionRequestedEvent === false) {
			return;
		}

		try {
			$decisionData = $this->buildDecisionData(event: $event);

			$result = $this->integrationService->createDecision(
				$decisionData,
				$event->getActorId()
			);

			if ($result['success'] === true) {
				$decisionId = (string)($result['decisionId'] ?? '');
				if ($decisionId !== '') {
					$event->setDecisionId($decisionId);
				}

				$event->setHandled(true);
				$this->logger->info(
					'Decidiq: handled DecisionRequestedEvent',
					[
						'sourceApp' => $event->getSourceApp(),
						'subjectId' => $event->getSubjectId(),
						'decisionId' => $decisionId,
						'created' => ($result['created'] ?? null),
					]
				);
				return;
			}

			// Non-success service result: leave the event unhandled (the producer
			// sees isHandled() === false) and log the reason. Never throw.
			$this->logger->warning(
				'Decidiq: DecisionRequestedEvent not handled',
				[
					'sourceApp' => $event->getSourceApp(),
					'subjectId' => $event->getSubjectId(),
					'message' => ($result['message'] ?? 'unknown'),
				]
			);
		} catch (\Throwable $e) {
			// The event bus must never surface a Decidiq failure as an exception
			// to the dispatching consumer; log and leave unhandled.
			$this->logger->error(
				'Decidiq: DecisionRequestedListener failed',
				[
					'sourceApp' => $event->getSourceApp(),
					'subjectId' => $event->getSubjectId(),
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end handle()

	/**
	 * Build the decision-data array createDecision() expects from the event.
	 *
	 * @param DecisionRequestedEvent $event The request event
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return array<string, mixed>
	 */
	private function buildDecisionData(DecisionRequestedEvent $event): array {
		$decisionData = [
			'decisionType' => $event->getDecisionType(),
			'sourceApp' => $event->getSourceApp(),
			'subjectRegister' => $event->getSubjectRegister(),
			'subjectSchema' => $event->getSubjectSchema(),
			'subjectId' => $event->getSubjectId(),
			'subjectLabel' => $event->getSubjectLabel(),
			'externalReference' => $event->getExternalReference(),
		];

		// Forward recognised body fields from the request payload (title/text/
		// decisionDate/outcome); the service applies its own defaults for any
		// absent value.
		$payload = $event->getPayload();
		foreach (self::BODY_FIELDS as $field) {
			if (array_key_exists($field, $payload) === true) {
				$decisionData[$field] = $payload[$field];
			}
		}

		// Forward the delegation context too: the delegating app sends the ask
		// itself in there (dossiq's requestDecision node sends
		// `context.question`), and createDecision() derives the schema-required
		// `text` from it when no explicit text was supplied. Dropping the
		// context here is what left flow-raised decisions without a `text`,
		// schema-invalid from birth.
		$context = ($payload['context'] ?? null);
		if (is_array($context) === true) {
			$decisionData['context'] = $context;
		}

		return $decisionData;
	}//end buildDecisionData()
}//end class
