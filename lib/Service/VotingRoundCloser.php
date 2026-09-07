<?php

/**
 * Decidiq Voting Round Closer
 *
 * Implements the close-a-round path: optional chair casting vote, the tally,
 * stamping closedAt, the subject lifecycle transition, ORI publication and the
 * optional GDPR anonymisation of individual ballots.
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
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\FileService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The close-a-round path, extracted from VotingService.
 *
 * VotingService::closeVotingRound() was a 141-line method with a cyclomatic
 * complexity of 24 and an NPath complexity of 9936. The phases it ran through
 * are now named methods here.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundCloser {
	/**
	 * Constructor for the VotingRoundCloser.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param OriPublicationService $oriService The ORI publication service
	 * @param MotionService $motionService Drives the subject lifecycle transition
	 * @param AmendmentOrderService $amendmentOrder Resolves an amendment's parent motion
	 * @param ObjectRelationFilter $relationFilter Exact-id scoping for relation-filtered result sets
	 * @param FileService $fileService The OpenRegister file service
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly OriPublicationService $oriService,
		private readonly MotionService $motionService,
		private readonly AmendmentOrderService $amendmentOrder,
		private readonly ObjectRelationFilter $relationFilter,
		private readonly FileService $fileService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Close a VotingRound, optionally anonymising vote values.
	 *
	 * A chair casting vote must already have been persisted (see
	 * applyChairCastingVote()) and the tally must already have been computed
	 * from it — both happen in VotingService::closeVotingRound(), because that
	 * ordering is what lets the tally resolve the tie.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param bool $anonymise Whether to nullify individual vote values (GDPR)
	 * @param array<string,mixed> $tally The tally already computed for this round
	 *
	 * @return array<string,mixed> The closed voting round object
	 *
	 * @throws RuntimeException When the subject lifecycle transition is refused
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function close(string $votingRoundId, bool $anonymise, array $tally): array {
		$round = $this->markClosed(votingRoundId: $votingRoundId);

		if ($round !== null) {
			$this->transitionSubject(
				round: $round,
				result: (string)($tally['result'] ?? 'invalid'),
				votingRoundId: $votingRoundId
			);
		}

		if ($round !== null) {
			$this->resolveDecisionStage(
				round: $round,
				result: (string)($tally['result'] ?? 'invalid'),
				votingRoundId: $votingRoundId
			);
		}

		$round = $this->publishOri(votingRoundId: $votingRoundId, round: $round);

		// Anonymise vote values if requested (sequence: tally -> publish -> anonymise).
		if ($anonymise === true) {
			$this->anonymiseVotes(votingRoundId: $votingRoundId);
		}

		return ($round ?? []);
	}//end close()

	/**
	 * Validate and persist the chair's casting vote on a tied round (fail closed).
	 *
	 * Only permitted when the round exists, its tieBreakRule is 'chair-decides',
	 * and the value is 'for' or 'against'. Persisted as chairCastingVote so the
	 * subsequent tally resolves the tie and the audit trail shows the resolution.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $chairCasting The casting vote value ('for'|'against')
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the casting vote is not permitted
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function applyChairCastingVote(string $votingRoundId, string $chairCasting): void {
		if (in_array($chairCasting, ['for', 'against'], true) === false) {
			throw new RuntimeException("Casting vote refused: value must be 'for' or 'against'");
		}

		$objectService = $this->objectService();
		$round = $this->findRound(votingRoundId: $votingRoundId);
		if ($round === null) {
			throw new RuntimeException("VotingRound {$votingRoundId} not found");
		}

		if (($round['tieBreakRule'] ?? 'rejected') !== 'chair-decides') {
			throw new RuntimeException("Casting vote refused: this round's tie-break rule is not 'chair-decides'");
		}

		$round['chairCastingVote'] = $chairCasting;
		$objectService->saveObject(register: 'decidiq', schema: 'voting-round', object: $round);

	}//end applyChairCastingVote()

	/**
	 * Stamp closedAt on the round when it is not already closed.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<string,mixed>|null The round, or null when it does not exist.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function markClosed(string $votingRoundId): ?array {
		$round = $this->findRound(votingRoundId: $votingRoundId);
		if ($round === null) {
			return null;
		}

		if (($round['closedAt'] ?? null) === null) {
			$round['closedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
			$this->objectService()->saveObject(register: 'decidiq', schema: 'voting-round', object: $round);
		}

		return $round;
	}//end markClosed()

	/**
	 * Transition the round's subject (motion or amendment) to its terminal state.
	 *
	 * #318: an InvalidArgumentException (a bad state-machine transition) is
	 * re-thrown so the caller learns the round was closed but the subject
	 * lifecycle could not be updated. Transient/infrastructure errors are
	 * logged at ERROR level and swallowed so a network hiccup does not leave
	 * the round un-closed.
	 *
	 * @param array<string,mixed> $round The closed voting round
	 * @param string $result The computed result
	 * @param string $votingRoundId The voting round UUID (for logging)
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the state machine refuses the transition.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function transitionSubject(array $round, string $result, string $votingRoundId): void {
		$subject = $this->subjectOf(round: $round);
		if ($subject === null) {
			return;
		}

		// ADR-005: a closed round produces an OUTCOME, not a lifecycle state.
		// `adopted`/`rejected` are values of `Decision.outcome`; the state the
		// decision enters is `decided`, and it enters it in either case — a
		// rejected motion has been decided just as surely as an adopted one.
		// Writing `adopted` into `lifecycle` (which is what this did) is a
		// value outside the enum, so the save was refused and every closed
		// round left its motion stale.
		$subjectOutcome = match ($result) {
			'adopted' => 'adopted',
			'rejected' => 'rejected',
			default => null,
		};

		if ($subjectOutcome === null) {
			return;
		}

		try {
			$this->motionService->transitionLifecycle(
				objectId: $subject['id'],
				objectType: $subject['type'],
				newState: 'decided',
				actorId: 'system',
				outcome: $subjectOutcome,
			);

			$this->afterAdoption(subject: $subject, subjectOutcome: $subjectOutcome);
		} catch (InvalidArgumentException $e) {
			// State-machine violation: re-throw so the caller can surface it.
			// #318: Previously swallowed silently.
			throw new RuntimeException(
				'Stemronde gesloten maar motie kon niet worden bijgewerkt: ' . $e->getMessage(),
				0,
				$e
			);
		} catch (Throwable $e) {
			// Transient infrastructure failure: log at ERROR level and continue.
			// #318: Previously logged at WARNING and lost in monitoring noise.
			$this->logger->error(
				'Decidiq: lifecycle transition after close failed — round is closed but motion state may be stale',
				['votingRoundId' => $votingRoundId, 'motionId' => $subject['id'], 'error' => $e->getMessage()]
			);
		}//end try

	}//end transitionSubject()

	/**
	 * Side effects that only apply to an adopted subject.
	 *
	 * @param array{type: string, id: string} $subject The round's subject
	 * @param string $subjectOutcome The recorded vote result ('adopted'|'rejected')
	 *
	 * @return void
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function afterAdoption(array $subject, string $subjectOutcome): void {
		// Keyed on the OUTCOME, not the lifecycle state: both an adopted and a
		// rejected motion end up in `decided`, so the state can no longer tell
		// these apart — only the outcome can. Reading the state here would have
		// created a dossier folder for a rejected motion.
		if ($subjectOutcome !== 'adopted') {
			return;
		}

		// Create dossier folder if an adopted MOTION.
		if ($subject['type'] === 'motion') {
			// ADR-005: the motion is a `decision` discriminated by decisionType.
			$motion = $this->findObject(objectId: $subject['id'], schema: 'decision');
			$motionTitle = (string)($motion['title'] ?? $subject['id']);
			$this->createFileFolder(motionId: $subject['id'], motionTitle: $motionTitle);
		}

		// Adopted AMENDMENT: incorporate it into the parent motion text
		// (motion-amendment spec — "the final motion text MUST incorporate
		// all adopted amendments"). Fail-soft: a text-merge failure must
		// not undo the recorded vote result.
		if ($subject['type'] === 'amendment') {
			$this->incorporateAdoptedAmendment(amendmentId: $subject['id']);
		}

	}//end afterAdoption()

	/**
	 * Record the vote outcome on the DecisionStage this round resolves.
	 *
	 * This is the writer for `DecisionStage.outcome` on a `method=vote` stage.
	 * The schema used to declare it as an x-openregister-calculations entry, and
	 * no configuration of that annotation can work: a virtual calculation named
	 * after a stored property replaces that property on every read and cannot
	 * read it back (the self-reference is a cycle the annotation validator
	 * rejects), a materialised one would clear the outcome every other method
	 * writes directly, and the virtual read path resolves neither `@ref` nor
	 * `@aggregate`, so it cannot see VotingRound.result across the relation at
	 * all. The round is still the single source of truth: this method copies its
	 * result through a fixed map at the moment the round closes and decides
	 * nothing of its own.
	 *
	 * Only an `active` stage is resolved. `pending -> decided` is not in the
	 * stage's declared lifecycle, and a stage the route never activated is not
	 * this round's to conclude. An `invalid` result yields no outcome.
	 *
	 * @param array<string,mixed> $round The closed voting round
	 * @param string $result The computed result
	 * @param string $votingRoundId The voting round UUID (for logging)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decision-methods/spec.md
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function resolveDecisionStage(array $round, string $result, string $votingRoundId): void {
		$outcome = match ($result) {
			'adopted' => 'adopted',
			'rejected', 'tied' => 'rejected',
			default => null,
		};

		$stageId = $this->stageIdOf(round: $round);
		if ($stageId === null || $outcome === null) {
			return;
		}

		try {
			$stage = $this->findObject(objectId: $stageId, schema: 'decision-stage');
			if ($stage === null) {
				return;
			}

			if ((string)($stage['status'] ?? '') !== 'active') {
				$this->logger->info(
					'Decidiq: round closed on a decision stage that is not active, outcome not recorded',
					['votingRoundId' => $votingRoundId, 'stageId' => $stageId, 'status' => ($stage['status'] ?? null)]
				);

				return;
			}

			$stage['status'] = 'decided';
			$stage['outcome'] = $outcome;
			$stage['decidedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
			$this->objectService()->saveObject(register: 'decidiq', schema: 'decision-stage', object: $stage);

			$this->logger->info(
				'Decidiq: decision stage resolved by its voting round',
				['votingRoundId' => $votingRoundId, 'stageId' => $stageId, 'outcome' => $outcome]
			);
		} catch (Throwable $e) {
			// Fail soft, exactly as the subject transition does: the round is
			// closed and its result is recorded, and losing the stage write must
			// not undo that.
			$this->logger->error(
				'Decidiq: round closed but the decision stage outcome could not be recorded',
				['votingRoundId' => $votingRoundId, 'stageId' => $stageId, 'error' => $e->getMessage()]
			);
		}//end try

	}//end resolveDecisionStage()

	/**
	 * Pick the DecisionStage this round resolves, if it has one.
	 *
	 * The link is the `decisionStage` property (a UUID, or the expanded object
	 * when the round was read with the relation extended). A round that resolves
	 * no stage returns null.
	 *
	 * @param array<string,mixed> $round The voting round
	 *
	 * @return string|null The stage UUID, or null when the round resolves none.
	 *
	 * @spec openspec/specs/decision-methods/spec.md
	 */
	private function stageIdOf(array $round): ?string {
		$stage = ($round['decisionStage'] ?? null);
		if (is_array($stage) === true) {
			$stage = ($stage['id'] ?? ($stage['uuid'] ?? null));
		}

		if (is_string($stage) === false || $stage === '') {
			return null;
		}

		return $stage;
	}//end stageIdOf()

	/**
	 * Pick the motion or amendment the round decides on.
	 *
	 * @param array<string,mixed> $round The voting round
	 *
	 * @return array{type: string, id: string}|null The subject, or null when absent.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function subjectOf(array $round): ?array {
		foreach (($round['relations'] ?? []) as $rel) {
			$relSchema = ($rel['schema'] ?? '');
			if ($relSchema !== 'motion' && $relSchema !== 'amendment') {
				continue;
			}

			$subjectId = ($rel['id'] ?? null);
			if ($subjectId === null) {
				return null;
			}

			return [
				'type' => $relSchema,
				'id' => (string)$subjectId,
			];
		}

		return null;
	}//end subjectOf()

	/**
	 * Trigger ORI publication, attaching any failure to the round data.
	 *
	 * #318: infrastructure/network errors are logged at ERROR level (was INFO)
	 * so they surface in monitoring. "Endpoint not configured" is still silent —
	 * publish() returns without doing anything when no endpoint is set.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param array<string,mixed>|null $round The closed round
	 *
	 * @return array<string,mixed>|null The round, possibly carrying oriPublicationError.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function publishOri(string $votingRoundId, ?array $round): ?array {
		try {
			$this->oriService->publish($votingRoundId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: ORI publication failed after round close',
				['votingRoundId' => $votingRoundId, 'error' => $e->getMessage()]
			);

			// Attach ORI error to round data so caller can reflect it in the response.
			if ($round !== null) {
				$round['oriPublicationError'] = $e->getMessage();
			}
		}

		return $round;
	}//end publishOri()

	/**
	 * Nullify every individual vote value in the round (GDPR anonymisation).
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function anonymiseVotes(string $votingRoundId): void {
		try {
			$objectService = $this->objectService();
			$objectService->setRegister('decidiq');
			$objectService->setSchema('vote');
			$voteEntities = $this->relationFilter->matching(
				entities: $objectService->findAll(
					['filters' => $this->relationFilter->filterFor(targetId: $votingRoundId)]
				),
				schema: 'voting-round',
				targetId: $votingRoundId
			);

			foreach ($voteEntities as $voteEntity) {
				$vote = $voteEntity->jsonSerialize();
				$vote['value'] = null;
				$objectService->saveObject(register: 'decidiq', schema: 'vote', object: $vote);
			}

			$this->logger->info('Decidiq: votes anonymised', ['votingRoundId' => $votingRoundId]);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: vote anonymisation failed', ['error' => $e->getMessage()]);
		}//end try

	}//end anonymiseVotes()

	/**
	 * Create a dossier folder for an adopted motion via FileService.
	 *
	 * @param string $motionId The motion UUID
	 * @param string $motionTitle The motion title (used to compose folder path)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function createFileFolder(string $motionId, string $motionTitle): void {
		try {
			$slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $motionTitle) ?? $motionId);
			$folderPath = "motions/{$slug}-{$motionId}";
			$this->fileService->createFolder($folderPath);
			$this->logger->info('Decidiq: dossier folder created', ['path' => $folderPath, 'motionId' => $motionId]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: dossier folder creation failed',
				['motionId' => $motionId, 'error' => $e->getMessage()]
			);
		}

	}//end createDossierFolder()

	/**
	 * Incorporate an adopted amendment into its parent motion text (fail-soft).
	 *
	 * Resolves the amendment's parent motion (flat `amends` property or
	 * structured relation) and delegates to MotionService::applyAmendment(),
	 * which appends the amendment as an annotated section of the motion text.
	 * Failures are logged and never undo the recorded vote result.
	 *
	 * @param string $amendmentId The adopted amendment UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function incorporateAdoptedAmendment(string $amendmentId): void {
		try {
			// ADR-005: the amendment is a `decision` discriminated by decisionType.
			$amendment = $this->findObject(objectId: $amendmentId, schema: 'decision');
			if ($amendment === null
				|| ($amendment['decisionType'] ?? null) !== 'amendment'
			) {
				return;
			}

			$parentMotionId = $this->amendmentOrder->resolveParentMotionId(amendment: $amendment);
			if ($parentMotionId === null) {
				return;
			}

			$this->motionService->applyAmendment(motionId: $parentMotionId, amendmentId: $amendmentId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to incorporate adopted amendment into the parent motion text',
				['amendmentId' => $amendmentId, 'error' => $e->getMessage()]
			);
		}//end try

	}//end incorporateAdoptedAmendment()

	/**
	 * Load a voting round as an array.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<string,mixed>|null The round, or null when absent.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function findRound(string $votingRoundId): ?array {
		return $this->findObject(objectId: $votingRoundId, schema: 'voting-round');
	}//end findRound()

	/**
	 * Load any Decidiq object as an array.
	 *
	 * @param string $objectId The object UUID
	 * @param string $schema The schema slug
	 *
	 * @return array<string,mixed>|null The object, or null when absent.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function findObject(string $objectId, string $schema): ?array {
		$entity = $this->objectService()->find(id: $objectId, register: 'decidiq', schema: $schema);
		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end findObject()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object The OpenRegister ObjectService.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
