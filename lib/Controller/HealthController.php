<?php

/**
 * Decidiq Health Controller
 *
 * AppHost adopter by COMPOSITION, not inheritance: the OpenRegister AppHost
 * observability engine is resolved lazily out of the DI container by FQCN
 * string, and the engine result is reshaped into the published REQ-API-004
 * response body (`{status, baseUrl, version, openregister}`). Health-check
 * execution, the always-200 status-code policy and CORS come from the engine
 * (declared in the `observability` block of `src/manifest.json`); the body
 * shape and the OpenRegister-absent fallback are owned here.
 *
 * ⚠️ This class MUST NOT `extends` — nor name in any resolved position — a
 * class from another app. Nextcloud's router `ReflectionClass()`es every file
 * in `lib/Controller/` while MATCHING a route, so an unresolvable parent makes
 * EVERY route in Decidiq return HTTP 500, not just this one. `extends` is
 * resolved by the autoloader, not the container, so no amount of lazy DI
 * registration can rescue it. See decidiq#377.
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
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.5
 * @spec openspec/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * Public, declarative health endpoint — REQ-API-004 body shape.
 *
 * The bespoke OR DI-container probe is replaced by the engine's `orAvailable`
 * check (manifest `observability.health.checks`); this controller maps the
 * engine result back onto the historical reverse-proxy-verification body.
 * The engine collaborators are pulled from the container by FQCN string at
 * dispatch time, so Decidiq never binds the OpenRegister classes at
 * class-declaration time.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class HealthController extends Controller {

	/**
	 * FQCN of the AppHost observability manifest loader.
	 *
	 * Referenced as a string, never imported: the class only exists when
	 * openregister is installed.
	 *
	 * @var string
	 */
	private const MANIFEST_LOADER = 'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader';

	/**
	 * FQCN of the AppHost declarative health-check executor.
	 *
	 * Referenced as a string, never imported: the class only exists when
	 * openregister is installed.
	 *
	 * @var string
	 */
	private const HEALTH_EXECUTOR = 'OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IConfig $config The Nextcloud config service (baseUrl).
	 * @param ContainerInterface $container DI container — resolves the AppHost engine lazily.
	 * @param IAppConfig $appConfig App config store (installed version on the degraded path).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/health and the legacy /api/v1/health — REQ-API-004 body.
	 *
	 * Runs the engine checks (orAvailable + always-200 policy + CORS, from the
	 * manifest), then reshapes the result into the published body that
	 * reverse-proxy probes verify: the effective base URL, the app version, and
	 * a flattened `openregister: connected|unavailable` status.
	 *
	 * When the AppHost engine cannot be resolved — openregister absent or
	 * disabled — the endpoint still answers (the whole point of a health
	 * probe): `status: degraded`, `openregister: unavailable`, HTTP 200.
	 *
	 * The AnonRateLimit below covers liveness/readiness probes, polled on a
	 * schedule by monitoring. Ceiling only — nothing here takes a credential,
	 * so there is no failure to count.
	 *
	 * @return JSONResponse HTTP 200 with status/baseUrl/version/openregister.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.5
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		$baseUrl = $this->config->getSystemValueString(key: 'overwrite.cli.url', default: '');

		$body = $this->engineBody();
		if ($body === null) {
			$body = [
				'status' => 'degraded',
				'version' => $this->appConfig->getValueString(Application::APP_ID, 'installed_version', ''),
				'openregister' => 'unavailable',
				'httpStatus' => Http::STATUS_OK,
			];
		}

		$httpStatus = (int)$body['httpStatus'];
		unset($body['httpStatus']);

		$response = new JSONResponse(
			[
				'status' => $body['status'],
				'baseUrl' => $baseUrl,
				'version' => $body['version'],
				'openregister' => $body['openregister'],
			],
			$httpStatus
		);

		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end index()

	/**
	 * Run the AppHost observability engine and flatten its result.
	 *
	 * Returns null when the engine is unavailable (openregister absent or
	 * disabled). The description lives here rather than trailing the `@return`
	 * tag because php-cs-fixer's docblock aligner indents a tag description to
	 * clear the longest type on the block — behind this 88-character array
	 * shape that lands at column 95, producing a 162-character line and
	 * tripping phpcs's 150-character budget. The two tools disagree; keeping
	 * the prose out of the tag is what stops them fighting.
	 *
	 * @return array{status: string, version: string, openregister: string, httpStatus: int}|null
	 */
	private function engineBody(): ?array {
		try {
			$manifestLoader = $this->container->get(self::MANIFEST_LOADER);
			$executor = $this->container->get(self::HEALTH_EXECUTOR);

			$appId = $this->appName;
			$manifest = $manifestLoader->load(appId: $appId);
			$result = $executor->execute(manifest: $manifest);

			// Flatten the engine's `checks.openregister` (ok|failed[: ...]) back
			// to the historical `connected|unavailable` value.
			$orCheck = (string)($result->checks['openregister'] ?? 'failed');
			$openregister = 'unavailable';
			if (str_starts_with($orCheck, 'ok') === true) {
				$openregister = 'connected';
			}

			return [
				'status' => (string)$result->status,
				'version' => (string)$manifestLoader->appVersion(appId: $appId),
				'openregister' => $openregister,
				'httpStatus' => (int)$result->httpStatusCode,
			];
		} catch (\Throwable $e) {
			return null;
		}//end try

	}//end engineBody()

	/**
	 * Legacy alias target for `GET /api/v1/health`. Delegates to {@see index()}.
	 *
	 * Kept so existing reverse-proxy probes on the historical URL keep working
	 * during the deprecation window.
	 *
	 * @return JSONResponse HTTP 200 with status/baseUrl/version/openregister.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function status(): JSONResponse {
		return $this->index();
	}//end status()

	/**
	 * CORS preflight for the legacy `OPTIONS /api/v1/health` route.
	 *
	 * @return JSONResponse HTTP 200 with Access-Control-* headers.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function statusOptions(): JSONResponse {
		$response = new JSONResponse([], Http::STATUS_OK);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end statusOptions()

	/**
	 * Apply CORS headers using the configured proxy origin when available
	 * (REQ-API-004 parity with the pre-adoption controller).
	 *
	 * @param JSONResponse $response The response to decorate.
	 *
	 * @return void
	 */
	private function applyCorsHeaders(JSONResponse $response): void {
		$origin = $this->config->getSystemValueString(key: 'overwrite.cli.url', default: '*');

		$allowedOrigin = '*';
		if ($origin !== '') {
			$allowedOrigin = $origin;
		}

		$response->addHeader(name: 'Access-Control-Allow-Origin', value: $allowedOrigin);
		$response->addHeader(name: 'Access-Control-Allow-Methods', value: 'GET, OPTIONS');
		$response->addHeader(name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type, X-Requested-With');

	}//end applyCorsHeaders()
}//end class
