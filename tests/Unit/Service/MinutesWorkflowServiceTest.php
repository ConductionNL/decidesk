<?php

/**
 * Unit tests for MinutesWorkflowService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\ActionItemExtractionService;
use OCA\Decidiq\Service\MinutesService;
use OCA\Decidiq\Service\MinutesWorkflowService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MinutesWorkflowService.
 *
 * These cover the extraction and approval-submission behaviour that used to run
 * inline in MinutesController: that a missing record is a MissingObjectException,
 * that a published or non-draft record is refused with the HTTP status the
 * endpoint should report, and that a successful submission actually persists the
 * review transition AND notifies the approvers.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
 */
class MinutesWorkflowServiceTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock ActionItemExtractionService.
	 *
	 * @var ActionItemExtractionService&MockObject
	 */
	private ActionItemExtractionService&MockObject $extractionService;

	/**
	 * Mock MinutesService.
	 *
	 * @var MinutesService&MockObject
	 */
	private MinutesService&MockObject $minutesService;

	/**
	 * The service under test.
	 *
	 * @var MinutesWorkflowService
	 */
	private MinutesWorkflowService $service;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->extractionService = $this->createMock(originalClassName: ActionItemExtractionService::class);
		$this->minutesService = $this->createMock(originalClassName: MinutesService::class);

		$this->service = new MinutesWorkflowService(
			objectService: $this->objectService,
			extractionService: $this->extractionService,
			minutesService: $this->minutesService,
		);

	}//end setUp()

	/**
	 * Build a mock ObjectEntity that serialises to $data.
	 *
	 * @param array<string,mixed> $data The object data
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function makeEntity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end makeEntity()

	/**
	 * An unknown Minutes record is a MissingObjectException, not an empty result.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 *
	 * @return void
	 */
	public function testExtractActionItemsThrowsWhenMinutesMissing(): void {
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(MissingObjectException::class);

		$this->service->extractActionItems(minutesId: 'minutes-999');

	}//end testExtractActionItemsThrowsWhenMinutesMissing()

	/**
	 * Extraction runs against the stored minutes content.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 *
	 * @return void
	 */
	public function testExtractActionItemsPassesStoredContentToTheExtractor(): void {
		$this->objectService->method('find')->willReturn(
			$this->makeEntity(['id' => 'minutes-001', 'content' => 'Actie: Jan doet X'])
		);

		$this->extractionService->expects($this->once())
			->method('extractFromContent')
			->with('Actie: Jan doet X')
			->willReturn([['title' => 'Jan doet X']]);

		$result = $this->service->extractActionItems(minutesId: 'minutes-001');

		self::assertSame([['title' => 'Jan doet X']], $result);

	}//end testExtractActionItemsPassesStoredContentToTheExtractor()

	/**
	 * Published minutes refuse new action items with a 400-shaped exception.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 *
	 * @return void
	 */
	public function testSaveExtractedActionItemsRefusesPublishedMinutes(): void {
		$this->objectService->method('find')->willReturn(
			$this->makeEntity(['id' => 'minutes-001', 'lifecycle' => 'published'])
		);

		$this->extractionService->expects($this->never())->method('saveExtracted');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionCode(400);

		$this->service->saveExtractedActionItems(minutesId: 'minutes-001', confirmed: []);

	}//end testSaveExtractedActionItemsRefusesPublishedMinutes()

	/**
	 * Draft minutes accept confirmed action items.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 *
	 * @return void
	 */
	public function testSaveExtractedActionItemsPersistsOnDraftMinutes(): void {
		$this->objectService->method('find')->willReturn(
			$this->makeEntity(['id' => 'minutes-001', 'lifecycle' => 'draft'])
		);

		$this->extractionService->expects($this->once())
			->method('saveExtracted')
			->willReturn(3);

		self::assertSame(
			3,
			$this->service->saveExtractedActionItems(
				minutesId: 'minutes-001',
				confirmed: [['title' => 'Jan doet X']]
			)
		);

	}//end testSaveExtractedActionItemsPersistsOnDraftMinutes()

	/**
	 * Non-draft minutes cannot be submitted for approval (409).
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testSubmitForApprovalRefusesNonDraftMinutes(): void {
		$this->objectService->method('find')->willReturn(
			$this->makeEntity(['id' => 'minutes-001', 'lifecycle' => 'review'])
		);

		$this->objectService->expects($this->never())->method('saveObject');
		$this->minutesService->expects($this->never())->method('notifyApproversOnSubmit');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionCode(409);

		$this->service->submitForApproval(minutesId: 'minutes-001');

	}//end testSubmitForApprovalRefusesNonDraftMinutes()

	/**
	 * Submitting draft minutes persists the review transition AND notifies approvers.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testSubmitForApprovalPersistsReviewAndNotifies(): void {
		$this->objectService->method('find')->willReturn(
			$this->makeEntity(['id' => 'minutes-001', 'lifecycle' => 'draft'])
		);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				// saveObject() is typed `: ObjectEntity` in production and can
				// never return the payload array it was handed (#399).
				function (array $object) use (&$saved): ObjectEntity {
					$saved = $object;
					return $this->makeEntity($object);
				}
			);

		$this->minutesService->expects($this->once())
			->method('notifyApproversOnSubmit')
			->willReturn(2);

		$result = $this->service->submitForApproval(minutesId: 'minutes-001');

		self::assertSame('review', $saved['lifecycle']);
		self::assertSame('review', $result['lifecycle']);
		self::assertSame(2, $result['notified']);

	}//end testSubmitForApprovalPersistsReviewAndNotifies()
}//end class
