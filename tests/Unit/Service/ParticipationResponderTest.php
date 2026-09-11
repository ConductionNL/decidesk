<?php

/**
 * Unit tests for how ParticipationResponder maps a refusal to a status.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\Decidiq\Service\ParticipationResponder;
use OCA\Decidiq\Service\ParticipationStaffGuard;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * The p3-citizen-participation scenarios fix two statuses: a value that fails
 * validation (a proposal larger than its round) is 422, and an action outside
 * its window (a deadline passed, a round not voting) is 400. Everything else
 * keeps the mapping it had.
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 */
class ParticipationResponderTest extends TestCase {

	/**
	 * Each refusal and the status it must produce.
	 *
	 * @return array<string, array{0: \Throwable, 1: int}>
	 */
	public static function refusalProvider(): array {
		return [
			'a value failing validation is 422' => [
				new ParticipationValidationException('requestedAmount exceeds the round total amount'),
				Http::STATUS_UNPROCESSABLE_ENTITY,
			],
			'an action outside its window is 400' => [
				new ParticipationWindowClosedException('Voting is closed for this budget round'),
				Http::STATUS_BAD_REQUEST,
			],
			'any other invalid argument stays 400' => [
				new \InvalidArgumentException('Proposal title must not be empty'),
				Http::STATUS_BAD_REQUEST,
			],
			'any other failure stays 409' => [
				new \RuntimeException('Citizen has already voted on this proposal'),
				Http::STATUS_CONFLICT,
			],
		];
	}//end refusalProvider()

	/**
	 * A refusal thrown inside a citizen action becomes the status it names,
	 * with the service's message as the body.
	 *
	 * @dataProvider refusalProvider
	 *
	 * @param \Throwable $refusal The exception the service throws.
	 * @param int $status The status the response must carry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function testCitizenActionMapsTheRefusal(\Throwable $refusal, int $status): void {
		$responder = new ParticipationResponder(staffGuard: $this->createMock(ParticipationStaffGuard::class));

		$response = $responder->citizenAction(
			operation: static function () use ($refusal): array {
				throw $refusal;
			},
			uid: 'alice'
		);

		self::assertSame($status, $response->getStatus());
		self::assertSame(['message' => $refusal->getMessage()], $response->getData());
	}//end testCitizenActionMapsTheRefusal()
}//end class
