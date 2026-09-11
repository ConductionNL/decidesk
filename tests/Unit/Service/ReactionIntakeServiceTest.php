<?php

/**
 * Unit tests for ReactionIntakeService.
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
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\Decidiq\Service\ReactionIntakeService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the intake matrix (auth/anon x policy), payload cap, anon gate,
 * pseudonymous submitterId (no PII), and moderation approve/reject counting.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
class ReactionIntakeServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ReactionIntakeService
	 */
	private ReactionIntakeService $service;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('test-secret');

		$this->service = new ReactionIntakeService(
			logger: $this->createMock(LoggerInterface::class),
			appConfig: $appConfig,
			lifecycleService: new ParticipationLifecycleService(
				objectService: $this->objectService,
			),
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock serialising to the given array.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Build an open consultation payload with a future deadline.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function openConsultation(array $overrides = []): array {
		$future = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);
		return array_merge(
			[
				'id' => 'c1',
				'status' => 'open',
				'submissionDeadline' => $future,
				'anonymousReactionsAllowed' => false,
				'moderationPolicy' => 'pre-moderation',
			],
			$overrides
		);

	}//end openConsultation()

	/**
	 * Authenticated + pre-moderation -> pending, NC UID as submitterId.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testAuthenticatedPreModerationPending(): void {
		$this->objectService->method('find')->willReturn($this->entity($this->openConsultation()));
		$captured = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$captured) {
				$captured = $args[0] ?? ($args['object'] ?? null);
				return $this->entity($captured);
			}
		);

		$result = $this->service->submitReaction(consultationId: 'c1', body: 'My idea', ncUid: 'alice');
		self::assertSame('pending', $result['moderationStatus']);
		self::assertSame('alice', $result['submitterId']);

	}//end testAuthenticatedPreModerationPending()

	/**
	 * Authenticated + post-moderation -> auto-approved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testAuthenticatedPostModerationAutoApproved(): void {
		// find() is called for the consultation (intake) then again for the count increment.
		$this->objectService->method('find')->willReturn($this->entity($this->openConsultation(['moderationPolicy' => 'post-moderation'])));
		$this->objectService->method('saveObject')->willReturnCallback(fn (...$a) => $this->entity($a[0] ?? []));

		$result = $this->service->submitReaction(consultationId: 'c1', body: 'My idea', ncUid: 'bob');
		self::assertSame('approved', $result['moderationStatus']);

	}//end testAuthenticatedPostModerationAutoApproved()

	/**
	 * Anonymous rejected when the consultation has not opted in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testAnonymousRejectedWhenNotEnabled(): void {
		$this->objectService->method('find')->willReturn($this->entity($this->openConsultation(['anonymousReactionsAllowed' => false])));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->submitReaction(consultationId: 'c1', body: 'Anon idea', ncUid: null, clientSeed: '1.2.3.4');

	}//end testAnonymousRejectedWhenNotEnabled()

	/**
	 * Anonymous accepted (enabled) is ALWAYS pending and uses a pseudonymous,
	 * PII-free submitterId — even under post-moderation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testAnonymousAlwaysPendingPseudonymous(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity($this->openConsultation(['anonymousReactionsAllowed' => true, 'moderationPolicy' => 'post-moderation']))
		);
		$this->objectService->method('saveObject')->willReturnCallback(fn (...$a) => $this->entity($a[0] ?? []));

		$result = $this->service->submitReaction(consultationId: 'c1', body: 'Anon idea', ncUid: null, clientSeed: '1.2.3.4');
		self::assertSame('pending', $result['moderationStatus']);
		self::assertStringStartsWith('anon-', (string)$result['submitterId']);
		self::assertStringNotContainsString('1.2.3.4', (string)$result['submitterId']);

	}//end testAnonymousAlwaysPendingPseudonymous()

	/**
	 * Oversized payload is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testOversizedPayloadRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->submitReaction(
			consultationId: 'c1',
			body: str_repeat('x', (ReactionIntakeService::MAX_BODY_BYTES + 1)),
			ncUid: 'alice'
		);

	}//end testOversizedPayloadRejected()

	/**
	 * Submission after the deadline is rejected (window guard).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testSubmissionAfterDeadlineRejected(): void {
		$past = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);
		$this->objectService->method('find')->willReturn($this->entity($this->openConsultation(['submissionDeadline' => $past])));
		$this->expectException(ParticipationWindowClosedException::class);
		$this->service->submitReaction(consultationId: 'c1', body: 'Late', ncUid: 'alice');

	}//end testSubmissionAfterDeadlineRejected()

	/**
	 * Approving a pending reaction increments submissionCount once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testApproveIncrementsSubmissionCount(): void {
		$reaction = [
			'id' => 'r1',
			'moderationStatus' => 'pending',
			'relations' => [['schema' => 'public-consultation', 'id' => 'c1']],
		];
		$consultation = ['id' => 'c1', 'submissionCount' => 2];

		// find() is called by id: 'r1' (reaction) then 'c1' (consultation).
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($reaction, $consultation) {
				if ((string)$id === 'r1') {
					return $this->entity($reaction);
				}

				return $this->entity($consultation);
			}
		);

		$savedCounts = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$savedCounts) {
				$obj = $args[0] ?? [];
				if (isset($obj['submissionCount']) === true) {
					$savedCounts[] = $obj['submissionCount'];
				}

				return $this->entity($obj);
			}
		);

		$result = $this->service->approveReaction(reactionId: 'r1', reason: 'Relevant');
		self::assertSame('approved', $result['moderationStatus']);
		self::assertContains(3, $savedCounts);

	}//end testApproveIncrementsSubmissionCount()

	/**
	 * Rejecting requires a reason and retains the object as 'rejected'.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testRejectRequiresReasonAndRetains(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'r1', 'moderationStatus' => 'pending']));
		$this->objectService->method('saveObject')->willReturnCallback(fn (...$a) => $this->entity($a[0] ?? []));

		$result = $this->service->rejectReaction(reactionId: 'r1', reason: 'Off-topic');
		self::assertSame('rejected', $result['moderationStatus']);
		self::assertSame('Off-topic', $result['moderationReason']);

	}//end testRejectRequiresReasonAndRetains()

	/**
	 * Rejecting without a reason is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testRejectWithoutReasonRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->rejectReaction(reactionId: 'r1', reason: '   ');

	}//end testRejectWithoutReasonRefused()

}//end class
