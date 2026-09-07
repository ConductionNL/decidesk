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

use OCA\Decidiq\Event\ApprovalActionRequestedEvent;
use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Decidiq\Listener\ApprovalActionRequestedListener;
use OCA\Decidiq\Listener\ApprovalRouteRequestedListener;
use OCA\Decidiq\Service\ApprovalRouteCommandService;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the two approval-route command listeners.
 *
 * The refusal test is the one worth reading. The engine refuses for several
 * different reasons — the actor is not the one named, there is nothing active
 * to act on, a mandatory stage cannot be skipped — and a producer needs to tell
 * them apart. A listener that reported a bare false would collapse all of them
 * into one indistinguishable failure.
 */
class ApprovalRouteListenersTest extends TestCase {

	/**
	 * A route command.
	 *
	 * @param string $subject Optional subject to travel it.
	 *
	 * @return ApprovalRouteRequestedEvent The event.
	 */
	private function routeEvent(string $subject = ''): ApprovalRouteRequestedEvent {
		return new ApprovalRouteRequestedEvent(
			'dossiq',
			'pr-1',
			'Collegeadvies parafering',
			[['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice']],
			'collegeadvies',
			'',
			true,
			$subject,
			'proposal',
			'admin',
			'corr-1',
		);

	}//end routeEvent()

	/**
	 * An action command.
	 *
	 * @param string $verb The action verb.
	 *
	 * @return ApprovalActionRequestedEvent The event.
	 */
	private function actionEvent(string $verb = 'approved'): ApprovalActionRequestedEvent {
		return new ApprovalActionRequestedEvent(
			'dossiq',
			'voorstel-1',
			'alice',
			$verb,
			0,
			'akkoord',
			'',
			'user',
			'',
			'',
			'corr-9',
		);

	}//end actionEvent()

	/**
	 * A handled route command writes every result slot.
	 *
	 * @return void
	 */
	public function testHandledRouteCommandWritesResultSlots(): void {
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->method('holdRoute')->willReturn(['id' => 'ar-1', 'created' => true, 'stageCount' => 3]);

		$event = $this->routeEvent(subject: 'voorstel-1');
		$listener = new ApprovalRouteRequestedListener($service, $this->createMock(LoggerInterface::class));
		$listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertTrue($event->isCreated());
		$this->assertSame('ar-1', $event->getRouteId());
		$this->assertSame(3, $event->getStageCount());

	}//end testHandledRouteCommandWritesResultSlots()

	/**
	 * The template reaches the service intact.
	 *
	 * @return void
	 */
	public function testTemplateReachesTheService(): void {
		$seen = [];
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->method('holdRoute')->willReturnCallback(
			static function (string $app, string $ref, array $template, string $subject = '', string $schema = '') use (&$seen): array {
				$seen = compact('app', 'ref', 'template', 'subject', 'schema');

				return ['id' => 'ar-1', 'created' => true, 'stageCount' => 1];
			}
		);

		$listener = new ApprovalRouteRequestedListener($service, $this->createMock(LoggerInterface::class));
		$listener->handle($this->routeEvent(subject: 'voorstel-1'));

		$this->assertSame('dossiq', $seen['app']);
		$this->assertSame('pr-1', $seen['ref']);
		$this->assertSame('Collegeadvies parafering', $seen['template']['name']);
		$this->assertSame('collegeadvies', $seen['template']['subjectType']);
		$this->assertTrue($seen['template']['isDefault']);
		$this->assertCount(1, $seen['template']['steps']);
		$this->assertSame('voorstel-1', $seen['subject']);
		$this->assertSame('proposal', $seen['schema']);

	}//end testTemplateReachesTheService()

	/**
	 * A failing route command leaves the event unhandled and throws nothing.
	 *
	 * @return void
	 */
	public function testFailingRouteCommandThrowsNothing(): void {
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->method('holdRoute')->willThrowException(new RuntimeException('register down'));

		$event = $this->routeEvent();
		$listener = new ApprovalRouteRequestedListener($service, $this->createMock(LoggerInterface::class));
		$listener->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertSame('', $event->getRouteId());

	}//end testFailingRouteCommandThrowsNothing()

	/**
	 * A non-final action reports handled but announces nothing.
	 *
	 * @return void
	 */
	public function testNonFinalActionAnnouncesNothing(): void {
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->method('recordAction')->willReturn(['recorded' => true, 'completed' => false]);

		$announcer = $this->createMock(ApprovalRouteConclusionAnnouncer::class);
		$announcer->expects($this->never())->method('announceIfConcluded');

		$event = $this->actionEvent();
		$listener = new ApprovalActionRequestedListener($service, $announcer, $this->createMock(LoggerInterface::class));
		$listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertTrue($event->isRecorded());
		$this->assertFalse($event->isCompleted());

	}//end testNonFinalActionAnnouncesNothing()

	/**
	 * A final action reaches the shared announcer with its correlation.
	 *
	 * The event's payload itself is the announcer's business and is pinned in
	 * ApprovalRouteConclusionAnnouncerTest; what this listener owes is exactly
	 * one announcement, through the one shared door, echoing the request's
	 * correlation id.
	 *
	 * @return void
	 */
	public function testFinalActionAnnouncesTheConclusion(): void {
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->method('recordAction')->willReturn(['recorded' => true, 'completed' => true]);

		$announcer = $this->createMock(ApprovalRouteConclusionAnnouncer::class);
		$announcer->expects($this->once())->method('announceIfConcluded')
			->with('voorstel-1', 'corr-9');

		$listener = new ApprovalActionRequestedListener($service, $announcer, $this->createMock(LoggerInterface::class));
		$listener->handle($this->actionEvent());

	}//end testFinalActionAnnouncesTheConclusion()

	/**
	 * A refusal carries the engine's REASON, not a bare false.
	 *
	 * @return void
	 */
	public function testRefusalCarriesTheReason(): void {
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->method('recordAction')->willThrowException(
			new RuntimeException('This stage is assigned to somebody else.')
		);

		$announcer = $this->createMock(ApprovalRouteConclusionAnnouncer::class);
		$announcer->expects($this->never())->method('announceIfConcluded');

		$event = $this->actionEvent();
		$listener = new ApprovalActionRequestedListener($service, $announcer, $this->createMock(LoggerInterface::class));
		$listener->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertFalse($event->isRecorded());
		$this->assertSame('This stage is assigned to somebody else.', $event->getRefusal());

	}//end testRefusalCarriesTheReason()

	/**
	 * The producer never chooses which stage an action lands on.
	 *
	 * @return void
	 */
	public function testProducerCannotChooseTheStage(): void {
		$seen = [];
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->method('recordAction')->willReturnCallback(
			static function (array $action) use (&$seen): array {
				$seen = $action;

				return ['recorded' => true, 'completed' => false];
			}
		);

		$listener = new ApprovalActionRequestedListener(
			$service,
			$this->createMock(ApprovalRouteConclusionAnnouncer::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->actionEvent());

		$this->assertArrayNotHasKey(
			'step',
			$seen,
			'the engine decides which stage is active; a producer-supplied step would file an action against a stage nobody waits on'
		);
		$this->assertSame('voorstel-1', $seen['subject']);
		$this->assertSame('alice', $seen['actor']);
		$this->assertSame('approved', $seen['action']);
		$this->assertSame('akkoord', $seen['comment']);

	}//end testProducerCannotChooseTheStage()

	/**
	 * A return carries its target step through.
	 *
	 * @return void
	 */
	public function testReturnCarriesItsTargetStep(): void {
		$seen = [];
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->method('recordAction')->willReturnCallback(
			static function (array $action) use (&$seen): array {
				$seen = $action;

				return ['recorded' => true, 'completed' => false];
			}
		);

		$event = new ApprovalActionRequestedEvent('dossiq', 'voorstel-1', 'alice', 'returned', 2);
		$listener = new ApprovalActionRequestedListener(
			$service,
			$this->createMock(ApprovalRouteConclusionAnnouncer::class),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle($event);

		$this->assertSame(2, $seen['returnToStep']);

	}//end testReturnCarriesItsTargetStep()

	/**
	 * Unrelated events pass through both listeners untouched.
	 *
	 * @return void
	 */
	public function testUnrelatedEventsAreIgnored(): void {
		$service = $this->createMock(ApprovalRouteCommandService::class);
		$service->expects($this->never())->method('holdRoute');
		$service->expects($this->never())->method('recordAction');

		$other = new class extends Event {
		};

		(new ApprovalRouteRequestedListener($service, $this->createMock(LoggerInterface::class)))->handle($other);
		(new ApprovalActionRequestedListener(
			$service,
			$this->createMock(ApprovalRouteConclusionAnnouncer::class),
			$this->createMock(LoggerInterface::class),
		))->handle($other);

		$this->addToAssertionCount(1);

	}//end testUnrelatedEventsAreIgnored()

}//end class
