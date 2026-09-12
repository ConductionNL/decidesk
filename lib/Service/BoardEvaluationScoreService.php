<?php

/**
 * Decidiq Board Evaluation Score Service
 *
 * Governance-specific scoring for a board self-evaluation cycle
 * (board-self-evaluation): per-dimension Likert means, an overall
 * board-effectiveness score, and a free-text theme grouping. Kept in-app
 * per REQ-AN-LEAF-002 (generic aggregations move to the Analytics leaf;
 * governance-specific calculations stay in-app). Below
 * `minRespondentThreshold` respondents, only the aggregate overall score is
 * returned — per-dimension and free-text breakdowns are suppressed at
 * computation time so no breakdown ever exists to leak, even internally.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Computes and materialises the `scoreSummary` on a BoardEvaluation.
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
 */
class BoardEvaluationScoreService {

	/**
	 * Default minimum-respondent threshold when a BoardEvaluation does not
	 * declare its own (design.md D2 — leaning 3).
	 */
	private const DEFAULT_MIN_RESPONDENT_THRESHOLD = 3;

	/**
	 * Stopwords excluded from free-text theme extraction (NL + EN, lowercase).
	 *
	 * @var string[]
	 */
	private const THEME_STOPWORDS = [
		'the',
		'and',
		'for',
		'with',
		'that',
		'this',
		'from',
		'have',
		'more',
		'could',
		'should',
		'would',
		'about',
		'into',
		'than',
		'they',
		'them',
		'de',
		'het',
		'een',
		'van',
		'voor',
		'met',
		'dat',
		'meer',
		'zou',
		'over',
		'niet',
		'wel',
		'kan',
		'ook',
		'aan',
		'bij',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy ObjectService lookup)
	 * @param LoggerInterface $logger Diagnostic logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Pure computation: per-dimension Likert means, overall score, free-text
	 * themes, with small-body suppression. Given the answer arrays only, so
	 * it can be exercised in unit tests without OpenRegister.
	 *
	 * Each response is shaped ['answers' => [ ['dimension'=>, 'likertValue'=>?, 'freeText'=>?], ... ]].
	 *
	 * @param array<int, array<string, mixed>> $responses The response payloads
	 * @param int $invitedMemberCount Number of invited members (roster size)
	 * @param int $minRespondents Minimum respondents required to expose breakdowns
	 *
	 * @return array<string, mixed> Keys: overallScore (float|null), respondentCount,
	 *                              invitedMemberCount, minRespondentThreshold (int), thresholdMet,
	 *                              suppressed (bool), dimensionScores (float per dimension, or null),
	 *                              themes (word counts per dimension, or null), computedAt (string).
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 */
	public function computeScoreSummary(
		array $responses,
		int $invitedMemberCount,
		int $minRespondents = self::DEFAULT_MIN_RESPONDENT_THRESHOLD,
	): array {
		$respondentCount = count($responses);
		$threshold = max(1, $minRespondents);
		$thresholdWith = ($respondentCount >= $threshold);

		[$dimensionScores, $overallScore] = $this->computeDimensionScores(responses: $responses);
		$themes = $this->computeThemes(responses: $responses);

		// Below threshold: NO per-dimension or free-text breakdown is ever
		// materialised — not merely hidden from a renderer — so a small
		// board cannot de-anonymise an answer by inference (design D2).
		$exposedScores = null;
		$exposedThemes = null;
		if ($thresholdWith === true) {
			$exposedScores = $dimensionScores;
			$exposedThemes = $themes;
		}

		$summary = [
			'overallScore' => $overallScore,
			'respondentCount' => $respondentCount,
			'invitedMemberCount' => $invitedMemberCount,
			'minRespondentThreshold' => $threshold,
			'thresholdMet' => $thresholdWith,
			'suppressed' => ($thresholdWith === false),
			'dimensionScores' => $exposedScores,
			'themes' => $exposedThemes,
			'computedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		return $summary;
	}//end computeScoreSummary()

	/**
	 * Close an evaluation cycle: fetch its responses, compute the score
	 * summary, and materialise it onto the BoardEvaluation together with the
	 * `closed` lifecycle + `closedAt`.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 *
	 * @return array<string, mixed> Keys: success (bool), and optionally message (string)
	 *                              and evaluation (array).
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 */
	public function closeCycle(string $evaluationId): array {
		if ($evaluationId === '') {
			return ['success' => false, 'message' => 'A BoardEvaluation id is required.'];
		}

		try {
			$objectService = $this->objectService();
			$entity = $objectService->find(id: $evaluationId, register: 'decidiq', schema: 'board-evaluation');
			if ($entity === null) {
				return ['success' => false, 'message' => "BoardEvaluation {$evaluationId} not found."];
			}

			$evaluation = $entity->jsonSerialize();
			if ((string)($evaluation['lifecycle'] ?? '') !== 'open') {
				return ['success' => false, 'message' => 'Only an open evaluation can be closed.'];
			}

			$objectService->setRegister('decidiq');
			$objectService->setSchema('evaluation-response');
			// NOT `_relations.board-evaluation`: responses are written with a
			// structured `relations` array (BoardEvaluationResponseService), which
			// OpenRegister flattens to `_relations` keys of the form
			// `relations.<n>.id` — a slug-keyed filter matched zero rows, so every
			// cycle closed with an empty response set and a vacuous score summary
			// on a healthy 200. See ObjectRelationFilter.
			//
			// The filter pins the related id but not the related SCHEMA, so the
			// set is re-checked below before it is scored.
			// `_rbac: false` because the CALLER was already authorised:
			// BoardEvaluationController::close() runs
			// BoardEvaluationAccessGuard::requireChairOrSecretary() before this
			// service is reached, and this controller is the only caller.
			//
			// It is REQUIRED, not an optimisation. `evaluation-response` now
			// declares an authorization block that omits `read`, closing the raw
			// anonymous answers to everyone but their own author and admins — a
			// chair does not own the other members' responses. Under caller RBAC
			// this query would return an empty set and the cycle would close with
			// a vacuous score summary on a healthy 200, which is EXACTLY the
			// silent failure the relation-filter comment above records having
			// already happened once.
			$relationFilter = $this->container->get(ObjectRelationFilter::class);
			$responseEntities = $relationFilter->matching(
				entities: $objectService->findAll(
					['filters' => $relationFilter->filterFor(targetId: $evaluationId)],
					_rbac: false
				),
				schema: 'board-evaluation',
				targetId: $evaluationId
			);

			$responses = array_map(
				static fn ($e) => $e->jsonSerialize(),
				$responseEntities
			);

			$invitedMemberCount = (int)($evaluation['invitedMemberCount'] ?? count(($evaluation['invitedParticipantIds'] ?? [])));
			$minRespondents = (int)($evaluation['minRespondentThreshold'] ?? self::DEFAULT_MIN_RESPONDENT_THRESHOLD);

			$summary = $this->computeScoreSummary(
				responses: $responses,
				invitedMemberCount: $invitedMemberCount,
				minRespondents: $minRespondents
			);

			$evaluation['scoreSummary'] = json_encode($summary);
			$evaluation['lifecycle'] = 'closed';
			$evaluation['closedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
			$evaluation['respondedCount'] = $summary['respondentCount'];

			$saved = $objectService->saveObject(register: 'decidiq', schema: 'board-evaluation', object: $evaluation);

			return [
				'success' => true,
				'evaluation' => $this->normaliseSaved(saved: $saved, fallback: $evaluation),
			];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: closing board evaluation cycle failed',
				['evaluationId' => $evaluationId, 'error' => $e->getMessage()]
			);
			return ['success' => false, 'message' => 'Closing the evaluation failed: ' . $e->getMessage()];
		}//end try

	}//end closeCycle()

	/**
	 * Per-dimension Likert means + the overall score (equal-weight mean of
	 * the per-dimension means — the "overall weighted score" of REQ-EVAL-004).
	 *
	 * @param array<int, array<string, mixed>> $responses The response payloads
	 *
	 * @return array{0: array<string,float>, 1: float|null} [dimensionScores, overallScore]
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 */
	private function computeDimensionScores(array $responses): array {
		$sums = [];
		$counts = [];

		foreach ($responses as $response) {
			$answers = [];
			if (is_array($response['answers'] ?? null) === true) {
				$answers = $response['answers'];
			}

			foreach ($answers as $answer) {
				if (is_array($answer) === false) {
					continue;
				}

				$likertValue = $answer['likertValue'] ?? null;
				if (is_numeric($likertValue) === false) {
					continue;
				}

				$dimension = (string)($answer['dimension'] ?? '');
				if ($dimension === '') {
					continue;
				}

				$sums[$dimension] = ($sums[$dimension] ?? 0.0) + (float)$likertValue;
				$counts[$dimension] = ($counts[$dimension] ?? 0) + 1;
			}
		}//end foreach

		$dimensionScores = [];
		foreach ($sums as $dimension => $sum) {
			$dimensionScores[$dimension] = round($sum / $counts[$dimension], 2);
		}

		if (empty($dimensionScores) === true) {
			return [$dimensionScores, null];
		}

		$overallScore = round(array_sum($dimensionScores) / count($dimensionScores), 2);

		return [$dimensionScores, $overallScore];
	}//end computeDimensionScores()

	/**
	 * Group free-text answers by dimension and extract a lightweight
	 * keyword-frequency "theme" list per dimension (top 3 recurring
	 * non-stopword tokens of length > 3). This is a simple, honest grouping —
	 * not an NLP/topic-modelling engine — sufficient to surface recurring
	 * concerns without re-implementing analytics infrastructure.
	 *
	 * @param array<int, array<string, mixed>> $responses The response payloads
	 *
	 * @return array<string, array<int, array{word: string, count: int}>>
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 */
	private function computeThemes(array $responses): array {
		$dimensionWords = [];

		foreach ($responses as $response) {
			$this->collectThemeWords(response: $response, dimensionWords: $dimensionWords);
		}

		$themes = [];
		foreach ($dimensionWords as $dimension => $wordCounts) {
			arsort($wordCounts);
			$top = [];
			foreach (array_slice($wordCounts, 0, 3, true) as $word => $count) {
				$top[] = ['word' => $word, 'count' => $count];
			}

			$themes[$dimension] = $top;
		}

		return $themes;
	}//end computeThemes()

	/**
	 * Accumulate the significant free-text words of a single response into the
	 * per-dimension word-frequency tally.
	 *
	 * @param array<string, mixed> $response One response payload
	 * @param array<string, array<string, int>> $dimensionWords Tally accumulated across responses (by reference)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 */
	private function collectThemeWords(array $response, array &$dimensionWords): void {
		$answers = [];
		if (is_array($response['answers'] ?? null) === true) {
			$answers = $response['answers'];
		}

		foreach ($answers as $answer) {
			if (is_array($answer) === false) {
				continue;
			}

			$freeText = (string)($answer['freeText'] ?? '');
			$dimension = (string)($answer['dimension'] ?? '');
			if (trim($freeText) === '' || $dimension === '') {
				continue;
			}

			foreach ($this->significantWords(text: $freeText) as $word) {
				$dimensionWords[$dimension][$word] = ($dimensionWords[$dimension][$word] ?? 0) + 1;
			}
		}//end foreach

	}//end collectThemeWords()

	/**
	 * Split free text into lowercase word tokens, dropping stopwords and
	 * tokens of four characters or fewer.
	 *
	 * @param string $text The free-text answer
	 *
	 * @return array<int, string> The significant tokens, in order of appearance
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 */
	private function significantWords(string $text): array {
		$words = preg_split('/[^\p{L}]+/u', mb_strtolower($text));
		if (is_array($words) === false) {
			return [];
		}

		$significant = array_filter(
			$words,
			static function (string $word): bool {
				return mb_strlen($word) > 3 && in_array($word, self::THEME_STOPWORDS, true) === false;
			}
		);

		return array_values($significant);
	}//end significantWords()

	/**
	 * Normalise a saved ObjectEntity (or array) to an array.
	 *
	 * @param mixed $saved The saveObject() return value
	 * @param array<string, mixed> $fallback The original payload
	 *
	 * @return array<string, mixed>
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
	 * Lazy-load the OpenRegister ObjectService from the container.
	 *
	 * @return object The ObjectService instance
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
