<?php

/**
 * Unit tests for DecisionTypesController.
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
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\DecisionTypesController;
use OCA\Decidiq\Service\DecisionTypeRegistry;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary endpoint hands the picker the REGISTRY's answer — the whole
 * point is that an admin-added type reaches the frontend without a release —
 * and refuses anonymous callers.
 *
 * @covers \OCA\Decidiq\Controller\DecisionTypesController
 * @uses \OCA\Decidiq\AppInfo\Application
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
class DecisionTypesControllerTest extends TestCase {

	/**
	 * Build the controller over a stubbed session and registry.
	 *
	 * @param bool $authenticated Whether a user is signed in.
	 * @param array<int, string> $types The registry's vocabulary.
	 *
	 * @return DecisionTypesController The controller.
	 */
	private function controller(bool $authenticated, array $types): DecisionTypesController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		$registry = $this->createMock(DecisionTypeRegistry::class);
		$registry->method('getTypes')->willReturn($types);

		return new DecisionTypesController(
			$this->createMock(IRequest::class),
			$session,
			$registry,
		);
	}

	/**
	 * The endpoint returns the registry's vocabulary, admin-added types included.
	 *
	 * @return void
	 */
	public function testReturnsTheRegistryVocabulary(): void {
		$types = ['motion', 'advice', 'subsidie-besluit'];
		$response = $this->controller(authenticated: true, types: $types)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['types' => $types], $response->getData());
	}

	/**
	 * An anonymous caller is refused.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller(authenticated: false, types: ['motion'])->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}
}
