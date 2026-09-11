<?php

/**
 * Decidiq Budget Voting Service
 *
 * Participatory-budget proposal submission, staff validation, advisory voting
 * (delegating to the shared tally machinery), and greedy allocation results.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * Stateless service for participatory-budget proposals + advisory voting.
 *
 * Advisory voting NEVER produces a statutory decision outcome; the tally is
 * delegated to AdvisoryVoteService::applyAdvisoryTally() so there is no parallel
 * tally implementation and citizen tallies stay separate from VotingRound
 * tallies.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class BudgetVotingService {
	/**
	 * Constructor for BudgetVotingService.
	 *
	 * @param ParticipationLifecycleService $lifecycleService Status/deadline guards
	 * @param AdvisoryVoteService $advisoryVoteService Advisory citizen-vote tally machinery
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		private readonly ParticipationLifecycleService $lifecycleService,
		private readonly AdvisoryVoteService $advisoryVoteService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object The ObjectService instance.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Normalise a saved ObjectEntity (or array) to an array.
	 *
	 * @param mixed $saved The saveObject() return value.
	 * @param array<string, mixed> $fallback The original payload.
	 *
	 * @return array<string, mixed> The persisted object as an array.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function normaliseSaved(mixed $saved, array $fallback): array {
		if ($saved instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
			return $saved->jsonSerialize();
		}

		if (is_array($saved) === true) {
			return $saved;
		}

		return $fallback;
	}//end normaliseSaved()

	/**
	 * Submit a budget proposal during a round's submission phase.
	 *
	 * Validates server-side that the round accepts proposals (status +
	 * deadline) and that requestedAmount is positive and within totalAmount.
	 *
	 * @param string $budgetId The ParticipatoryBudget round UUID.
	 * @param string $title The proposal title.
	 * @param string $description The proposal description.
	 * @param float $requested The requested amount.
	 * @param string $submitterId The citizen's NC UID.
	 * @param string $category Optional category.
	 *
	 * @return array<string, mixed> The created BudgetProposal object.
	 *
	 * @throws RuntimeException When the round is missing.
	 * @throws ParticipationWindowClosedException When the round is not accepting proposals (HTTP 400).
	 * @throws ParticipationValidationException When the amount is not positive or exceeds the round (HTTP 422).
	 * @throws InvalidArgumentException When the title is empty.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function submitProposal(
		string $budgetId,
		string $title,
		string $description,
		float $requested,
		string $submitterId,
		string $category = '',
	): array {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidArgumentException('Proposal title must not be empty');
		}

		$objectService = $this->objectService();
		$roundEntity = $objectService->find(id: $budgetId, register: 'decidiq', schema: 'participatory-budget');
		if ($roundEntity === null) {
			throw new RuntimeException("ParticipatoryBudget {$budgetId} not found");
		}

		$round = $roundEntity->jsonSerialize();

		if ($this->lifecycleService->budgetAcceptsProposals(round: $round) === false) {
			throw new ParticipationWindowClosedException('This budget round is not open for proposal submission');
		}

		if ($requested <= 0) {
			throw new ParticipationValidationException('requestedAmount must be a positive number');
		}

		$total = (float)($round['totalAmount'] ?? 0);
		if ($total > 0 && $requested > $total) {
			throw new ParticipationValidationException('requestedAmount exceeds the round total amount');
		}

		$proposal = [
			'title' => $title,
			'description' => $description,
			'requestedAmount' => $requested,
			'submitter' => $submitterId,
			'status' => 'submitted',
			'votesFor' => 0,
			'votesAgainst' => 0,
			// The schema's own reference to the round. `relations` below is not a
			// BudgetProposal property: OpenRegister keeps it only until the next
			// save, and validateProposal() saves the proposal again. Without this
			// field castAdvisoryVote() could not find the round after validation
			// and refused every vote as "voting is closed".
			'participatoryBudget' => $budgetId,
			'relations' => [
				['register' => 'decidiq', 'schema' => 'participatory-budget', 'id' => $budgetId],
			],
		];

		if ($category !== '') {
			$proposal['category'] = $category;
		}

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'budget-proposal', object: $proposal);

		return $this->normaliseSaved(saved: $saved, fallback: $proposal);
	}//end submitProposal()

	/**
	 * Staff-validate a submitted proposal (submitted -> validated | rejected).
	 *
	 * Only 'validated' proposals are votable.
	 *
	 * @param string $proposalId The proposal UUID.
	 * @param bool $approve True to validate, false to reject.
	 *
	 * @return array<string, mixed> The updated proposal object.
	 *
	 * @throws RuntimeException When the proposal is not found.
	 * @throws InvalidArgumentException When the proposal is not in 'submitted'.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function validateProposal(string $proposalId, bool $approve): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $proposalId, register: 'decidiq', schema: 'budget-proposal');
		if ($entity === null) {
			throw new RuntimeException("BudgetProposal {$proposalId} not found");
		}

		$proposal = $entity->jsonSerialize();
		if ((string)($proposal['status'] ?? '') !== 'submitted') {
			throw new InvalidArgumentException('Only a submitted proposal can be validated or rejected');
		}

		$proposal['status'] = 'rejected';
		if ($approve === true) {
			$proposal['status'] = 'validated';
		}

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'budget-proposal', object: $proposal);

		return $this->normaliseSaved(saved: $saved, fallback: $proposal);
	}//end validateProposal()

	/**
	 * Cast one advisory vote on a validated proposal during the voting window.
	 *
	 * Enforces the window guard (round in 'voting' + before votingDeadline,
	 * server-side) and that the proposal is 'validated', then delegates the
	 * one-vote integrity + atomic tally to the shared AdvisoryVoteService.
	 *
	 * The window guard fails CLOSED: an unresolvable or missing round is
	 * refused, not waved through.
	 *
	 * @param string $proposalId The BudgetProposal UUID.
	 * @param string $voterId The authenticated citizen NC UID.
	 * @param string $value 'voor' | 'tegen'.
	 *
	 * @return array<string, mixed> ['vote' => ..., 'votesFor' => int, 'votesAgainst' => int].
	 *
	 * @throws RuntimeException When the proposal is missing or not validated.
	 * @throws ParticipationWindowClosedException When the round cannot be established or is not in its voting window (HTTP 400).
	 * @throws InvalidArgumentException When the value is invalid.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed
	 */
	public function castAdvisoryVote(string $proposalId, string $voterId, string $value): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $proposalId, register: 'decidiq', schema: 'budget-proposal');
		if ($entity === null) {
			throw new RuntimeException("BudgetProposal {$proposalId} not found");
		}

		$proposal = $entity->jsonSerialize();
		if ((string)($proposal['status'] ?? '') !== 'validated') {
			throw new RuntimeException('Only validated proposals can be voted on');
		}

		// The voting-window guard FAILS CLOSED. Both of these used to be silent
		// `if (... !== null)` skips, which made the window guard conditional on
		// the very data an attacker would be probing: a proposal whose round
		// could not be resolved — no `relations` entry and no flat
		// `participatoryBudget`, or a round row that has since been deleted —
		// accepted votes forever, long after `votingDeadline`, because the only
		// code that could have said no never ran. "The round could not be
		// established" is not "the round is open".
		$budgetId = $this->resolveBudgetId(proposal: $proposal);
		if ($budgetId === null) {
			throw new ParticipationWindowClosedException('Voting is closed for this budget round');
		}

		$roundEntity = $objectService->find(id: $budgetId, register: 'decidiq', schema: 'participatory-budget');
		if ($roundEntity === null) {
			throw new ParticipationWindowClosedException('Voting is closed for this budget round');
		}

		$round = $roundEntity->jsonSerialize();
		if ($this->lifecycleService->budgetAcceptsVotes(round: $round) === false) {
			throw new ParticipationWindowClosedException('Voting is closed for this budget round');
		}

		return $this->advisoryVoteService->applyAdvisoryTally(proposalId: $proposalId, voterId: $voterId, value: $value);
	}//end castAdvisoryVote()

	/**
	 * Compute the greedy allocation result for a closed budget round.
	 *
	 * Ranks validated proposals by votesFor descending (votesAgainst then
	 * requestedAmount as deterministic tiebreakers) and funds them greedily
	 * while the running total stays within totalAmount. Funded proposals are
	 * marked 'awarded'; the rest remain 'validated'. Advisory only — never
	 * produces a statutory decision.
	 *
	 * @param string $budgetId The ParticipatoryBudget round UUID.
	 *
	 * @return array<string, mixed> Allocation summary with ranked proposals.
	 *
	 * @throws RuntimeException When the round is not found.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function calculateAllocation(string $budgetId): array {
		$objectService = $this->objectService();
		$roundEntity = $objectService->find(id: $budgetId, register: 'decidiq', schema: 'participatory-budget');
		if ($roundEntity === null) {
			throw new RuntimeException("ParticipatoryBudget {$budgetId} not found");
		}

		$round = $roundEntity->jsonSerialize();
		$total = (float)($round['totalAmount'] ?? 0);

		$proposals = $this->fetchValidatedProposals(budgetId: $budgetId);

		usort(
			$proposals,
			static function (array $a, array $b): int {
				$forCmp = ((int)($b['votesFor'] ?? 0) <=> (int)($a['votesFor'] ?? 0));
				if ($forCmp !== 0) {
					return $forCmp;
				}

				$againstCmp = ((int)($a['votesAgainst'] ?? 0) <=> (int)($b['votesAgainst'] ?? 0));
				if ($againstCmp !== 0) {
					return $againstCmp;
				}

				return ((float)($a['requestedAmount'] ?? 0) <=> (float)($b['requestedAmount'] ?? 0));
			}
		);

		$allocated = 0.0;
		$ranked = [];
		foreach ($proposals as $index => $proposal) {
			$requested = (float)($proposal['requestedAmount'] ?? 0);
			$funded = false;
			if ($total <= 0 || ($allocated + $requested) <= $total) {
				$funded = true;
				$allocated += $requested;
			}

			$proposalId = (string)($proposal['id'] ?? $proposal['uuid'] ?? '');

			// Persist the award decision on the proposal.
			if ($funded === true && $proposalId !== '') {
				$proposal['status'] = 'awarded';
				$objectService->saveObject(register: 'decidiq', schema: 'budget-proposal', object: $proposal);
			}

			$ranked[] = [
				'rank' => ($index + 1),
				'proposalId' => $proposalId,
				'title' => (string)($proposal['title'] ?? ''),
				'requestedAmount' => $requested,
				'votesFor' => (int)($proposal['votesFor'] ?? 0),
				'votesAgainst' => (int)($proposal['votesAgainst'] ?? 0),
				'funded' => $funded,
			];
		}//end foreach

		return [
			'budgetId' => $budgetId,
			'totalAmount' => $total,
			'allocatedAmount' => $allocated,
			'proposalCount' => count($ranked),
			'proposals' => $ranked,
		];

	}//end calculateAllocation()

	/**
	 * Fetch all validated/awarded proposals for a round.
	 *
	 * A BudgetProposal names its round through the FLAT `participatoryBudget`
	 * property — that is the field the portal contribution provider writes
	 * (`PortalContributionProvider::citizenActions()`, createBudgetProposal) and
	 * the one `resolveBudgetId()` falls back to. OpenRegister keys the
	 * `_relations` JSONB by that same property name, so `participatoryBudget` is
	 * the filter that matches. `_relations.participatory-budget` — the schema
	 * SLUG, which this call site used to hand-write — matches no key at all and
	 * returned zero rows on a healthy HTTP 200, so calculateAllocation() ranked an
	 * empty list and publishBudgetResults() published `proposals: []` with
	 * `participationCount: 0` for every round. relatesToBudget() below still
	 * re-checks each row, so the structured-relations write shape is not lost.
	 *
	 * @param string $budgetId The round UUID.
	 *
	 * @return array<int, array<string, mixed>> The proposal objects.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function fetchValidatedProposals(string $budgetId): array {
		$objectService = $this->objectService();
		$objectService->setRegister('decidiq');
		$objectService->setSchema('budget-proposal');
		$entities = $objectService->findAll(['filters' => ['participatoryBudget' => $budgetId]]);

		$result = [];
		foreach ($entities as $entity) {
			$proposal = $entity->jsonSerialize();
			if ($this->relatesToBudget(proposal: $proposal, budgetId: $budgetId) === false) {
				continue;
			}

			$status = (string)($proposal['status'] ?? '');
			if (in_array($status, ['validated', 'awarded', 'voting'], true) === true) {
				$result[] = $proposal;
			}
		}

		return $result;
	}//end fetchValidatedProposals()

	/**
	 * Whether a proposal genuinely references the given budget round.
	 *
	 * @param array<string, mixed> $proposal The proposal object.
	 * @param string $budgetId The round UUID.
	 *
	 * @return bool True when the proposal belongs to the round.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function relatesToBudget(array $proposal, string $budgetId): bool {
		return ($this->resolveBudgetId(proposal: $proposal) === $budgetId);
	}//end relatesToBudget()

	/**
	 * Resolve the parent ParticipatoryBudget UUID from a proposal.
	 *
	 * @param array<string, mixed> $proposal The proposal object.
	 *
	 * @return string|null The round UUID, or null when unresolved.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function resolveBudgetId(array $proposal): ?string {
		$related = $this->budgetIdFromRelations(relations: ($proposal['relations'] ?? []));
		if ($related !== null) {
			return $related;
		}

		$flat = ($proposal['participatoryBudget'] ?? null);
		if (is_string($flat) === true && $flat !== '') {
			return $flat;
		}

		if (is_array($flat) === true) {
			$id = ($flat['id'] ?? $flat['uuid'] ?? '');
			if ($id !== '') {
				return (string)$id;
			}
		}

		return null;
	}//end resolveBudgetId()

	/**
	 * Resolve the ParticipatoryBudget UUID from a proposal's relations.
	 *
	 * @param mixed $relations The proposal's relations collection.
	 *
	 * @return string|null The budget UUID, or null when no usable relation exists.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function budgetIdFromRelations(mixed $relations): ?string {
		foreach ($relations as $relation) {
			if (is_array($relation) === false || ($relation['schema'] ?? '') !== 'participatory-budget') {
				continue;
			}

			$id = ($relation['id'] ?? null);
			if ($id !== null && $id !== '') {
				return (string)$id;
			}
		}

		return null;
	}//end budgetIdFromRelations()
}//end class
