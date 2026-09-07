<?php

/**
 * Unit tests for DecisionRequestedListener.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
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

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCA\Decidiq\Listener\DecisionRequestedListener;
use OCA\Decidiq\Service\DecisionIntegrationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener maps a DecisionRequestedEvent onto createDecision(). The
 * delegation context MUST travel with it: dossiq sends the ask itself as
 * `context.question`, and createDecision() derives the schema-required `text`
 * from it. Dropping the context left flow-raised decisions without a text,
 * schema-invalid from birth (observed live: decision 7f2dc8f4).
 *
 * @covers \OCA\Decidiq\Listener\DecisionRequestedListener
 * @uses \OCA\Decidiq\Event\DecisionRequestedEvent
 *
 * @spec openspec/specs/decidesk-decision-events/spec.md
 */
class DecisionRequestedListenerTest extends TestCase {

	/**
	 * The listener forwards subject/provenance fields, recognised body fields
	 * AND the delegation context onto createDecision(), then writes the
	 * resolved decisionId back onto the event.
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return void
	 */
	public function testHandleForwardsDelegationContext(): void {
		$event = new DecisionRequestedEvent(
			sourceApp: 'dossiq',
			subjectRegister: 'dossiq',
			subjectSchema: 'case',
			subjectId: 'case-9',
			subjectLabel: 'Vergunning Hoofdstraat 12',
			decisionType: 'advice',
			actorId: 'alice',
			payload: [
				'title' => 'Vergunning Hoofdstraat 12',
				'context' => [
					'question' => 'Kan de vergunning worden verleend?',
					'advisor' => 'jan',
				],
			],
			externalReference: 'case-9',
		);

		$captured = null;
		$integration = $this->createMock(DecisionIntegrationService::class);
		$integration->method('createDecision')->willReturnCallback(
			function (array $decisionData) use (&$captured): array {
				$captured = $decisionData;
				return ['success' => true, 'decisionId' => 'dec-1', 'created' => true];
			}
		);

		$listener = new DecisionRequestedListener(
			integrationService: $integration,
			logger: $this->createMock(LoggerInterface::class),
		);

		$listener->handle($event);

		self::assertIsArray($captured);
		self::assertSame('advice', $captured['decisionType']);
		self::assertSame('dossiq', $captured['sourceApp']);
		self::assertSame(
			['question' => 'Kan de vergunning worden verleend?', 'advisor' => 'jan'],
			$captured['context'] ?? null,
			'The delegation context must reach createDecision(), or the derived text has nothing to derive from'
		);
		self::assertTrue($event->isHandled());
		self::assertSame('dec-1', $event->getDecisionId());

	}//end testHandleForwardsDelegationContext()

	/**
	 * A payload without a context array forwards no context key at all — the
	 * service falls back to its documented source-naming sentence.
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return void
	 */
	public function testHandleWithoutContextForwardsNone(): void {
		$event = new DecisionRequestedEvent(
			sourceApp: 'dossiq',
			subjectRegister: 'dossiq',
			subjectSchema: 'case',
			subjectId: 'case-42',
			decisionType: 'bezwaar-decision',
			actorId: 'alice',
			payload: [],
			externalReference: 'case-42',
		);

		$captured = null;
		$integration = $this->createMock(DecisionIntegrationService::class);
		$integration->method('createDecision')->willReturnCallback(
			function (array $decisionData) use (&$captured): array {
				$captured = $decisionData;
				return ['success' => true, 'decisionId' => 'dec-2', 'created' => true];
			}
		);

		$listener = new DecisionRequestedListener(
			integrationService: $integration,
			logger: $this->createMock(LoggerInterface::class),
		);

		$listener->handle($event);

		self::assertIsArray($captured);
		self::assertArrayNotHasKey('context', $captured);

	}//end testHandleWithoutContextForwardsNone()
}//end class
