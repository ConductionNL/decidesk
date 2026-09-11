<?php

/**
 * Unit tests for PreferencesController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\PreferencesController;
use OCP\AppFramework\Http;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Per-user preferences are read and written through IUserConfig, under the
 * app's own id and a `pref_` prefix.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class PreferencesControllerTest extends TestCase {

	/**
	 * Mock per-user config store.
	 *
	 * @var IUserConfig&MockObject
	 */
	private IUserConfig&MockObject $userConfig;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var PreferencesController
	 */
	private PreferencesController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userConfig  = $this->createMock(IUserConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new PreferencesController(
			$this->createMock(IRequest::class),
			$this->userConfig,
			$this->userSession,
		);

	}//end setUp()

	/**
	 * Sign a user in on the mocked session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end signIn()

	/**
	 * A stored preference is read from the app's namespace with the prefix.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function testGetReadsThePrefixedKey(): void {
		$this->signIn();
		$this->userConfig->expects(self::once())
			->method('getValueString')
			->with('alice', 'decidiq', 'pref_support-seen', '')
			->willReturn('1');

		$response = $this->controller->getPreference('Support-Seen');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['value' => '1'], $response->getData());

	}//end testGetReadsThePrefixedKey()

	/**
	 * An unset preference answers null, not an empty string.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function testGetAnswersNullWhenUnset(): void {
		$this->signIn();
		$this->userConfig->method('getValueString')->willReturn('');

		self::assertSame(['value' => null], $this->controller->getPreference('support-seen')->getData());

	}//end testGetAnswersNullWhenUnset()

	/**
	 * A non-empty value is stored as a string under the prefixed key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function testSetStoresTheValue(): void {
		$this->signIn();
		$this->userConfig->expects(self::once())
			->method('setValueString')
			->with('alice', 'decidiq', 'pref_support-seen', '1');
		$this->userConfig->expects(self::never())->method('deleteUserConfig');

		$response = $this->controller->setPreference('support-seen', '1');

		self::assertSame(['value' => '1'], $response->getData());

	}//end testSetStoresTheValue()

	/**
	 * An empty value deletes the preference instead of storing a blank.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function testSetWithAnEmptyValueDeletes(): void {
		$this->signIn();
		$this->userConfig->expects(self::once())
			->method('deleteUserConfig')
			->with('alice', 'decidiq', 'pref_support-seen');
		$this->userConfig->expects(self::never())->method('setValueString');

		$response = $this->controller->setPreference('support-seen', '');

		self::assertSame(['value' => null], $response->getData());

	}//end testSetWithAnEmptyValueDeletes()

	/**
	 * Without a session nothing is read and the answer is 401.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function testAnonymousCallerIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->userConfig->expects(self::never())->method('getValueString');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->getPreference('support-seen')->getStatus());

	}//end testAnonymousCallerIsRefused()

	/**
	 * A key with nothing safe left is refused before the store is touched.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function testAKeyWithNoSafeCharactersIsRefused(): void {
		$this->signIn();
		$this->userConfig->expects(self::never())->method('setValueString');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->setPreference('../../', 'x')->getStatus());

	}//end testAKeyWithNoSafeCharactersIsRefused()

}//end class
