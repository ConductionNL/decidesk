<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Event\ApprovalRouteConcludedEvent;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the one door a concluded route leaves by.
 *
 * The payload assertions matter more than the dispatch: dossiq writes its
 * parafeeractie case records FROM this event's action trail, so an event that
 * fired with a hollow payload would conclude the voorstel while losing the
 * who-signed-what-when record — a loss of record dressed as a notification.
 */
class ApprovalRouteConclusionAnnouncerTest extends TestCase {

	/**
	 * Build an announcer over canned stages, route rows and actions.
	 *
	 * @param array<int, array<string, mixed>> $stages The engine's answer.
	 * @param array<int, array<string, mixed>> $routes The approval-route rows.
	 * @param array<int, array<string, mixed>> $actions The approval-action rows.
	 * @param array<int, Event> $announced Collector for dispatched events.
	 *
	 * @return ApprovalRouteConclusionAnnouncer The announcer.
	 */
	private function announcer(array $stages, array $routes, array $actions, array &$announced): ApprovalRouteConclusionAnnouncer {
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->method('stagesFor')->willReturn($stages);

		$store = $this->createMock(RegisterObjectStore::class);
		// The route row resolves through find() BY UUID, like live
		// OpenRegister. The findAll(['id' => ...]) form the announcer used to
		// call matches nothing live — the defect that silenced every
		// cross-app conclusion — so this fake's findAll refuses it too: a
		// fake that resolved it would agree with the caller and could not
		// fail.
		$store->method('find')->willReturnCallback(
			static function (string $schema, string $uuid) use ($routes): ?array {
				foreach ($routes as $row) {
					if ((string)($row['id'] ?? '') === $uuid) {
						return $row;
					}
				}

				return null;
			}
		);
		$store->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters) use ($actions): array {
				if (isset($filters['id']) === true || isset($filters['uuid']) === true) {
					// Live OR: identity lives in @self, so this matches nothing.
					return [];
				}

				return $actions;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $e) use (&$announced): void {
				$announced[] = $e;
			}
		);

		return new ApprovalRouteConclusionAnnouncer(
			$engine,
			$store,
			$dispatcher,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A concluded stage set.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function concludedStages(): array {
		return [
			['id' => 's-1', 'sequence' => 1, 'status' => 'decided', 'outcome' => 'endorsed', 'note' => 'proposal', 'route' => 'route-77'],
			['id' => 's-2', 'sequence' => 2, 'status' => 'decided', 'outcome' => 'approved', 'note' => 'proposal', 'route' => 'route-77'],
		];
	}

	/**
	 * A concluded route with a producer announces the full record.
	 *
	 * @return void
	 */
	public function testAConcludedRouteAnnouncesTheFullRecord(): void {
		$announced = [];
		$announcer = $this->announcer(
			stages: $this->concludedStages(),
			routes: [['id' => 'route-77', 'sourceApp' => 'dossiq', 'externalReference' => 'pr-1']],
			actions: [
				['id' => 'a-2', 'actor' => 'carol', 'action' => 'approved', 'recordedAt' => '2026-09-02T11:00:00+00:00'],
				['id' => 'a-1', 'actor' => 'alice', 'action' => 'endorsed', 'recordedAt' => '2026-09-02T10:00:00+00:00'],
			],
			announced: $announced,
		);

		$announcer->announceIfConcluded(subject: 'v-1');

		$this->assertCount(1, $announced);
		$event = $announced[0];
		$this->assertInstanceOf(ApprovalRouteConcludedEvent::class, $event);
		$this->assertSame('v-1', $event->getSubject());
		$this->assertSame('dossiq', $event->getSourceApp());
		$this->assertSame('approved', $event->getOutcome());
		$this->assertSame('carol', $event->getActor());
		$this->assertSame('proposal', $event->getSubjectSchema());
		$this->assertSame('pr-1', $event->getExternalReference());
		$this->assertSame('pr-1', $event->getCorrelationId(), 'No caller correlation falls back to the external reference.');
		$this->assertSame(
			['a-1', 'a-2'],
			array_map(static fn (array $a): string => (string)$a['id'], $event->getActions()),
			'The action trail travels CHRONOLOGICALLY, however the store returned it.'
		);
	}

	/**
	 * A caller-supplied correlation id wins over the resolved one.
	 *
	 * @return void
	 */
	public function testACallerCorrelationWins(): void {
		$announced = [];
		$announcer = $this->announcer(
			stages: $this->concludedStages(),
			routes: [['id' => 'route-77', 'sourceApp' => 'dossiq', 'externalReference' => 'pr-1']],
			actions: [],
			announced: $announced,
		);

		$announcer->announceIfConcluded(subject: 'v-1', correlationId: 'corr-9');

		$this->assertSame('corr-9', $announced[0]->getCorrelationId());
	}

	/**
	 * A route still holding an active stage announces nothing.
	 *
	 * @return void
	 */
	public function testATravellingRouteAnnouncesNothing(): void {
		$stages = $this->concludedStages();
		$stages[1]['status'] = 'active';
		$stages[1]['outcome'] = '';

		$announced = [];
		$this->announcer(
			stages: $stages,
			routes: [['id' => 'route-77', 'sourceApp' => 'dossiq', 'externalReference' => 'pr-1']],
			actions: [],
			announced: $announced,
		)->announceIfConcluded(subject: 'v-1');

		$this->assertSame([], $announced);
	}

	/**
	 * An internal route (no source app) announces nothing.
	 *
	 * @return void
	 */
	public function testAnInternalRouteAnnouncesNothing(): void {
		$announced = [];
		$this->announcer(
			stages: $this->concludedStages(),
			routes: [['id' => 'route-77', 'sourceApp' => '', 'externalReference' => '']],
			actions: [],
			announced: $announced,
		)->announceIfConcluded(subject: 'v-1');

		$this->assertSame([], $announced);
	}

	/**
	 * An instantiated-but-untouched route announces nothing.
	 *
	 * No stage decided anything, so there is no conclusion — only a route
	 * nobody has started signing.
	 *
	 * @return void
	 */
	public function testAnUntouchedRouteAnnouncesNothing(): void {
		$announced = [];
		$this->announcer(
			stages: [
				['id' => 's-1', 'sequence' => 1, 'status' => 'pending', 'outcome' => '', 'note' => 'proposal', 'route' => 'route-77'],
			],
			routes: [['id' => 'route-77', 'sourceApp' => 'dossiq', 'externalReference' => 'pr-1']],
			actions: [],
			announced: $announced,
		)->announceIfConcluded(subject: 'v-1');

		$this->assertSame([], $announced);
	}
}
