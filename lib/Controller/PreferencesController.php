<?php

/**
 * Decidiq PreferencesController.
 *
 * Generic per-user key/value preferences, backed by Nextcloud's per-user
 * config store (IUserConfig). Used by shared @conduction/nextcloud-vue widgets (e.g.
 * CnSupportDialog's "seen" flag) that need to persist a small per-user
 * UI flag cross-device without a bespoke endpoint per feature.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user preferences controller.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1
 */
class PreferencesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserConfig $userConfig The Nextcloud per-user config store.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserConfig $userConfig,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read a per-user preference value.
	 *
	 * @param string $key The preference key (kebab/alphanumeric).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getPreference(string $key): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->sanitizeKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$value = $this->userConfig->getValueString(
			userId: $user->getUID(),
			app: Application::APP_ID,
			key: 'pref_' . $safeKey,
			default: ''
		);

		$stored = null;
		if ($value !== '') {
			$stored = $value;
		}

		return new JSONResponse(data: ['value' => $stored]);
	}//end getPreference()

	/**
	 * Write a per-user preference value. An empty value clears it.
	 *
	 * @param string $key The preference key (kebab/alphanumeric).
	 * @param string $value The value to store (empty string clears it).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function setPreference(string $key, string $value = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->sanitizeKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// An empty value clears the preference rather than storing a blank one.
		if ($value === '') {
			$this->userConfig->deleteUserConfig(
				userId: $user->getUID(),
				app: Application::APP_ID,
				key: 'pref_' . $safeKey
			);

			return new JSONResponse(data: ['value' => null]);
		}

		$this->userConfig->setValueString(
			userId: $user->getUID(),
			app: Application::APP_ID,
			key: 'pref_' . $safeKey,
			value: $value
		);

		return new JSONResponse(data: ['value' => $value]);
	}//end setPreference()

	/**
	 * Restrict keys to a safe charset so callers cannot reach arbitrary
	 * per-user config values outside the `pref_` namespace.
	 *
	 * @param string $key The raw key.
	 *
	 * @return string The sanitised key, or '' when nothing safe remains.
	 */
	private function sanitizeKey(string $key): string {
		$safe = preg_replace(pattern: '/[^a-z0-9-]/', replacement: '', subject: strtolower($key));
		return substr((string)$safe, offset: 0, length: 64);
	}//end sanitizeKey()
}//end class
