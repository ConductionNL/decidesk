<?php

/**
 * Unit tests for DecisionController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\DecisionController;
use OCA\Decidiq\Service\DecisionLifecycleService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DecisionController.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 */
class DecisionControllerTest extends TestCase {

	/**
	 * The controller under test (authenticated admin user).
	 *
	 * @var DecisionController
	 */
	private DecisionController $controller;

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ContainerInterface (DI container).
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock IUser (authenticated user).
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock DecisionLifecycleService.
	 *
	 * @var DecisionLifecycleService&MockObject
	 */
	private DecisionLifecycleService&MockObject $lifecycleService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->user = $this->createMock(IUser::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->lifecycleService = $this->createMock(DecisionLifecycleService::class);

		$this->user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($this->user);

		$this->controller = new DecisionController(
			request: $this->request,
			container: $this->container,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
			lifecycleService: $this->lifecycleService,
		);

	}//end setUp()

	/**
	 * publish() for an unauthenticated request returns 401.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testPublishUnauthenticatedReturns401(): void {
		$unauthSession = $this->createMock(IUserSession::class);
		$unauthSession->method('getUser')->willReturn(null);

		$unauthController = new DecisionController(
			request: $this->request,
			container: $this->container,
			userSession: $unauthSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
			lifecycleService: $this->lifecycleService,
		);

		// Container must NOT be called for an unauthenticated request.
		$this->container->expects($this->never())->method('get');

		$result = $unauthController->publish('decision-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishUnauthenticatedReturns401()

	/**
	 * publish() by a non-admin returns 403.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testPublishByNonAdminReturns403(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(false);

		// Container must NOT be called — admin check happens before delegation.
		$this->container->expects($this->never())->method('get');

		$result = $this->controller->publish('decision-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishByNonAdminReturns403()

	/**
	 * publish() when OpenRegister is unavailable returns 503.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testPublishWhenOpenRegisterUnavailableReturns503(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willThrowException(new \RuntimeException('OpenRegister is not available.'));

		$result = $this->controller->publish('decision-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishWhenOpenRegisterUnavailableReturns503()

	/**
	 * publish() when the Decision object is not found returns 404.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testPublishDecisionNotFoundReturns404(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		// find() returns null — decision does not exist.
		$this->objectService->method('find')->willReturn(null);

		$result = $this->controller->publish('nonexistent-uuid');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishDecisionNotFoundReturns404()

	/**
	 * publish() for a decision with a non-adopted outcome returns 422.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testPublishRejectedDecisionReturns422(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(
			[
				'id' => 'decision-uuid-002',
				'outcome' => 'rejected',
				'isPublished' => 'internal',
			]
		);

		$this->objectService->method('find')->willReturn($entity);

		$result = $this->controller->publish('decision-uuid-002');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishRejectedDecisionReturns422()

	/**
	 * publish() for an already-published decision returns 422.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testPublishAlreadyPublishedDecisionReturns422(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(
			[
				'id' => 'decision-uuid-003',
				'outcome' => 'adopted',
				'isPublished' => 'public',
			]
		);

		$this->objectService->method('find')->willReturn($entity);

		$result = $this->controller->publish('decision-uuid-003');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishAlreadyPublishedDecisionReturns422()

	/**
	 * publish() happy path returns 200 with the updated Decision.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testPublishSucceedsReturns200(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(
			[
				'id' => 'decision-uuid-004',
				'title' => 'Besluit A',
				'outcome' => 'adopted',
				'isPublished' => 'internal',
			]
		);

		$this->objectService->method('find')->willReturn($entity);

		// OpenRegister's saveObject() returns the stored ENTITY, never the
		// payload array this test used to hand back (which the contract refuses).
		// Capture what is written so the assertions pin the persisted change,
		// not only the response.
		$saves = [];
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (
					array $object,
					?array $extend = [],
					string|int|null $register = null,
					string|int|null $schema = null,
					?string $uuid = null,
				) use (&$saves): ObjectEntity {
					$saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];

					// What OpenRegister STORES is not what it was handed:
					// it stamps its own fields on write. The response must
					// carry the stored object, so the double answers one.
					$stored = ($object + ['version' => '2', 'storedBy' => 'openregister']);

					$saved = $this->createMock(ObjectEntity::class);
					$saved->method('getObject')->willReturn($stored);
					$saved->method('jsonSerialize')->willReturn($stored);
					return $saved;
				}
			);

		$result = $this->controller->publish('decision-uuid-004');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertArrayHasKey('isPublished', $result->getData());
		self::assertSame('public', $result->getData()['isPublished']);
		self::assertArrayHasKey('publishedAt', $result->getData());

		// 🔑 THE RESPONSE IS THE STORED OBJECT. These two fields exist only on
		// what saveObject() answered, so they cannot come from the payload the
		// service assembled locally.
		self::assertSame('2', $result->getData()['version']);
		self::assertSame('openregister', $result->getData()['storedBy']);

		// The write targets the loaded decision by uuid and carries the stamp.
		self::assertCount(1, $saves);
		self::assertSame('decision', $saves[0]['schema']);
		self::assertSame('decision-uuid-004', $saves[0]['uuid']);
		self::assertSame('public', $saves[0]['object']['isPublished']);
		self::assertSame('Besluit A', $saves[0]['object']['title'], 'The full object is saved, not a partial payload');
		self::assertNotEmpty($saves[0]['object']['publishedAt']);

	}//end testPublishSucceedsReturns200()

	/**
	 * publish() when saveObject throws returns 503.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return void
	 */
	public function testPublishWhenSaveFailsReturns503(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(
			[
				'id' => 'decision-uuid-005',
				'outcome' => 'adopted',
				'isPublished' => 'internal',
			]
		);

		$this->objectService->method('find')->willReturn($entity);

		$this->objectService->method('saveObject')
			->willThrowException(new \RuntimeException('Database connection lost.'));

		$result = $this->controller->publish('decision-uuid-005');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishWhenSaveFailsReturns503()

	/**
	 * transition() for an unauthenticated request returns 401.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTransitionUnauthenticatedReturns401(): void {
		$unauthSession = $this->createMock(IUserSession::class);
		$unauthSession->method('getUser')->willReturn(null);

		$unauthController = new DecisionController(
			request: $this->request,
			container: $this->container,
			userSession: $unauthSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
			lifecycleService: $this->lifecycleService,
		);

		$this->lifecycleService->expects($this->never())->method('transition');

		$result = $unauthController->transition('decision-uuid-001');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

	}//end testTransitionUnauthenticatedReturns401()

	/**
	 * transition() without an action parameter returns 422.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTransitionMissingActionReturns422(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['action', '', ''],
				['comment', '', ''],
			]
		);

		$this->lifecycleService->expects($this->never())->method('transition');

		$result = $this->controller->transition('decision-uuid-001');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

	}//end testTransitionMissingActionReturns422()

	/**
	 * transition() happy path returns 200 with the service result.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTransitionHappyReturns200(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['action', '', 'propose'],
				['comment', '', 'ready for review'],
			]
		);

		$this->lifecycleService->expects($this->once())
			->method('transition')
			->with(
				self::equalTo('decision-uuid-001'),
				self::equalTo('propose'),
				self::equalTo('admin'),
				self::equalTo('ready for review')
			)
			->willReturn(
				[
					'success' => true,
					'decision' => ['id' => 'decision-uuid-001', 'lifecycle' => 'proposed'],
					'message' => "Decision transitioned to 'proposed'.",
				]
			);

		$result = $this->controller->transition('decision-uuid-001');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame('proposed', $result->getData()['decision']['lifecycle']);

	}//end testTransitionHappyReturns200()

	/**
	 * transition() surfaces guard rejections as 422.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTransitionRejectedReturns422(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['action', '', 'enact'],
				['comment', '', ''],
			]
		);

		$this->lifecycleService->method('transition')->willReturn(
			[
				'success' => false,
				'decision' => null,
				'message' => "Only decisions with outcome 'adopted' may be enacted.",
			]
		);

		$result = $this->controller->transition('decision-uuid-001');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testTransitionRejectedReturns422()

	/**
	 * transitions() returns the lifecycle envelope for readable decisions.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTransitionsHappyReturns200(): void {
		$this->lifecycleService->method('getAvailableTransitions')->willReturn(
			[
				'success' => true,
				'lifecycle' => 'deliberating',
				'domain' => 'association',
				'actions' => [['action' => 'openVoting', 'to' => 'voting', 'chairOnly' => true]],
				'states' => ['draft', 'proposed', 'deliberating', 'voting', 'decided', 'enacted', 'archived'],
				'message' => 'OK',
			]
		);

		$result = $this->controller->transitions('decision-uuid-001');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame('deliberating', $result->getData()['lifecycle']);

	}//end testTransitionsHappyReturns200()

	/**
	 * transitions() renders unreadable / missing decisions as 404.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTransitionsNotFoundReturns404(): void {
		$this->lifecycleService->method('getAvailableTransitions')->willReturn(
			[
				'success' => false,
				'lifecycle' => null,
				'domain' => null,
				'actions' => [],
				'states' => [],
				'message' => "Decision 'decision-uuid-404' not found.",
			]
		);

		$result = $this->controller->transitions('decision-uuid-404');

		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

	}//end testTransitionsNotFoundReturns404()
}//end class
