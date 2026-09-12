<?php

/**
 * Unit tests for VotingBehaviourService.
 *
 * This service had ZERO test coverage (129 statements, 0 covered) while being
 * the thing that answers "how does this board member actually vote" — a
 * participation statistic that ends up in front of a governance body. These
 * tests pin the traversal it performs and the arithmetic it reports.
 *
 * The traversal is three hops and easy to get subtly wrong:
 *   governance-body -> motion (decisionType discriminator)
 *                   -> voting-round (closed only)
 *                   -> vote (this participant's ballots)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VotingBehaviourService;
use OCA\Decidiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers VotingBehaviourService::getStats().
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */
class VotingBehaviourServiceTest extends TestCase {
	/**
	 * The mocked OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

	/**
	 * Set up the mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// NOTE: setSchema() is deliberately NOT configured here. respondPerSchema()
		// installs the callback that records it, and a second ->method() matcher
		// for the same method would never be reached.
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();

	}//end setUp()

	/**
	 * Build a mock entity whose jsonSerialize() returns $data.
	 *
	 * @param array<string,mixed> $data The serialised object payload.
	 *
	 * @return object
	 */
	private function makeEntity(array $data): object {
		$entity = $this->getMockBuilder(\stdClass::class)
			->addMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end makeEntity()

	/**
	 * Drive findAll() by the schema the service last selected.
	 *
	 * The service calls setSchema() then findAll() for each hop, so keying the
	 * responses on schema is what lets one mock serve the whole traversal.
	 *
	 * @param array<string, array<int, object>> $bySchema Schema slug => entities.
	 *
	 * @return void
	 */
	private function respondPerSchema(array $bySchema): void {
		$schema = '';
		$this->objectService->method('setSchema')->willReturnCallback(
			function (string $s) use (&$schema) {
				$schema = $s;
				return $this->objectService;
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(
			static function () use (&$schema, $bySchema): array {
				return ($bySchema[$schema] ?? []);
			}
		);

	}//end respondPerSchema()

	/**
	 * A governance body with no motions yields a zeroed, well-formed report.
	 *
	 * The shape matters as much as the numbers: callers render every key, and a
	 * missing one is a broken page rather than a zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	public function testEmptyBodyYieldsZeroedStats(): void {
		$this->respondPerSchema([]);
		$service = new VotingBehaviourService($this->objectService, new FakeSlugResolver(['decidiq']));

		$stats = $service->getStats('participant-1', 'body-1');

		$this->assertSame('participant-1', $stats['participantId']);
		$this->assertSame('body-1', $stats['governanceBodyId']);
		$this->assertSame(0, $stats['totalRounds']);
		$this->assertSame(0, $stats['participated']);
		$this->assertSame(0.0, $stats['participationRate']);
		$this->assertSame(0, $stats['votesFor']);
		$this->assertSame(0, $stats['votesAgainst']);
		$this->assertSame(0, $stats['votesAbstain']);
		$this->assertSame(0, $stats['proxiesGiven']);

	}//end testEmptyBodyYieldsZeroedStats()

	/**
	 * An OPEN round is not counted — neither in the denominator nor as
	 * participation.
	 *
	 * This is the assertion that gives the participation rate its meaning: a
	 * member cannot be marked absent from a vote that has not closed yet, so a
	 * round without `closedAt` must not reach the tally at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	public function testOpenRoundsAreExcludedFromTheDenominator(): void {
		$this->respondPerSchema(
			[
				'decision' => [$this->makeEntity(['id' => 'motion-1'])],
				'voting-round' => [
					$this->makeEntity(['id' => 'round-open']),
					$this->makeEntity(['id' => 'round-closed', 'closedAt' => '2026-01-02T00:00:00Z']),
				],
				'vote' => [$this->makeEntity(['value' => 'for'])],
			]
		);
		$service = new VotingBehaviourService($this->objectService, new FakeSlugResolver(['decidiq']));

		$stats = $service->getStats('participant-1', 'body-1');

		// Two rounds exist; only the closed one counts.
		$this->assertSame(1, $stats['totalRounds']);
		$this->assertSame(1, $stats['participated']);
		$this->assertSame(100.0, $stats['participationRate']);

	}//end testOpenRoundsAreExcludedFromTheDenominator()

	/**
	 * Ballot values are tallied per bucket, and a proxy ballot is counted as
	 * one this participant CAST, not one they received.
	 *
	 * The direction is the easy thing to invert, and inverting it would
	 * misreport who delegated to whom.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	public function testVotesAreTalliedPerBucketAndProxyCountsAsGiven(): void {
		$this->respondPerSchema(
			[
				'decision' => [$this->makeEntity(['id' => 'motion-1'])],
				'voting-round' => [
					$this->makeEntity(['id' => 'round-1', 'closedAt' => '2026-01-02T00:00:00Z']),
				],
				'vote' => [
					$this->makeEntity(['value' => 'for']),
					$this->makeEntity(['value' => 'against']),
					$this->makeEntity(['value' => 'abstain']),
					$this->makeEntity(['value' => 'for', 'isProxy' => true]),
					// An unrecognised value must not land in any bucket.
					$this->makeEntity(['value' => 'banana']),
				],
			]
		);
		$service = new VotingBehaviourService($this->objectService, new FakeSlugResolver(['decidiq']));

		$stats = $service->getStats('participant-1', 'body-1');

		$this->assertSame(2, $stats['votesFor']);
		$this->assertSame(1, $stats['votesAgainst']);
		$this->assertSame(1, $stats['votesAbstain']);
		$this->assertSame(1, $stats['proxiesGiven']);
		// One round with ballots => participated once, not once per ballot.
		$this->assertSame(1, $stats['participated']);

	}//end testVotesAreTalliedPerBucketAndProxyCountsAsGiven()

	/**
	 * The participation rate is a percentage of CLOSED rounds, rounded to one
	 * decimal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	public function testParticipationRateIsAPercentageOfClosedRounds(): void {
		$rounds = [];
		foreach (['r1', 'r2', 'r3'] as $id) {
			$rounds[] = $this->makeEntity(['id' => $id, 'closedAt' => '2026-01-02T00:00:00Z']);
		}

		$this->respondPerSchema(
			[
				'decision' => [$this->makeEntity(['id' => 'motion-1'])],
				'voting-round' => $rounds,
				'vote' => [],
			]
		);
		$service = new VotingBehaviourService($this->objectService, new FakeSlugResolver(['decidiq']));

		$stats = $service->getStats('participant-1', 'body-1');

		$this->assertSame(3, $stats['totalRounds']);
		// No ballots found for this participant in any round.
		$this->assertSame(0, $stats['participated']);
		$this->assertSame(0.0, $stats['participationRate']);

	}//end testParticipationRateIsAPercentageOfClosedRounds()

	/**
	 * A motion without a resolvable id is skipped rather than fatal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	public function testMotionWithoutAnIdIsSkipped(): void {
		$this->respondPerSchema(
			[
				'decision' => [$this->makeEntity(['title' => 'no id here'])],
				'voting-round' => [
					$this->makeEntity(['id' => 'round-1', 'closedAt' => '2026-01-02T00:00:00Z']),
				],
				'vote' => [$this->makeEntity(['value' => 'for'])],
			]
		);
		$service = new VotingBehaviourService($this->objectService, new FakeSlugResolver(['decidiq']));

		$stats = $service->getStats('participant-1', 'body-1');

		// The motion never yielded an id, so its rounds were never reached.
		$this->assertSame(0, $stats['totalRounds']);
		$this->assertSame(0, $stats['votesFor']);

	}//end testMotionWithoutAnIdIsSkipped()
}//end class
