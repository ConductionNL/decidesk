<?php

/**
 * Decidiq Dashboard Controller
 *
 * SPA host: renders the SPA from `templates/index.php` and serves the Vue
 * history-mode catch-all. Behaviourally identical to the OpenRegister AppHost
 * `GenericDashboardController`, but implemented locally and depending on
 * nothing outside Decidiq and OCP.
 *
 * ⚠️ This class MUST NOT `extends` — nor name in any resolved position — a
 * class from another app. Nextcloud's router `ReflectionClass()`es every file
 * in `lib/Controller/` while MATCHING a route, so an unresolvable parent makes
 * EVERY route in Decidiq return HTTP 500 — including routes with no
 * OpenRegister involvement at all. `extends` is resolved by the AUTOLOADER,
 * not the DI container, so no amount of lazy registration can rescue it, and
 * the 10 lines below are cheaper than a whole-app outage. See decidiq#377.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
 * @spec openspec/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * SPA host for Decidiq.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class DashboardController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest      $request      The request object.
	 * @param IInitialState $initialState Initial-state writer for the SPA bootstrap.
	 * @param IUserSession  $userSession  The current session, for the acting user.
	 * @param IGroupManager $groupManager Group manager, used only for the admin test.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IInitialState $initialState,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Render the main SPA page from `templates/index.php`.
	 *
	 * @return TemplateResponse The rendered Decidiq index template.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(): TemplateResponse {
		return $this->renderIndex();
	}//end page()

	/**
	 * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
	 *
	 * @return TemplateResponse The rendered Decidiq index template.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catchAll(): TemplateResponse {
		return $this->page();
	}//end catchAll()

	/**
	 * Build the `index` TemplateResponse.
	 *
	 * Publishes `isAdmin` into initial state. It is the ONLY input to the
	 * frontend's permission list (`src/utils/permissions.js`), which feeds both
	 * the CnAppNav filter and the router's permission guard. The frontend used
	 * to derive that list from `window.OC.currentUser.permissions`, a property
	 * that does not exist, so the list was always empty and the nav filter
	 * failed open.
	 *
	 * Defaults to `false` when there is no session, so an unauthenticated or
	 * half-booted request denies rather than permits.
	 *
	 * @return TemplateResponse The rendered Decidiq index template.
	 */
	protected function renderIndex(): TemplateResponse {
		$user = $this->userSession->getUser();
		$isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

		$this->initialState->provideInitialState('isAdmin', $isAdmin);

		return new TemplateResponse($this->appName, 'index');
	}//end renderIndex()
}//end class
