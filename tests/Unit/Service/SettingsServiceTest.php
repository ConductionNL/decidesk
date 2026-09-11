<?php

/**
 * Unit tests for SettingsService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsService.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
 */
class SettingsServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock IAppManager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appManager = $this->createMock(originalClassName: IAppManager::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new SettingsService(
			appConfig: $this->appConfig,
			appManager: $this->appManager,
			container: $this->container,
			groupManager: $this->groupManager,
			userSession: $this->userSession,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Test that isOpenRegisterAvailable returns true when OpenRegister is installed.
	 *
	 * @return void
	 */
	public function testIsOpenRegisterAvailableReturnsTrue(): void {
		$this->appManager->expects($this->once())
			->method('isEnabledForAnyone')
			->with('openregister')
			->willReturn(true);

		self::assertTrue(condition: $this->service->isOpenRegisterAvailable());

	}//end testIsOpenRegisterAvailableReturnsTrue()

	/**
	 * Test that isOpenRegisterAvailable returns false when OpenRegister is not installed.
	 *
	 * @return void
	 */
	public function testIsOpenRegisterAvailableReturnsFalse(): void {
		$this->appManager->expects($this->once())
			->method('isEnabledForAnyone')
			->with('openregister')
			->willReturn(false);

		self::assertFalse(condition: $this->service->isOpenRegisterAvailable());

	}//end testIsOpenRegisterAvailableReturnsFalse()

	/**
	 * Test that getSettings returns settings with admin flag for admin user.
	 *
	 * @return void
	 */
	public function testGetSettingsReturnsAdminTrue(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');

		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')
			->with('admin')
			->willReturn(true);

		$this->appConfig->method('getValueString')
			->willReturn('some-register-id');

		$this->appManager->method('isEnabledForAnyone')
			->willReturn(true);

		$settings = $this->service->getSettings();

		self::assertTrue(condition: $settings['isAdmin']);
		self::assertTrue(condition: $settings['openregisters']);
		self::assertSame(expected: 'some-register-id', actual: $settings['register']);

	}//end testGetSettingsReturnsAdminTrue()

	/**
	 * Test that getSettings returns isAdmin false for non-admin user.
	 *
	 * @return void
	 */
	public function testGetSettingsReturnsAdminFalseForNonAdmin(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('regularuser');

		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')
			->with('regularuser')
			->willReturn(false);

		$this->appConfig->method('getValueString')
			->willReturn('');

		$this->appManager->method('isEnabledForAnyone')
			->willReturn(false);

		$settings = $this->service->getSettings();

		self::assertFalse(condition: $settings['isAdmin']);
		self::assertFalse(condition: $settings['openregisters']);

	}//end testGetSettingsReturnsAdminFalseForNonAdmin()

	/**
	 * Test that updateSettings writes to IAppConfig.
	 *
	 * @return void
	 */
	public function testUpdateSettingsWritesToConfig(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('decidiq', 'register', 'new-register-id');

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->appConfig->method('getValueString')
			->willReturn('new-register-id');

		$this->appManager->method('isEnabledForAnyone')
			->willReturn(true);

		$result = $this->service->updateSettings(['register' => 'new-register-id']);

		self::assertSame(expected: 'new-register-id', actual: $result['register']);

	}//end testUpdateSettingsWritesToConfig()

	/**
	 * Test that loadConfiguration returns failure when OpenRegister is not available.
	 *
	 * @return void
	 */
	public function testLoadConfigurationFailsWithoutOpenRegister(): void {
		$this->appManager->method('isEnabledForAnyone')
			->with('openregister')
			->willReturn(false);

		$result = $this->service->loadConfiguration();

		self::assertFalse(condition: $result['success']);
		self::assertStringContainsString(
			needle: 'not installed',
			haystack: $result['message']
		);

	}//end testLoadConfigurationFailsWithoutOpenRegister()

	/**
	 * Test that loadConfiguration returns failure when import throws exception.
	 *
	 * @return void
	 */
	public function testLoadConfigurationHandlesException(): void {
		$this->appManager->method('isEnabledForAnyone')
			->with('openregister')
			->willReturn(true);

		$this->container->method('get')
			->willThrowException(new \RuntimeException('Service not found'));

		$result = $this->service->loadConfiguration();

		self::assertFalse(condition: $result['success']);
		self::assertStringContainsString(
			needle: 'failed',
			haystack: $result['message']
		);

	}//end testLoadConfigurationHandlesException()

}//end class
