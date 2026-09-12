<?php

/**
 * Test Suite for LiveDecisionService
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\LiveDecisionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LiveDecisionService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
 */
class LiveDecisionServiceTest extends TestCase {
	private ContainerInterface|\PHPUnit\Framework\MockObject\MockObject $container;
	private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * Build the service under test around the given injected ObjectService.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service double
	 *
	 * @return LiveDecisionService
	 */
	private function makeService(ObjectServiceInterface $objectService): LiveDecisionService {
		return new LiveDecisionService(
			container: $this->container,
			logger: $this->logger,
			objectService: $objectService,
		);
	}

	/**
	 * Build a mock entity that returns $data from jsonSerialize().
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return object
	 */
	private function makeEntity(array $data): object {
		$entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}

	/**
	 * Test that recordDecision creates Decision and links to Meeting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
	 */
	public function testRecordDecisionCreatesDecisionAndLinksToMeeting(): void {
		$meetingEntity = $this->makeEntity([
			'id' => 'meeting-1',
			'title' => 'Council Meeting',
			'lifecycle' => 'opened',
		]);

		$savedDecisionEntity = $this->makeEntity([
			'id' => 'decision-1',
			'@self' => ['slug' => 'council-decision-1'],
		]);

		$mockObjectService = $this->createMock(ObjectServiceInterface::class);

		$mockObjectService->method('setRegister')->willReturnSelf();
		$mockObjectService->method('setSchema')->willReturnSelf();

		$mockObjectService->expects($this->any())
			->method('find')
			->willReturn($meetingEntity);

		$mockObjectService->expects($this->any())
			->method('findAll')
			->willReturn([]);

		$mockObjectService->expects($this->any())
			->method('saveObject')
			->willReturn($savedDecisionEntity);

		$decisionData = [
			'title' => 'Budget Approved',
			'text' => 'The budget was approved unanimously',
			'outcome' => 'adopted',
		];

		$result = $this->makeService($mockObjectService)->recordDecision('meeting-1', $decisionData);

		$this->assertEquals('council-decision-1', $result);
	}

	/**
	 * Test that recordDecision throws 409 when Meeting not opened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.5
	 */
	public function testRecordDecisionThrows409ForNonOpenedMeeting(): void {
		$meetingEntity = $this->makeEntity([
			'id' => 'meeting-1',
			'title' => 'Council Meeting',
			'lifecycle' => 'scheduled',
		]);

		$mockObjectService = $this->createMock(ObjectServiceInterface::class);

		$mockObjectService->method('setRegister')->willReturnSelf();
		$mockObjectService->method('setSchema')->willReturnSelf();

		$mockObjectService->expects($this->once())
			->method('find')
			->willReturn($meetingEntity);

		$decisionData = [
			'title' => 'Budget Approved',
			'text' => 'The budget was approved unanimously',
			'outcome' => 'adopted',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionCode(409);

		$this->makeService($mockObjectService)->recordDecision('meeting-1', $decisionData);
	}

}
