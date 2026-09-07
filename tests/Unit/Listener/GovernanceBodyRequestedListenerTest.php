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

use OCA\Decidiq\Event\GovernanceBodyCreatedEvent;
use OCA\Decidiq\Event\GovernanceBodyRequestedEvent;
use OCA\Decidiq\Listener\GovernanceBodyRequestedListener;
use OCA\Decidiq\Service\GovernanceBodyCommandService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the governance-body command listener.
 *
 * The failure test is the load-bearing one. An exception out of handle() aborts
 * the whole typed dispatch, so a listener that rethrows takes down every other
 * listener on the same event — and the producer, which reads its answer off the
 * event instance, would never reach the line where it checks isHandled().
 */
class GovernanceBodyRequestedListenerTest extends TestCase {

	/**
	 * A request event.
	 *
	 * @return GovernanceBodyRequestedEvent The event.
	 */
	private function event(): GovernanceBodyRequestedEvent {
		return new GovernanceBodyRequestedEvent(
			'dossiq',
			'cmte-1',
			'Bezwaarcommissie sociaal domein',
			'advisory-body',
			'social_domain',
			true,
			['statutoryBasis' => 'Awb 7:13'],
			[['uid' => 'alice', 'role' => 'chair']],
			'admin',
			'corr-1',
		);

	}//end event()

	/**
	 * A handled command writes the result slots and announces the conclusion.
	 *
	 * @return void
	 */
	public function testHandledCommandWritesResultSlotsAndAnnounces(): void {
		$service = $this->createMock(GovernanceBodyCommandService::class);
		$service->method('upsert')->willReturn(['id' => 'gb-9', 'created' => true]);

		$announced = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $e) use (&$announced): void {
				$announced[] = $e;
			}
		);

		$event = $this->event();
		$listener = new GovernanceBodyRequestedListener(
			$service,
			$dispatcher,
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertTrue($event->isCreated());
		$this->assertSame('gb-9', $event->getGovernanceBodyId());

		$this->assertCount(1, $announced);
		$conclusion = $announced[0];
		$this->assertInstanceOf(GovernanceBodyCreatedEvent::class, $conclusion);
		$this->assertSame('corr-1', $conclusion->getCorrelationId());
		$this->assertSame('gb-9', $conclusion->getGovernanceBodyId());
		$this->assertSame('dossiq', $conclusion->getSourceApp());
		$this->assertSame('cmte-1', $conclusion->getExternalReference());
		$this->assertTrue($conclusion->isCreated());

	}//end testHandledCommandWritesResultSlotsAndAnnounces()

	/**
	 * A failing command leaves the event unhandled and throws nothing.
	 *
	 * @return void
	 */
	public function testFailureLeavesEventUnhandledAndThrowsNothing(): void {
		$service = $this->createMock(GovernanceBodyCommandService::class);
		$service->method('upsert')->willThrowException(new RuntimeException('register down'));

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$event = $this->event();
		$listener = new GovernanceBodyRequestedListener(
			$service,
			$dispatcher,
			$this->createMock(LoggerInterface::class),
		);

		$listener->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertSame('', $event->getGovernanceBodyId());
		$this->assertFalse($event->isCreated());

	}//end testFailureLeavesEventUnhandledAndThrowsNothing()

	/**
	 * A matched body reports created = false.
	 *
	 * @return void
	 */
	public function testMatchedBodyReportsNotCreated(): void {
		$service = $this->createMock(GovernanceBodyCommandService::class);
		$service->method('upsert')->willReturn(['id' => 'gb-9', 'created' => false]);

		$event = $this->event();
		$listener = new GovernanceBodyRequestedListener(
			$service,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertFalse($event->isCreated());

	}//end testMatchedBodyReportsNotCreated()

	/**
	 * An unrelated event passes through untouched.
	 *
	 * @return void
	 */
	public function testUnrelatedEventIsIgnored(): void {
		$service = $this->createMock(GovernanceBodyCommandService::class);
		$service->expects($this->never())->method('upsert');

		$listener = new GovernanceBodyRequestedListener(
			$service,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(LoggerInterface::class),
		);

		$listener->handle(new class extends Event {
		});

		$this->addToAssertionCount(1);

	}//end testUnrelatedEventIsIgnored()

	/**
	 * The four stated fields reach the service alongside the attribute bag.
	 *
	 * @return void
	 */
	public function testStatedFieldsReachTheService(): void {
		$seen = [];
		$service = $this->createMock(GovernanceBodyCommandService::class);
		$service->method('upsert')->willReturnCallback(
			static function (string $app, string $ref, array $body, array $members) use (&$seen): array {
				$seen = ['app' => $app, 'ref' => $ref, 'body' => $body, 'members' => $members];

				return ['id' => 'gb-9', 'created' => true];
			}
		);

		$listener = new GovernanceBodyRequestedListener(
			$service,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->event());

		$this->assertSame('dossiq', $seen['app']);
		$this->assertSame('cmte-1', $seen['ref']);
		$this->assertSame('Bezwaarcommissie sociaal domein', $seen['body']['name']);
		$this->assertSame('advisory-body', $seen['body']['bodyType']);
		$this->assertSame('social_domain', $seen['body']['domain']);
		$this->assertTrue($seen['body']['active']);
		$this->assertSame('Awb 7:13', $seen['body']['statutoryBasis']);
		$this->assertSame([['uid' => 'alice', 'role' => 'chair']], $seen['members']);

	}//end testStatedFieldsReachTheService()

}//end class
