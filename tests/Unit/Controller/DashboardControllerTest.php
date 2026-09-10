<?php

/**
 * Wire-contract tests for the decidiq SPA host routes.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Controller\DashboardController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `GET /` (dashboard#page) and the Vue history-mode
 * catch-all (dashboard#catchAll).
 *
 * These two routes are the only way a browser ever reaches the app, and their
 * contract is narrow but load-bearing: a TemplateResponse for the decidiq app
 * naming the `index` template. Rendering a different template — or the right
 * template under the wrong app id — serves a blank page while every status-only
 * probe still reads 200.
 *
 * The catch-all matters separately from the page route: it is what makes a deep
 * link like `/apps/decidiq/meetings/<uuid>` survive a browser reload, and a
 * catch-all that diverged from `page()` would break exactly the reload path and
 * nothing a click-through test walks.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class DashboardControllerTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var DashboardController
	 */
	private DashboardController $controller;

	/**
	 * Initial-state writer, asserted against for the `isAdmin` key.
	 *
	 * @var IInitialState&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $initialState;

	/**
	 * The session the controller reads the acting user from.
	 *
	 * @var IUserSession&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * Group manager, mocked to answer the admin test either way.
	 *
	 * @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $groupManager;

	/**
	 * Set up the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->initialState = $this->createMock(IInitialState::class);
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new DashboardController(
			$this->createMock(IRequest::class),
			$this->initialState,
			$this->userSession,
			$this->groupManager,
		);

	}//end setUp()

	/**
	 * Point the session at a user with the given uid, or at no user at all.
	 *
	 * @param string|null $uid The uid to sign in as, or null for no session.
	 *
	 * @return void
	 */
	private function signInAs(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signInAs()

	/**
	 * page() renders the decidiq `index` template with HTTP 200.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testPageRendersIndexTemplate(): void {
		$response = $this->controller->page();

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('index', $response->getTemplateName());
		self::assertSame(Application::APP_ID, $response->getApp());

	}//end testPageRendersIndexTemplate()

	/**
	 * catchAll() serves the same template as page() so a reloaded deep link
	 * boots the SPA instead of 404ing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function testCatchAllServesTheSameSpaShell(): void {
		$response = $this->controller->catchAll();

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('index', $response->getTemplateName());
		self::assertSame(Application::APP_ID, $response->getApp());
		self::assertSame($this->controller->page()->getTemplateName(), $response->getTemplateName());

	}//end testCatchAllServesTheSameSpaShell()

	/**
	 * An administrator is published to the SPA as `isAdmin: true`.
	 *
	 * 🔴 THIS IS THE ONLY INPUT TO THE FRONTEND'S PERMISSION LIST.
	 *
	 * `src/utils/permissions.js` turns this boolean into the list read by BOTH
	 * the CnAppNav filter and the router's permission guard. If this key stops
	 * being published, `loadState`'s fallback denies — which is the safe
	 * direction, and is asserted separately below.
	 *
	 * @return void
	 *
	 * @spec exclude Bootstrap wiring for the manifest permission gate; no behavioural spec yet.
	 */
	public function testAdminIsPublishedAsIsAdminTrue(): void {
		$this->signInAs('alice');
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(true);

		$this->initialState->expects(self::once())
			->method('provideInitialState')
			->with('isAdmin', true);

		$this->controller->page();

	}//end testAdminIsPublishedAsIsAdminTrue()

	/**
	 * An ordinary account is published as `isAdmin: false`.
	 *
	 * The assertion that matters is the VALUE, not the call. Publishing the key
	 * with a truthy value for a non-admin is precisely the failure this pair
	 * exists to catch, and it would be invisible from the nav: the entry would
	 * simply be there.
	 *
	 * @return void
	 *
	 * @spec exclude Bootstrap wiring for the manifest permission gate; no behavioural spec yet.
	 */
	public function testOrdinaryAccountIsPublishedAsIsAdminFalse(): void {
		$this->signInAs('bob');
		$this->groupManager->method('isAdmin')->with('bob')->willReturn(false);

		$this->initialState->expects(self::once())
			->method('provideInitialState')
			->with('isAdmin', false);

		$this->controller->page();

	}//end testOrdinaryAccountIsPublishedAsIsAdminFalse()

	/**
	 * With no session the controller denies rather than consulting the group
	 * manager with a null uid.
	 *
	 * @return void
	 *
	 * @spec exclude Bootstrap wiring for the manifest permission gate; no behavioural spec yet.
	 */
	public function testNoSessionDenies(): void {
		$this->signInAs(null);
		$this->groupManager->expects(self::never())->method('isAdmin');

		$this->initialState->expects(self::once())
			->method('provideInitialState')
			->with('isAdmin', false);

		$this->controller->page();

	}//end testNoSessionDenies()

}//end class
