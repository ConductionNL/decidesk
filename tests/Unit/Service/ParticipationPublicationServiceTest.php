<?php

/**
 * Unit tests for ParticipationPublicationService.
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

use OCA\Decidiq\Service\BudgetVotingService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\ParticipationPublicationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the PII-free reaction digest, setting the RBAC published predicate
 * (publicationDate), and the OpenCatalogi-absent graceful degradation.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
class ParticipationPublicationServiceTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		// Since ADR-083 the container resolves ONE collaborator here: decidiq's
		// own ObjectRelationFilter. OpenRegister is injected instead (see
		// makeService()), so a double parked on the container would never be
		// consulted. ObjectRelationFilter is a dependency-free pure filter, so
		// the real one is used: a mock here would assert nothing about the
		// disclosure boundary that scoping enforces.
		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === ObjectRelationFilter::class) {
					return new ObjectRelationFilter();
				}

				throw new \RuntimeException("Unexpected container::get('{$id}')");
			}
		);
		$this->appManager = $this->createMock(IAppManager::class);

	}//end setUp()

	/**
	 * Build the service with the configured OpenCatalogi presence.
	 *
	 * @param bool $openCatalogi Whether OpenCatalogi reports installed.
	 *
	 * @return ParticipationPublicationService
	 */
	private function makeService(bool $openCatalogi): ParticipationPublicationService {
		$this->appManager->method('isEnabledForAnyone')->willReturn($openCatalogi);
		return new ParticipationPublicationService(
			container: $this->container,
			logger: $this->createMock(LoggerInterface::class),
			appManager: $this->appManager,
			appConfig: $this->createMock(IAppConfig::class),
			budgetService: $this->createMock(BudgetVotingService::class),
			objectService: $this->objectService,
		);

	}//end makeService()

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
	 * The reaction digest carries body+timestamp only — no submitterId / PII.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testReactionDigestIsPiiFree(): void {
		// Reactions carry the structured relations array ReactionIntakeService
		// writes; the digest re-checks it, so a fixture without one is not a
		// reaction the service would ever see.
		$reactions = [
			$this->entity(['body' => 'Idea one', 'submittedAt' => '2026-06-15T10:00:00+00:00', 'submitterId' => 'alice', 'moderationStatus' => 'approved', 'relations' => [['register' => 'decidiq', 'schema' => 'public-consultation', 'id' => 'c1']]]),
			$this->entity(['body' => 'Idea two', 'submittedAt' => '2026-06-15T11:00:00+00:00', 'submitterId' => 'anon-deadbeef', 'moderationStatus' => 'approved', 'relations' => [['register' => 'decidiq', 'schema' => 'public-consultation', 'id' => 'c1']]]),
			// Disclosure boundary: the OpenRegister filter pins the related id
			// but not the related SCHEMA, so a row reached via some other
			// relation must not be published under this consultation.
			$this->entity(['body' => 'Other consultation', 'submittedAt' => '2026-06-15T12:00:00+00:00', 'submitterId' => 'bob', 'moderationStatus' => 'approved', 'relations' => [['register' => 'decidiq', 'schema' => 'public-consultation', 'id' => 'c2']]]),
		];
		$this->objectService->method('findAll')->willReturn($reactions);

		$digest = $this->makeService(openCatalogi: false)->buildReactionDigest(consultationId: 'c1');
		self::assertCount(2, $digest);
		self::assertSame(['Idea one', 'Idea two'], array_column($digest, 'body'));
		foreach ($digest as $entry) {
			self::assertArrayHasKey('body', $entry);
			self::assertArrayNotHasKey('submitterId', $entry);
			// No PII anywhere in the serialised entry.
			self::assertStringNotContainsString('alice', json_encode($entry));
			self::assertStringNotContainsString('anon-', json_encode($entry));
		}

	}//end testReactionDigestIsPiiFree()

	/**
	 * Publishing consultation results sets publicationDate (the RBAC published
	 * predicate), reports anonVisibilityVerified=true, and degrades with a
	 * warning when OpenCatalogi is absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testPublishConsultationDegradesWithoutOpenCatalogi(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'c1', 'title' => 'Visie', 'status' => 'closed']));
		$this->objectService->method('findAll')->willReturn([]);
		$captured = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$captured) {
				$captured = $args[0] ?? [];
				return $this->entity($captured);
			}
		);

		$result = $this->makeService(openCatalogi: false)->publishConsultationResults(consultationId: 'c1', staffResponse: 'Thanks');
		self::assertTrue($result['publishedPredicateSet']);
		// RBAC model: publicationDate <= $now makes the object anon-readable.
		self::assertTrue($result['anonVisibilityVerified']);
		self::assertFalse($result['openCatalogiInstalled']);
		self::assertFalse($result['openCatalogiRouted']);
		self::assertNotNull($result['warning']);
		// The RBAC published predicate (publicationDate) was set on the source
		// object as a normal field, in the past so the public-group rule matches.
		self::assertArrayHasKey('publicationDate', $captured);
		self::assertLessThanOrEqual(
			(new \DateTimeImmutable())->getTimestamp(),
			(new \DateTimeImmutable((string)$captured['publicationDate']))->getTimestamp()
		);
		// This used to assert the depublication key was present AND null — i.e. it
		// pinned the call the code happened to make, and stayed green for exactly as
		// long as the defect lived. OpenRegister declares the property
		// `type: "string", format: "date-time"` and NOT nullable, and its validator
		// rejects an explicit null rather than reading it as "absent", so writing the
		// key failed the whole saveObject; the service's `catch (\Throwable)` logged a
		// warning and the endpoint still answered 200 with publishedPredicateSet=false.
		// "Not depublished" is spelled by the ABSENCE of the key.
		//
		// Asserted under BOTH spellings on purpose. The English one is what this
		// branch's code writes; the Dutch one guards the merge itself, since a
		// resolution that kept the rename but lost the unset() would leave
		// `depublicatiedatum` behind and this line is what would catch it.
		self::assertArrayNotHasKey('depublicationDate', $captured);
		self::assertArrayNotHasKey('depublicatiedatum', $captured);
		// No legacy @self.published predicate is written anymore.
		self::assertArrayNotHasKey('@self', $captured);
		// The summary stored on the object is PII-free (no submitter ids).
		self::assertStringNotContainsString('submitterId', (string)($captured['resultsSummary'] ?? ''));

	}//end testPublishConsultationDegradesWithoutOpenCatalogi()

	/**
	 * publishReaction refuses a non-approved reaction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
	 */
	public function testPublishReactionRefusesNonApproved(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'r1', 'moderationStatus' => 'pending']));
		$this->expectException(\RuntimeException::class);
		$this->makeService(openCatalogi: false)->publishReaction(reactionId: 'r1');

	}//end testPublishReactionRefusesNonApproved()

	/**
	 * OpenRegister hands `scoreSummary` back ALREADY PARSED as an array even though
	 * the schema declares it `type: "string"`. The service used to do
	 * `(string) $evaluation['scoreSummary']`, which on an array yields the literal
	 * "Array"; `json_decode` then returned null and EVERY aggregate fell back to
	 * null, so a published board evaluation carried `overallScore: null` while the
	 * stored object held a real score. And the array was written straight back,
	 * failing validation for the whole object.
	 *
	 * The double returns the shape the COLLABORATOR really produces (an array), not
	 * the shape the caller was written to expect (a string) — mirroring the caller's
	 * expectation instead is how this stayed green in the first place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md
	 */
	public function testPublishEvaluationReadsAnAlreadyParsedScoreSummary(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'be1',
					'lifecycle' => 'closed',
					'cycleLabel' => 'E2E-Aggregate',
					// The shape OpenRegister actually returns: an ARRAY.
					'scoreSummary' => [
						'overallScore' => 4,
						'respondentCount' => 3,
						'dimensionScores' => ['chair-effectiveness' => 4],
						'suppressed' => false,
					],
				]
			)
		);
		$this->objectService->method('findAll')->willReturn([]);
		$captured = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$captured) {
				$captured = $args[0] ?? [];
				return $this->entity($captured);
			}
		);

		$result = $this->makeService(openCatalogi: false)->publishEvaluationResults(evaluationId: 'be1');

		// The aggregate is READ, not lost to a cast.
		self::assertSame(4, $result['summary']['overallScore']);
		self::assertSame(3, $result['summary']['respondentCount']);
		self::assertFalse($result['summary']['suppressed']);

		// The predicate write happened, so the object really is published.
		self::assertTrue($result['publishedPredicateSet']);
		self::assertArrayHasKey('publicationDate', $captured);
		self::assertSame('published', $captured['lifecycle']);

		// ...and it went back in the DECLARED shape: a JSON string, not an array.
		self::assertIsString($captured['scoreSummary']);
		self::assertSame(4, json_decode((string)$captured['scoreSummary'], true)['overallScore']);

	}//end testPublishEvaluationReadsAnAlreadyParsedScoreSummary()

	/**
	 * A `scoreSummary` that really is a JSON string still decodes — the fix accepts
	 * both shapes rather than swapping one bet for the other.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md
	 */
	public function testPublishEvaluationStillReadsAJsonStringScoreSummary(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'be2',
					'lifecycle' => 'closed',
					'scoreSummary' => json_encode(['overallScore' => 2.5, 'respondentCount' => 2, 'suppressed' => true]),
				]
			)
		);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturnCallback(fn (...$args) => $this->entity($args[0] ?? []));

		$result = $this->makeService(openCatalogi: false)->publishEvaluationResults(evaluationId: 'be2');
		self::assertSame(2.5, $result['summary']['overallScore']);
		self::assertTrue($result['summary']['suppressed']);

	}//end testPublishEvaluationStillReadsAJsonStringScoreSummary()

	/**
	 * A failed predicate write must be VISIBLE. It is caught on purpose — a catalog
	 * flow should not 500 because the save failed — but as a bare warning with no
	 * reader it made a total failure indistinguishable from success at HTTP 200.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function testAFailedPredicateWriteIsReportedInTheWarning(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'c9', 'title' => 'Visie', 'status' => 'closed']));
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willThrowException(
			new \RuntimeException("Property 'depublicationDate' should be type 'string' but is 'null'.")
		);

		$result = $this->makeService(openCatalogi: false)->publishConsultationResults(consultationId: 'c9', staffResponse: 'Thanks');

		self::assertFalse($result['publishedPredicateSet']);
		self::assertFalse($result['anonVisibilityVerified']);
		self::assertStringContainsString('NOT publicly readable', (string)$result['warning']);
		self::assertStringContainsString("should be type 'string'", (string)$result['warning']);

	}//end testAFailedPredicateWriteIsReportedInTheWarning()

}//end class
