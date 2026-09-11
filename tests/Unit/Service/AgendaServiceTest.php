<?php

/**
 * Unit tests for AgendaService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.5
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AgendaService.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.5
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
 */
class AgendaServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var AgendaService
	 */
	private AgendaService $service;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock CalendarEventService.
	 *
	 * @var CalendarEventService&MockObject
	 */
	private CalendarEventService&MockObject $calendarEventService;

	/**
	 * Mock INotificationManager.
	 *
	 * @var INotificationManager&MockObject
	 */
	private INotificationManager&MockObject $notificationManager;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ParticipantResolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * Set up mocks and service instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->calendarEventService = $this->createMock(CalendarEventService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);

		$this->service = new AgendaService(
			objectService: $this->objectService,
			calendarEventService: $this->calendarEventService,
			notificationManager: $this->notificationManager,
			logger: $this->logger,
			participantResolver: $this->participantResolver,
		);

	}//end setUp()

	// -----------------------------------------------------------------------
	// publishAgenda tests
	// -----------------------------------------------------------------------

	/**
	 * publishAgenda throws InvalidArgumentException when no items exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testPublishAgendaThrowsWhenNoItems(): void {
		$this->objectService
			->method('findAll')
			->willReturnCallback(function (array $config) {
				if (($config['filters']['schema'] ?? '') === 'agenda-item') {
					return [];
				}

				return [];
			});

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/no agenda items/i');

		$this->service->publishAgenda('meeting-uuid-1');

	}//end testPublishAgendaThrowsWhenNoItems()

	/**
	 * publishAgenda sends notifications only to active participants (leftAt === null).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testPublishAgendaNotifiesOnlyActiveParticipants(): void {
		$meetingId = 'meeting-uuid-1';

		$participants = [
			['displayName' => 'Alice', 'leftAt' => null, 'owner' => 'alice'],
			['displayName' => 'Bob',   'leftAt' => '2025-01-01T00:00:00Z', 'owner' => 'bob'],
			['displayName' => 'Carol', 'leftAt' => null, 'owner' => 'carol'],
		];

		// Meeting entity stub for the #315 full-object read in publishAgenda.
		$meetingEntity = $this->createMock(ObjectEntity::class);
		$meetingEntity->method('jsonSerialize')->willReturn(
			['id' => $meetingId, 'title' => 'Test Meeting', 'lifecycle' => 'scheduled']
		);

		$this->objectService
			->method('find')
			->willReturn($meetingEntity);

		// Agenda-item query returns one item so the not-empty check passes.
		$this->objectService
			->method('findAll')
			->willReturnCallback(function (array $config) {
				if (($config['filters']['schema'] ?? '') === 'agenda-item') {
					return [['id' => 'item-1', 'title' => 'Item 1']];
				}

				return [];
			});

		// Participants now come from ParticipantResolver (canonical path).
		$this->participantResolver
			->method('resolveMeetingParticipants')
			->with($meetingId)
			->willReturn($participants);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();

		$this->notificationManager
			->method('createNotification')
			->willReturn($notification);

		// Only 2 active participants → notify() called exactly 2 times.
		$this->notificationManager
			->expects($this->exactly(2))
			->method('notify');

		$this->objectService
			->expects($this->atLeastOnce())
			->method('saveObject');

		$this->service->publishAgenda($meetingId);

	}//end testPublishAgendaNotifiesOnlyActiveParticipants()

	// -----------------------------------------------------------------------
	// advanceBobPhase tests
	// -----------------------------------------------------------------------

	/**
	 * advanceBobPhase correctly cycles through the BOB phase sequence.
	 *
	 * Each step must be written through patchObject() with a status-only
	 * payload: a uuid-bearing saveObject() is a full replace that OpenRegister
	 * validates whole, so a partial save 400s on the required fields it omits
	 * (#1104). The assertion therefore pins BOTH the next phase and the patch
	 * path, and saveObject() must never be reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testAdvanceBobPhaseCyclesThroughPhases(): void {
		$transitions = [
			['from' => 'voorstel',        'to' => 'beeldvorming'],
			['from' => 'beeldvorming',    'to' => 'oordeelsvorming'],
			['from' => 'oordeelsvorming', 'to' => 'besluitvorming'],
			['from' => 'besluitvorming',  'to' => 'completed'],
		];

		foreach ($transitions as $t) {
			$itemId = 'item-' . $t['from'];
			$itemData = ['id' => $itemId, 'itemType' => 'decision', 'status' => $t['from']];

			// A fresh double per iteration so expectations do not accumulate.
			$objectService = $this->createMock(ObjectServiceInterface::class);
			$objectService->method('find')->willReturn($this->entity($itemData));
			$objectService->expects($this->never())->method('saveObject');

			$patches = [];
			$objectService
				->expects($this->once())
				->method('patchObject')
				->willReturnCallback(
					function (string $objectId, array $data) use (&$patches): ObjectEntity {
						$patches[] = ['id' => $objectId, 'data' => $data];
						return $this->entity($data);
					}
				);

			$freshService = new AgendaService(
				objectService: $objectService,
				calendarEventService: $this->calendarEventService,
				notificationManager: $this->notificationManager,
				logger: $this->logger,
				participantResolver: $this->participantResolver,
			);

			$freshService->advanceBobPhase($itemId);

			$this->assertSame(
				[['id' => $itemId, 'data' => ['status' => $t['to']]]],
				$patches,
				"'{$t['from']}' must advance to '{$t['to']}' with a status-only patch"
			);
		}//end foreach

	}//end testAdvanceBobPhaseCyclesThroughPhases()

	/**
	 * advanceBobPhase throws NotFoundException when the item does not exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testAdvanceBobPhaseThrowsWhenItemNotFound(): void {
		$this->objectService
			->method('find')
			->willReturn(null);

		$this->expectException(\OCA\Decidiq\Exception\NotFoundException::class);

		$this->service->advanceBobPhase('missing-uuid');

	}//end testAdvanceBobPhaseThrowsWhenItemNotFound()

	/**
	 * advanceBobPhase throws for informational items, and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testAdvanceBobPhaseThrowsForInformationalItem(): void {
		$itemId = 'item-info';
		$itemData = ['id' => $itemId, 'itemType' => 'informational', 'status' => 'beeldvorming'];

		$this->objectService
			->method('find')
			->willReturn($this->entity($itemData));
		$this->objectService->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/informational/i');

		$this->service->advanceBobPhase($itemId);

	}//end testAdvanceBobPhaseThrowsForInformationalItem()

	/**
	 * advanceBobPhase throws when item is already at final phase 'completed'.
	 *
	 * `completed` is the English data value the Dutch `afgerond` was renamed to
	 * (lib/Repair/RenameDutchDecidiqValues.php, `status` map), which is why the
	 * spec prose still reads `afgerond`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testAdvanceBobPhaseThrowsAtFinalPhase(): void {
		$itemId = 'item-final';
		$itemData = ['id' => $itemId, 'itemType' => 'decision', 'status' => 'completed'];

		$this->objectService
			->method('find')
			->willReturn($this->entity($itemData));
		$this->objectService->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/final phase/i');

		$this->service->advanceBobPhase($itemId);

	}//end testAdvanceBobPhaseThrowsAtFinalPhase()

	// -----------------------------------------------------------------------
	// processHamerstukken tests
	// -----------------------------------------------------------------------

	/**
	 * processHamerstukken bulk-updates only items tagged 'hamerstuk'.
	 *
	 * The items come back from findAll() as entities, the way OpenRegister
	 * returns them, and each tagged one must be patched to 'completed'. The
	 * untagged item-2 must be left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testProcessHamerstukkenUpdatesTaggedItemsOnly(): void {
		$meetingId = 'meeting-uuid-1';
		$items = [
			['id' => 'item-1', 'title' => 'Item 1', 'tags' => ['hamerstuk'], 'status' => 'besluitvorming'],
			['id' => 'item-2', 'title' => 'Item 2', 'tags' => [],            'status' => 'beeldvorming'],
			['id' => 'item-3', 'title' => 'Item 3', 'tags' => ['hamerstuk'], 'status' => 'oordeelsvorming'],
		];

		$this->objectService
			->method('findAll')
			->willReturn(array_map(fn (array $item): ObjectEntity => $this->entity($item), $items));
		$this->objectService->expects($this->never())->method('saveObject');

		$patches = $this->capturePatches();

		$this->service->processHamerstukken($meetingId);

		$this->assertSame(
			[
				['id' => 'item-1', 'data' => ['status' => 'completed']],
				['id' => 'item-3', 'data' => ['status' => 'completed']],
			],
			$patches->getArrayCopy()
		);

	}//end testProcessHamerstukkenUpdatesTaggedItemsOnly()

	// -----------------------------------------------------------------------
	// reorderItems tests
	// -----------------------------------------------------------------------

	/**
	 * reorderItems assigns sequential orderNumber values 1..n.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testReorderItemsAssignsSequentialNumbers(): void {
		$meetingId = 'meeting-uuid-1';
		$orderedIds = ['item-c', 'item-a', 'item-b'];

		// Return the meeting's items so all IDs pass the ownership check.
		$this->objectService
			->method('findAll')
			->willReturn(
				[
					$this->entity(['id' => 'item-a']),
					$this->entity(['id' => 'item-b']),
					$this->entity(['id' => 'item-c']),
				]
			);
		$this->objectService->expects($this->never())->method('saveObject');

		$patches = $this->capturePatches();

		$this->service->reorderItems($meetingId, $orderedIds);

		$this->assertSame(
			[
				['id' => 'item-c', 'data' => ['orderNumber' => 1]],
				['id' => 'item-a', 'data' => ['orderNumber' => 2]],
				['id' => 'item-b', 'data' => ['orderNumber' => 3]],
			],
			$patches->getArrayCopy()
		);

	}//end testReorderItemsAssignsSequentialNumbers()

	// -----------------------------------------------------------------------
	// helpers
	// -----------------------------------------------------------------------

	/**
	 * Record every patchObject() call on the shared object-service double.
	 *
	 * @return \ArrayObject<int, array{id: string, data: array<string, mixed>}> The live capture
	 */
	private function capturePatches(): \ArrayObject {
		$patches = new \ArrayObject();
		$this->objectService
			->method('patchObject')
			->willReturnCallback(
				function (string $objectId, array $data) use ($patches): ObjectEntity {
					$patches->append(['id' => $objectId, 'data' => $data]);
					return $this->entity($data);
				}
			);

		return $patches;

	}//end capturePatches()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it verbatim.
	 *
	 * The same double VotingServiceCastAsTest uses: OpenRegister returns
	 * entities, never arrays, from find(), findAll() and the write methods.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;

	}//end entity()
}//end class
