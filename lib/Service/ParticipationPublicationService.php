<?php

/**
 * Decidiq Participation Publication Service
 *
 * Builds PII-free result summaries for citizen-participation rounds, attempts
 * to set the OpenRegister published-predicate, and routes to OpenCatalogi when
 * installed (with graceful degradation when it is not).
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Stateless service building + publishing participation result summaries.
 *
 * Anonymous visibility uses the OpenRegister RBAC published-predicate: the
 * published schemas (public-consultation, participatory-budget,
 * consultation-reaction, and the opencatalogi publication) declare an
 * `authorization.read` rule granting the public group read access while
 * `publicationDate <= $now`. "Publish" means setting `publicationDate` (a normal
 * field) on the register-owned object via the ordinary OR object API — these are
 * RBAC-save-path objects, so the historical MagicMapper `published` allowlist
 * limitation never applied. Withdraw sets `depublicationDate`. Catalog routing
 * degrades gracefully when OpenCatalogi is absent (ADR-022 — no app-local public
 * read endpoint).
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationPublicationService {
	/**
	 * This app's id, used below as the `oc_appconfig` namespace.
	 *
	 * Spelled out rather than referencing `Application::APP_ID` on purpose:
	 * importing that class pushes this service one over the PHPMD
	 * CouplingBetweenObjects ceiling of 14. It MUST stay equal to `<id>` in
	 * `appinfo/info.xml` — an appconfig read under the wrong namespace returns
	 * its default rather than failing, so a mismatch here loses the configured
	 * catalog silently.
	 *
	 * @var string
	 */
	private const APP_ID = 'decidiq';

	/**
	 * Constructor for ParticipationPublicationService.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService)
	 * @param LoggerInterface $logger The logger
	 * @param IAppManager $appManager Detects whether OpenCatalogi is installed
	 * @param IAppConfig $appConfig Reads the target catalog config
	 * @param BudgetVotingService $budgetService Allocation result computation
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly BudgetVotingService $budgetService,
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
	 * Build + publish the PII-free summary for a closed consultation.
	 *
	 * Builds a digest of APPROVED reactions (body only — no submitterId, no
	 * pseudonymous token) plus the staff response, sets `publicationDate` (the
	 * RBAC published predicate), and routes to OpenCatalogi when installed.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $staffResponse The staff response text included in the summary.
	 *
	 * @return array<string, mixed> Publication result (see publishSummary()).
	 *
	 * @throws RuntimeException When the consultation is not found.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function publishConsultationResults(string $consultationId, string $staffResponse = ''): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $consultationId, register: 'decidiq', schema: 'public-consultation');
		if ($entity === null) {
			throw new RuntimeException("PublicConsultation {$consultationId} not found");
		}

		$consultation = $entity->jsonSerialize();

		$digest = $this->buildReactionDigest(consultationId: $consultationId);

		$summary = [
			'summaryType' => 'consultation-results',
			'title' => (string)($consultation['title'] ?? 'Consultation results'),
			'description' => (string)($consultation['description'] ?? ''),
			'staffResponse' => $staffResponse,
			'reactionCount' => count($digest),
			'reactions' => $digest,
			'sourceId' => $consultationId,
			'generatedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		return $this->publishSummary(
			summary: $summary,
			sourceSchema: 'public-consultation',
			sourceId: $consultationId,
			governanceBodyId: $this->resolveGovernanceBodyId(object: $consultation)
		);

	}//end publishConsultationResults()

	/**
	 * Build + publish the PII-free summary for a closed budget round.
	 *
	 * Includes the greedy allocation ranking + participation count. Voter
	 * identities never appear (the allocation result carries only aggregate
	 * vote counts, not voter ids).
	 *
	 * @param string $budgetId The ParticipatoryBudget round UUID.
	 *
	 * @return array<string, mixed> Publication result (see publishSummary()).
	 *
	 * @throws RuntimeException When the round is not found.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function publishBudgetResults(string $budgetId): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $budgetId, register: 'decidiq', schema: 'participatory-budget');
		if ($entity === null) {
			throw new RuntimeException("ParticipatoryBudget {$budgetId} not found");
		}

		$round = $entity->jsonSerialize();
		$allocation = $this->budgetService->calculateAllocation(budgetId: $budgetId);
		$participation = 0;
		foreach (($allocation['proposals'] ?? []) as $proposal) {
			$participation += ((int)($proposal['votesFor'] ?? 0) + (int)($proposal['votesAgainst'] ?? 0));
		}

		$summary = [
			'summaryType' => 'budget-results',
			'title' => (string)($round['name'] ?? 'Budget results'),
			'description' => (string)($round['description'] ?? ''),
			'totalAmount' => (float)($round['totalAmount'] ?? 0),
			'allocatedAmount' => (float)($allocation['allocatedAmount'] ?? 0),
			'participationCount' => $participation,
			'proposals' => ($allocation['proposals'] ?? []),
			'sourceId' => $budgetId,
			'generatedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		// Mark the round as having published results.
		$round['resultsPublished'] = true;

		return $this->publishSummary(
			summary: $summary,
			sourceSchema: 'participatory-budget',
			sourceId: $budgetId,
			governanceBodyId: $this->resolveGovernanceBodyId(object: $round),
			sourceObject: $round
		);

	}//end publishBudgetResults()

	/**
	 * Publish (set publicationDate on) a closed BoardEvaluation's aggregate
	 * scoreSummary (board-self-evaluation, REQ-EVAL-005). Reuses this
	 * service's generic publishSummary() — the same mechanism as
	 * publishBudgetResults()/publishConsultationResults() — instead of a new
	 * publication pathway. Raw EvaluationResponse objects are never touched
	 * or exposed by this method; scoreSummary is already threshold-suppressed
	 * by BoardEvaluationScoreService before it ever reaches this call.
	 *
	 * @param string $evaluationId The BoardEvaluation UUID.
	 *
	 * @return array<string, mixed> Publication result (see publishSummary()).
	 *
	 * @throws RuntimeException When the evaluation is not found or not closed.
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function publishEvaluationResults(string $evaluationId): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $evaluationId, register: 'decidiq', schema: 'board-evaluation');
		if ($entity === null) {
			throw new RuntimeException("BoardEvaluation {$evaluationId} not found");
		}

		$evaluation = $entity->jsonSerialize();
		if (in_array((string)($evaluation['lifecycle'] ?? ''), ['closed', 'published'], true) === false) {
			throw new RuntimeException('Only a closed evaluation may be published');
		}

		// Read the materialised scoreSummary WHICHEVER SHAPE IT ARRIVES IN. The
		// schema declares it as a `string` holding JSON and BoardEvaluationScoreService
		// writes one, but OpenRegister hands it back ALREADY PARSED, as an array —
		// measured on a live instance and documented on the Vue side, which had the
		// identical bug (`GovernanceBodyEvaluationsTab::scoreSummaryFor()`, where
		// `JSON.parse()` on the object turned every real summary into null).
		// Here `(string)` on an array yields the literal "Array", `json_decode`
		// returns null, and EVERY aggregate below silently fell back to null — so a
		// published board evaluation carried `overallScore: null` while the stored
		// object held a perfectly good score. Same defect class, other language.
		$rawSummary = ($evaluation['scoreSummary'] ?? null);
		$scoreSummary = [];
		if (is_array($rawSummary) === true) {
			$scoreSummary = $rawSummary;
		} elseif (is_string($rawSummary) === true && $rawSummary !== '') {
			$decoded = json_decode($rawSummary, true);
			if (is_array($decoded) === true) {
				$scoreSummary = $decoded;
			}
		}

		$summary = [
			'summaryType' => 'board-evaluation-results',
			'title' => sprintf('Board evaluation %s', (string)($evaluation['cycleLabel'] ?? '')),
			'cycleLabel' => (string)($evaluation['cycleLabel'] ?? ''),
			// Aggregate-only: overallScore + (already-suppressed) breakdowns.
			// No participant identity, no raw EvaluationResponse, ever.
			'overallScore' => $scoreSummary['overallScore'] ?? null,
			'respondentCount' => $scoreSummary['respondentCount'] ?? ($evaluation['respondedCount'] ?? 0),
			'dimensionScores' => $scoreSummary['dimensionScores'] ?? null,
			'themes' => $scoreSummary['themes'] ?? null,
			'suppressed' => $scoreSummary['suppressed'] ?? true,
			'sourceId' => $evaluationId,
			'generatedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		// Mark the evaluation as published.
		$evaluation['lifecycle'] = 'published';

		// `$evaluation` is about to be written back as `$sourceObject`, and the read
		// above establishes that OpenRegister handed `scoreSummary` over as an ARRAY
		// while the schema declares it `type: "string"`. Saving it in that shape fails
		// validation for the whole object — and `publishSummary()` swallows that
		// failure in a `catch (\Throwable)` that only logs a warning, so the endpoint
		// still answered 200 with `publishedPredicateSet: false` and the evaluation
		// was never touched (`lifecycle` stayed `closed`, `publicatiedatum` absent).
		// Restore the declared shape before the save.
		if (isset($evaluation['scoreSummary']) === true && is_array($evaluation['scoreSummary']) === true) {
			$evaluation['scoreSummary'] = json_encode($evaluation['scoreSummary']);
		}

		return $this->publishSummary(
			summary: $summary,
			sourceSchema: 'board-evaluation',
			sourceId: $evaluationId,
			governanceBodyId: $this->resolveGovernanceBodyId(object: $evaluation),
			sourceObject: $evaluation
		);

	}//end publishEvaluationResults()

	/**
	 * Build a PII-free digest of approved reactions for a consultation.
	 *
	 * Returns ONLY the reaction body (and submittedAt) — never submitterId,
	 * never the pseudonymous token.
	 *
	 * @param string $consultationId The consultation UUID.
	 *
	 * @return array<int, array<string, string>> The reaction digest.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function buildReactionDigest(string $consultationId): array {
		$objectService = $this->objectService();
		$objectService->setRegister('decidiq');
		$objectService->setSchema('consultation-reaction');
		// NOT `_relations.public-consultation`: reactions are written with a
		// structured `relations` array (ReactionIntakeService), which OpenRegister
		// flattens to `_relations` keys of the form `relations.<n>.id` — a
		// slug-keyed filter matched zero rows, so the digest was always empty on a
		// healthy 200. See ObjectRelationFilter.
		//
		// The filter pins the related id but not the related SCHEMA, so the set is
		// re-checked against the consultation before anything is published. That
		// re-check is a disclosure boundary, not a nicety: this digest is public
		// output, and an unscoped row would publish another consultation's
		// reactions.
		// Resolved by FQCN string, matching objectService() above. A
		// `ObjectRelationFilter::class` reference here counts as one more coupled
		// type and pushes this class over phpmd's CouplingBetweenObjects ceiling.
		$relationFilter = $this->container->get('OCA\Decidiq\Service\ObjectRelationFilter');
		$entities = $relationFilter->matching(
			entities: $objectService->findAll(
				[
					'filters' => array_merge(
						$relationFilter->filterFor(targetId: $consultationId),
						['moderationStatus' => 'approved']
					),
				]
			),
			schema: 'public-consultation',
			targetId: $consultationId
		);

		$digest = [];
		foreach ($entities as $entity) {
			$reaction = $entity->jsonSerialize();
			if ((string)($reaction['moderationStatus'] ?? '') !== 'approved') {
				continue;
			}

			// PII-free: body + timestamp only. No submitterId.
			$digest[] = [
				'body' => (string)($reaction['body'] ?? ''),
				'submittedAt' => (string)($reaction['submittedAt'] ?? ''),
			];
		}

		return $digest;
	}//end buildReactionDigest()

	/**
	 * Persist the summary onto the source object, attempt the published-predicate,
	 * and route to OpenCatalogi.
	 *
	 * The PII-free summary is stored as a `resultsSummary` JSON field on the
	 * source object (consultation or budget round) and the source object's
	 * `publicationDate` is set — the public-group RBAC rule on the schema then
	 * makes it anonymously readable, avoiding an undeclared schema while still
	 * producing one anonymously-publishable result object.
	 *
	 * @param array<string, mixed> $summary The PII-free summary payload.
	 * @param string $sourceSchema The source schema slug.
	 * @param string $sourceId The source object UUID.
	 * @param string|null $governanceBodyId The owning governance body, for catalog targeting.
	 * @param array<string, mixed>|null $sourceObject The already-loaded source object (optional; loaded if null).
	 *
	 * @return array<string, mixed> Keys: summary (array), publishedPredicateSet,
	 *                              anonVisibilityVerified, openCatalogiInstalled,
	 *                              openCatalogiRouted (bool), warning (string|null).
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function publishSummary(
		array $summary,
		string $sourceSchema,
		string $sourceId,
		?string $governanceBodyId,
		?array $sourceObject = null,
	): array {
		$objectService = $this->objectService();

		if ($sourceObject === null) {
			$entity = $objectService->find(id: $sourceId, register: 'decidiq', schema: $sourceSchema);
			$sourceObject = [];
			if ($entity !== null) {
				$sourceObject = $entity->jsonSerialize();
			}
		}

		// Attach the PII-free summary and set publicationDate so the public-group
		// RBAC rule (publicationDate <= $now) on the schema makes the object
		// anonymously readable through the OR published-predicate surface.
		$sourceObject['resultsSummary'] = json_encode($summary);
		$sourceObject['publicationDate'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		// `depublicationDate` is declared `type: "string", format: "date-time"` and is
		// NOT nullable, and OpenRegister's validator rejects an explicit null for a
		// typed property rather than reading it as "absent". Writing the key failed the
		// entire save, which the catch below turns into a logged warning under an
		// HTTP 200 — the publish looked successful and persisted nothing. Publishing
		// means "not depublished", and the absence of the key is how that is spelled.
		//
		// The rename branch had `= null` here. Merging development's fix in means
		// keeping the unset() and only moving the key name; taking "ours" wholesale
		// would have reverted the fix and restored the silent-no-op publish.
		unset($sourceObject['depublicationDate']);

		$predicateSet = false;
		$persistError = null;
		try {
			$saved = $objectService->saveObject(register: 'decidiq', schema: $sourceSchema, object: $sourceObject);
			$summary = array_merge($summary, ['sourceObject' => $this->normaliseSaved(saved: $saved, fallback: $sourceObject)]);
			$predicateSet = true;
		} catch (\Throwable $e) {
			// This catch is deliberate — a catalog-routing flow should not 500 because
			// the predicate write failed — but as a WARNING with no reader it made a
			// total failure indistinguishable from success: the endpoint answered 200,
			// `publishedPredicateSet` was the only tell, and nothing said why. Carry
			// the reason out in `warning` so the caller can see it, and log at error
			// level, because nothing about this is advisory: the object was NOT
			// published.
			$persistError = $e->getMessage();
			$this->logger->error(
				'Decidiq participation: failed to persist published summary',
				['error' => $persistError, 'schema' => $sourceSchema, 'sourceId' => $sourceId]
			);
		}

		$catalogiInstalled = $this->isOpenCatalogiInstalled();
		$openCatalogiRouted = false;

		// Default to the "not installed" warning; the branch below refines it.
		$warning = 'OpenCatalogi is not installed; the catalog routing step was skipped. '
			. 'The summary carries the published predicate only.';

		if ($catalogiInstalled === true) {
			$warning = null;
			$openCatalogiRouted = $this->routeToOpenCatalogi(summary: $summary, governanceBodyId: $governanceBodyId);
			if ($openCatalogiRouted === false) {
				$warning = 'OpenCatalogi is installed but no target catalog is configured for this governance body; '
					. 'the summary was not routed to a catalog.';
			}
		}

		if ($persistError !== null) {
			$failure = 'The published predicate could not be persisted, so the object is NOT publicly readable: '
				. $persistError;
			$warning = trim($failure . ' ' . ($warning ?? ''));
		}

		return [
			'summary' => $summary,
			'publishedPredicateSet' => $predicateSet,
			// Anonymous visibility is governed by the public-group RBAC rule on
			// the published schema (publicationDate <= $now); when the predicate
			// write succeeded the object is publicly readable.
			'anonVisibilityVerified' => $predicateSet,
			'openCatalogiInstalled' => $catalogiInstalled,
			'openCatalogiRouted' => $openCatalogiRouted,
			'warning' => $warning,
		];

	}//end publishSummary()

	/**
	 * Publish (set publicationDate on) a single approved reaction (moderator opt-in).
	 *
	 * Never blanket: the moderator publishes one reaction at a time. The
	 * reaction body carries no PII (the submitterId stays internal and is not
	 * part of the published predicate surface payload by convention).
	 *
	 * @param string $reactionId The approved reaction UUID.
	 *
	 * @return array<string, mixed> The updated reaction object.
	 *
	 * @throws RuntimeException When the reaction is missing or not approved.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function publishReaction(string $reactionId): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $reactionId, register: 'decidiq', schema: 'consultation-reaction');
		if ($entity === null) {
			throw new RuntimeException("ConsultationReaction {$reactionId} not found");
		}

		$reaction = $entity->jsonSerialize();
		if ((string)($reaction['moderationStatus'] ?? '') !== 'approved') {
			throw new RuntimeException('Only approved reactions may be published');
		}

		$reaction['publicationDate'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$reaction['depublicationDate'] = null;
		$saved = $objectService->saveObject(register: 'decidiq', schema: 'consultation-reaction', object: $reaction);

		return $this->normaliseSaved(saved: $saved, fallback: $reaction);
	}//end publishReaction()

	/**
	 * Whether the OpenCatalogi app is installed and enabled.
	 *
	 * @return bool True when OpenCatalogi is available.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function isOpenCatalogiInstalled(): bool {
		try {
			return $this->appManager->isEnabledForAnyone('opencatalogi');
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq participation: OpenCatalogi presence check failed', ['error' => $e->getMessage()]);
			return false;
		}

	}//end isOpenCatalogiInstalled()

	/**
	 * Route a summary into the configured OpenCatalogi catalog for a governance body.
	 *
	 * Reads the target catalog from app config keyed by governance body. When
	 * no target is configured, returns false so the caller degrades with a
	 * warning.
	 *
	 * @param array<string, mixed> $summary The summary object.
	 * @param string|null $governanceBodyId The owning governance body UUID.
	 *
	 * @return bool True when a catalog publication was created.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function routeToOpenCatalogi(array $summary, ?string $governanceBodyId): bool {
		$configKey = 'participation_catalog';
		if ($governanceBodyId !== null && $governanceBodyId !== '') {
			$configKey .= '_' . $governanceBodyId;
		}

		$catalogId = $this->appConfig->getValueString(self::APP_ID, $configKey, '');
		if ($catalogId === '') {
			// Fall back to the instance-wide default target catalog.
			$catalogId = $this->appConfig->getValueString(self::APP_ID, 'participation_catalog', '');
		}

		if ($catalogId === '') {
			return false;
		}

		try {
			$objectService = $this->objectService();
			$publication = [
				'title' => (string)($summary['title'] ?? 'Participation results'),
				'summary' => (string)($summary['description'] ?? ''),
				'catalog' => $catalogId,
				'sourceId' => (string)($summary['sourceId'] ?? ''),
				'publishedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'publicationDate' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			];
			$objectService->saveObject(register: 'opencatalogi', schema: 'publication', object: $publication);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq participation: OpenCatalogi routing failed',
				['error' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end routeToOpenCatalogi()

	/**
	 * Resolve the governance-body UUID from an object's relations.
	 *
	 * @param array<string, mixed> $object The consultation/budget object.
	 *
	 * @return string|null The governance-body UUID, or null.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function resolveGovernanceBodyId(array $object): ?string {
		foreach (($object['relations'] ?? []) as $relation) {
			if (is_array($relation) === true && ($relation['schema'] ?? '') === 'governance-body') {
				$id = ($relation['id'] ?? null);
				if ($id !== null && $id !== '') {
					return (string)$id;
				}
			}
		}

		$flat = ($object['governanceBody'] ?? null);
		if (is_string($flat) === true && $flat !== '') {
			return $flat;
		}

		return null;
	}//end resolveGovernanceBodyId()
}//end class
