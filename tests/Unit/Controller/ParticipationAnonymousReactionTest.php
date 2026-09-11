<?php

/**
 * Wire-contract tests for the anonymous citizen-reaction intake endpoint.
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

use OCA\Decidiq\Controller\ParticipationController;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\Decidiq\Service\ParticipationPublicationService;
use OCA\Decidiq\Service\ParticipationResponder;
use OCA\Decidiq\Service\ParticipationStaffGuard;
use OCA\Decidiq\Service\ReactionIntakeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for
 * `POST /api/participation/public/consultations/{consultationId}/reactions`.
 *
 * This is the ONE `#[PublicPage]` write in the app: an unauthenticated citizen
 * posting a reaction to a consultation. Everything about its contract is
 * therefore load-bearing in a way an authenticated endpoint's is not.
 *
 * Three properties are pinned here.
 *
 * 1. The submitter is NEVER taken from the request. The service is called with
 *    `ncUid: null` and a `clientSeed` derived server-side from the remote
 *    address; the pseudonymous token is built from that. A version that read a
 *    submitter from the body would let an anonymous caller attribute a reaction
 *    to a named citizen, and would pass every status assertion.
 *
 * 2. "Anonymous reactions are not enabled for this consultation" answers 401,
 *    not 403 or 422, and is THROTTLED. It is the one rejection that tells an
 *    anonymous prober something about a consultation's configuration, so it has
 *    to be rate-limited like a failed login rather than returned freely.
 *
 * 3. Every other rejection stays coarse: a malformed body is 400, anything else
 *    is 409. No branch leaks whether the consultation exists.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationAnonymousReactionTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ReactionIntakeService.
	 *
	 * @var ReactionIntakeService&MockObject
	 */
	private ReactionIntakeService&MockObject $intakeService;

	/**
	 * The controller under test.
	 *
	 * @var ParticipationController
	 */
	private ParticipationController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * The responder is the REAL ParticipationResponder over a mocked staff
	 * guard: the anonymous route does not go through it, and building it for
	 * real keeps this test from asserting against a stand-in of the class that
	 * decides the app's participation status codes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->intakeService = $this->createMock(ReactionIntakeService::class);
		$this->request->method('getRemoteAddress')->willReturn('198.51.100.7');

		$this->controller = new ParticipationController(
			$this->request,
			$this->createMock(ParticipationLifecycleService::class),
			$this->intakeService,
			$this->createMock(ParticipationPublicationService::class),
			new ParticipationResponder($this->createMock(ParticipationStaffGuard::class)),
		);

	}//end setUp()

	/**
	 * A valid anonymous reaction answers 201 with the stored reaction, and the
	 * service is called with NO user id plus the server-derived client seed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function testAnonymousReactionReturns201AndCarriesNoUserIdentity(): void {
		$this->intakeService->expects($this->once())
			->method('submitReaction')
			->with(
				consultationId: 'consultation-1',
				body: 'Graag meer groen in dit plan.',
				ncUid: null,
				clientSeed: '198.51.100.7'
			)
			->willReturn(['id' => 'reaction-1', 'submitter' => 'pseudonym-4f2a', 'status' => 'pending']);

		$response = $this->controller->submitAnonymousReaction(
			consultationId: 'consultation-1',
			body: 'Graag meer groen in dit plan.'
		);

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('reaction-1', $response->getData()['reaction']['id']);
		self::assertSame('pseudonym-4f2a', $response->getData()['reaction']['submitter']);

	}//end testAnonymousReactionReturns201AndCarriesNoUserIdentity()

	/**
	 * A consultation that has not opted in to anonymous reactions answers 401
	 * and the response is throttled — this rejection is the one an anonymous
	 * prober could otherwise use to enumerate consultation configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function testAnonymousReactionNotEnabledIs401AndThrottled(): void {
		$this->intakeService->method('submitReaction')
			->willThrowException(
				new \InvalidArgumentException('Anonymous reactions are not enabled for this consultation.')
			);

		$response = $this->controller->submitAnonymousReaction(
			consultationId: 'consultation-1',
			body: 'Poging.'
		);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

		// Response::throttle() records the metadata that makes NC's brute-force
		// middleware count this attempt. Asserting only the 401 would pass just
		// as happily with the throttle call deleted.
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'throttleMetadata');
		$property->setAccessible(true);
		self::assertSame(['action' => 'decideskAnonReaction'], $property->getValue($response));

	}//end testAnonymousReactionNotEnabledIs401AndThrottled()

	/**
	 * A malformed body (empty or oversized) is 400, NOT the 401 reserved for
	 * the not-enabled case — a citizen who typed nothing should not be told to
	 * authenticate.
	 *
	 * @param string $serviceMessage The service's rejection message.
	 *
	 * @return void
	 *
	 * @dataProvider malformedBodyMessages
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function testAnonymousReactionMalformedBodyIs400(string $serviceMessage): void {
		$this->intakeService->method('submitReaction')
			->willThrowException(new \InvalidArgumentException($serviceMessage));

		$response = $this->controller->submitAnonymousReaction(consultationId: 'consultation-1', body: '');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAnonymousReactionMalformedBodyIs400()

	/**
	 * Service rejections that describe the payload rather than the feature gate.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformedBodyMessages(): array {
		return [
			'empty body' => ['A reaction body is required.'],
			'oversized body' => ['A reaction body may not exceed 5000 characters.'],
		];

	}//end malformedBodyMessages()

	/**
	 * A consultation not open for reactions (closed, or never published) is 409
	 * — coarse on purpose: it does not distinguish "closed" from "unknown".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function testAnonymousReactionOnUnavailableConsultationIs409(): void {
		$this->intakeService->method('submitReaction')
			->willThrowException(new \RuntimeException('Consultation is not open for reactions.'));

		$response = $this->controller->submitAnonymousReaction(
			consultationId: 'ghost',
			body: 'Reactie.'
		);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testAnonymousReactionOnUnavailableConsultationIs409()

	/**
	 * A closed consultation stays 409 on THIS endpoint even though the
	 * authenticated endpoints now answer it with 400. The closed-window
	 * exception is a \RuntimeException subclass precisely so the anonymous
	 * handler's \Throwable branch keeps it coarse: someone probing without an
	 * account cannot tell a closed consultation from a missing one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function testAnonymousReactionOnClosedConsultationStaysCoarse(): void {
		$this->intakeService->method('submitReaction')
			->willThrowException(new ParticipationWindowClosedException('This consultation is not open for submissions'));

		$response = $this->controller->submitAnonymousReaction(
			consultationId: 'closed',
			body: 'Reactie.'
		);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testAnonymousReactionOnClosedConsultationStaysCoarse()

	/**
	 * The `body` parameter defaults to an empty string, so a request that omits
	 * it entirely reaches the service (which validates) rather than raising an
	 * ArgumentCountError out of the router.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function testAnonymousReactionWithOmittedBodyReachesTheService(): void {
		$this->intakeService->expects($this->once())
			->method('submitReaction')
			->with(consultationId: 'consultation-1', body: '', ncUid: null, clientSeed: '198.51.100.7')
			->willThrowException(new \InvalidArgumentException('A reaction body is required.'));

		$response = $this->controller->submitAnonymousReaction(consultationId: 'consultation-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAnonymousReactionWithOmittedBodyReachesTheService()

}//end class
