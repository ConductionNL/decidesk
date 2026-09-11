<?php

/**
 * Wire-contract test for the legacy health CORS preflight endpoint.
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

use OCA\Decidiq\Controller\HealthController;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Contract test for `OPTIONS /api/v1/health`.
 *
 * The legacy health URL is what reverse proxies and uptime probes already point
 * at, and a browser-based status page reaching it cross-origin issues a
 * preflight first. The endpoint's whole contract is 200 + the CORS header
 * triple; without them the probe never gets to send its GET.
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
 */
class HealthControllerStatusOptionsTest extends TestCase {

	/**
	 * Mock IConfig.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The controller under test.
	 *
	 * @var HealthController
	 */
	private HealthController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->config    = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->controller = new HealthController(
			$this->createMock(IRequest::class),
			$this->config,
			$this->createMock(ContainerInterface::class),
			$this->appConfig,
		);

	}//end setUp()

	/**
	 * Read the headers the controller itself set on a response.
	 *
	 * `Response::getHeaders()` merges in framework headers by asking
	 * `\OC::$server` for the request, and `\OC` does not exist in a standalone
	 * unit run. For a CORS preflight the status is not the contract, so this
	 * reads the private `headers` array that `Response::addHeader()` writes to.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response to inspect.
	 *
	 * @return array<string, string> The controller-set headers.
	 */
	private function controllerHeaders(\OCP\AppFramework\Http\Response $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);

		return (array)$property->getValue($response);
	}//end controllerHeaders()

	/**
	 * The health preflight answers 200 with an empty body and the CORS triple.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
	 */
	public function testStatusOptionsReturnsCorsHeaders(): void {
		$this->config->method('getSystemValueString')->willReturn('https://raad.example');

		$response = $this->controller->statusOptions();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());

		$headers = $this->controllerHeaders($response);
		self::assertSame('https://raad.example', $headers['Access-Control-Allow-Origin']);
		self::assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);
		self::assertStringContainsString('Authorization', $headers['Access-Control-Allow-Headers']);

	}//end testStatusOptionsReturnsCorsHeaders()

	/**
	 * With no configured overwrite URL the origin falls back to the wildcard
	 * rather than an empty header value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
	 */
	public function testStatusOptionsFallsBackToWildcardOrigin(): void {
		$this->config->method('getSystemValueString')->willReturn('');

		$response = $this->controller->statusOptions();

		self::assertSame('*', $this->controllerHeaders($response)['Access-Control-Allow-Origin']);

	}//end testStatusOptionsFallsBackToWildcardOrigin()

	/**
	 * Without the AppHost engine the body is `degraded`, and its version is
	 * the installed version read from the app config store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.5
	 */
	public function testDegradedBodyReportsTheInstalledVersion(): void {
		$this->config->method('getSystemValueString')->willReturn('');
		$this->appConfig->expects(self::once())
			->method('getValueString')
			->with('decidiq', 'installed_version', '')
			->willReturn('1.2.4');

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('degraded', $response->getData()['status']);
		self::assertSame('1.2.4', $response->getData()['version']);
		self::assertSame('unavailable', $response->getData()['openregister']);

	}//end testDegradedBodyReportsTheInstalledVersion()

}//end class
