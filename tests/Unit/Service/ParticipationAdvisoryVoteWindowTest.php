<?php

/**
 * The advisory voting-window guard fails CLOSED.
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
 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\Decidiq\Service\AdvisoryVoteService;
use OCA\Decidiq\Service\BudgetVotingService;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `BudgetVotingService::castAdvisoryVote()` refuses when the round cannot be
 * established, and still accepts when it is genuinely open.
 *
 * The window check used to live inside `if ($budgetId !== null) { if
 * ($roundEntity !== null) { ... } }`, so the only code that could say "voting is
 * closed" did not run at all for a proposal whose `ParticipatoryBudget` could
 * not be resolved — a missing relation, or a round row deleted after the
 * proposal was made. Votes were accepted indefinitely, past `votingDeadline`,
 * on a healthy 201.
 *
 * Both directions are pinned, because a guard proven only in the refusing
 * direction would be satisfied by a service that refuses everything:
 *   - two fail-closed shapes REJECT and never reach the tally;
 *   - an open round still TALLIES.
 *
 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed
 */
class ParticipationAdvisoryVoteWindowTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock AdvisoryVoteService — the tally must not be reached when refused.
	 *
	 * @var AdvisoryVoteService&MockObject
	 */
	private AdvisoryVoteService&MockObject $advisoryVoteService;

	/**
	 * Service under test.
	 *
	 * @var BudgetVotingService
	 */
	private BudgetVotingService $service;

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
		$this->advisoryVoteService = $this->createMock(AdvisoryVoteService::class);

		$this->service = new BudgetVotingService(
			lifecycleService: new ParticipationLifecycleService(objectService: $this->objectService),
			advisoryVoteService: $this->advisoryVoteService,
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
	 * Route `find()` by schema: proposal row, then round row (or null).
	 *
	 * @param array<string, mixed> $proposal The BudgetProposal payload.
	 * @param array<string, mixed>|null $round The ParticipatoryBudget payload, or null for "no such round".
	 *
	 * @return void
	 */
	private function stubFind(array $proposal, ?array $round): void {
		$this->objectService->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use ($proposal, $round): ?ObjectEntity {
				if ($schema === 'budget-proposal') {
					return $this->entity($proposal);
				}

				if ($schema === 'participatory-budget' && $round !== null) {
					return $this->entity($round);
				}

				return null;
			}
		);

	}//end stubFind()

	/**
	 * REFUSE — the proposal names no round at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed
	 */
	public function testVoteRefusedWhenTheProposalNamesNoRound(): void {
		$this->stubFind(['id' => 'p-1', 'status' => 'validated'], null);
		$this->advisoryVoteService->expects($this->never())->method('applyAdvisoryTally');

		$this->expectException(ParticipationWindowClosedException::class);
		$this->expectExceptionMessage('Voting is closed for this budget round');

		$this->service->castAdvisoryVote(proposalId: 'p-1', voterId: 'alice', value: 'voor');

	}//end testVoteRefusedWhenTheProposalNamesNoRound()

	/**
	 * REFUSE — the named round no longer resolves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed
	 */
	public function testVoteRefusedWhenTheNamedRoundDoesNotResolve(): void {
		$this->stubFind(
			['id' => 'p-1', 'status' => 'validated', 'participatoryBudget' => 'b-gone'],
			null
		);
		$this->advisoryVoteService->expects($this->never())->method('applyAdvisoryTally');

		$this->expectException(ParticipationWindowClosedException::class);
		$this->expectExceptionMessage('Voting is closed for this budget round');

		$this->service->castAdvisoryVote(proposalId: 'p-1', voterId: 'alice', value: 'voor');

	}//end testVoteRefusedWhenTheNamedRoundDoesNotResolve()

	/**
	 * REFUSE — the round resolves but its voting window has closed (the branch
	 * that always worked; kept so a regression cannot trade one refusal for
	 * another).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed
	 */
	public function testVoteRefusedAfterTheVotingDeadline(): void {
		$past = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);
		$this->stubFind(
			['id' => 'p-1', 'status' => 'validated', 'participatoryBudget' => 'b-1'],
			['id' => 'b-1', 'status' => 'voting', 'votingDeadline' => $past]
		);
		$this->advisoryVoteService->expects($this->never())->method('applyAdvisoryTally');

		$this->expectException(ParticipationWindowClosedException::class);
		$this->expectExceptionMessage('Voting is closed for this budget round');

		$this->service->castAdvisoryVote(proposalId: 'p-1', voterId: 'alice', value: 'voor');

	}//end testVoteRefusedAfterTheVotingDeadline()

	/**
	 * ALLOW — an open round still tallies. This is the direction that protects
	 * the feature: fail-closed must not become closed-always.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed
	 */
	public function testVoteAcceptedWhileTheRoundIsOpen(): void {
		$future = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);
		$this->stubFind(
			['id' => 'p-1', 'status' => 'validated', 'participatoryBudget' => 'b-1'],
			['id' => 'b-1', 'status' => 'voting', 'votingDeadline' => $future]
		);

		$this->advisoryVoteService->expects($this->once())
			->method('applyAdvisoryTally')
			->willReturn(['vote' => ['voterId' => 'alice'], 'votesFor' => 1, 'votesAgainst' => 0]);

		$result = $this->service->castAdvisoryVote(proposalId: 'p-1', voterId: 'alice', value: 'voor');

		self::assertSame(1, $result['votesFor']);
		self::assertSame(0, $result['votesAgainst']);

	}//end testVoteAcceptedWhileTheRoundIsOpen()
}//end class
