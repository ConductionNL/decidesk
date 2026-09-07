<?php

/**
 * Decidiq approval-route command engine.
 *
 * The thin idempotent layer the cross-app seam sits on. It resolves a route
 * template by its provenance pair and then DELEGATES to
 * {@see \OCA\Decidiq\Service\ApprovalRouteService} for everything a route
 * actually does.
 *
 * 🔴 IT IS NOT A SECOND ENGINE, and that is the whole design constraint. Which
 * stage is active, what a return does to the stages after it, which actors may
 * act, whether a mandatory stage may be skipped — all of that lives in
 * ApprovalRouteService and none of it is restated here. A seam that
 * re-implemented any of it would drift from the REST path silently, and the two
 * would answer differently for the same action.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Idempotent upsert of an approval-route template, plus delegated travel.
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class ApprovalRouteCommandService {

	/**
	 * Schema slug of the route template.
	 */
	private const SCHEMA_ROUTE = 'approval-route';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store  Reads and writes decidiq's register objects.
	 * @param ApprovalRouteService $engine The route engine every rule lives in.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ApprovalRouteService $engine,
	) {
	}//end __construct()

	/**
	 * Hold a route template, and optionally start a subject travelling it.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own id for the route.
	 * @param array<string, mixed> $template The template: name, steps and the optional descriptors.
	 * @param string $subject Optional subject to instantiate against.
	 * @param string $subjectSchema Schema slug of that subject.
	 *
	 * @return array{id: string, created: bool, stageCount: int} What happened.
	 *
	 * @throws RuntimeException When the command is incomplete or a write fails.
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function holdRoute(
		string $sourceApp,
		string $externalReference,
		array $template,
		string $subject = '',
		string $subjectSchema = '',
	): array {
		if ($sourceApp === '' || $externalReference === '') {
			throw new RuntimeException(
				'An approval-route command needs both sourceApp and externalReference: they are the key a re-run resolves on'
			);
		}

		$payload = $this->buildTemplate(
			sourceApp: $sourceApp,
			externalReference: $externalReference,
			template: $template,
		);

		$existing = $this->findRoute(sourceApp: $sourceApp, externalReference: $externalReference);
		$created = ($existing === null);

		$stored = $this->store->save(
			schema: self::SCHEMA_ROUTE,
			object: $payload,
			uuid: $this->idOf(row: ($existing ?? [])),
		);

		$routeId = $this->idOf(row: $stored);
		if ($routeId === null) {
			throw new RuntimeException('OpenRegister returned an approval route with no id');
		}

		$stageCount = 0;
		if ($subject !== '') {
			// Delegated, not reimplemented. instantiate() already returns the
			// existing stages when the subject has any, so travelling twice does
			// not double the stages — inherited deliberately and pinned by a test,
			// because a behaviour relied on across an app boundary should fail
			// loudly if it ever changes.
			$stages = $this->engine->instantiate(
				route: $stored,
				subject: $subject,
				subjectSchema: $subjectSchema,
			);
			$stageCount = count($stages);
		}

		return [
			'id' => $routeId,
			'created' => $created,
			'stageCount' => $stageCount,
		];

	}//end holdRoute()

	/**
	 * Record one action against a subject already travelling a route.
	 *
	 * @param array<string, mixed> $action The action, in the engine's own shape.
	 *
	 * @return array{recorded: bool, completed: bool} What happened.
	 *
	 * @throws RuntimeException When the engine refuses.
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function recordAction(array $action): array {
		// Straight through. Every refusal below this line is the engine's, and
		// its message is the one the producer sees, because "you are not the
		// named actor" and "there is nothing to act on" need different handling
		// and a bare false collapses them.
		$this->engine->record(action: $action);

		$subject = (string)($action['subject'] ?? '');
		$completed = ($this->activeStageOf(subject: $subject) === null);

		return [
			'recorded' => true,
			'completed' => $completed,
		];

	}//end recordAction()

	/**
	 * The outcome recorded on the last decided stage of a subject's route.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return string The outcome, or an empty string.
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function finalOutcomeOf(string $subject): string {
		$stages = $this->engine->stagesFor(subject: $subject);

		$outcome = '';
		foreach ($stages as $stage) {
			$stageOutcome = (string)($stage['outcome'] ?? '');
			if ($stageOutcome !== '') {
				$outcome = $stageOutcome;
			}
		}

		return $outcome;

	}//end finalOutcomeOf()

	/**
	 * Whether the subject still has a stage waiting on somebody.
	 *
	 * Asks the engine for the stages rather than querying them here, so the seam
	 * and the engine cannot disagree about what a route's state is.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return array<string, mixed>|null The active stage, or null.
	 */
	private function activeStageOf(string $subject): ?array {
		foreach ($this->engine->stagesFor(subject: $subject) as $stage) {
			if ((string)($stage['status'] ?? '') === 'active') {
				return $stage;
			}
		}

		return null;

	}//end activeStageOf()

	/**
	 * Build the template object to store.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own reference.
	 * @param array<string, mixed> $template The commanded template fields.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @throws RuntimeException When the template is unusable.
	 */
	private function buildTemplate(string $sourceApp, string $externalReference, array $template): array {
		$name = trim((string)($template['name'] ?? ''));
		if ($name === '') {
			throw new RuntimeException('An approval-route command needs a name');
		}

		$steps = ($template['steps'] ?? []);
		if (is_array($steps) === false || $steps === []) {
			// A route with no steps is a route nothing can travel, and
			// instantiate() would refuse it later with a message about a route
			// the producer no longer has in hand. Refuse it here instead.
			throw new RuntimeException('An approval route declares no steps, so there is nothing to travel');
		}

		$payload = [
			'name' => $name,
			'steps' => array_values($steps),
			'active' => (bool)($template['active'] ?? true),
			'isDefault' => (bool)($template['isDefault'] ?? false),
			'sourceApp' => $sourceApp,
			'externalReference' => $externalReference,
		];

		foreach (['subjectType', 'description'] as $key) {
			$value = trim((string)($template[$key] ?? ''));
			if ($value !== '') {
				$payload[$key] = $value;
			}
		}

		return $payload;

	}//end buildTemplate()

	/**
	 * Resolve an existing route by its provenance pair.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own reference.
	 *
	 * @return array<string, mixed>|null The row, or null when none matches.
	 */
	private function findRoute(string $sourceApp, string $externalReference): ?array {
		$rows = $this->store->findAll(
			schema: self::SCHEMA_ROUTE,
			filters: [
				'sourceApp' => $sourceApp,
				'externalReference' => $externalReference,
			],
		);

		// Re-checked in PHP rather than trusted. A filter key the store does not
		// recognise is dropped rather than refused, and a dropped filter returns
		// EVERY route — which would make this match the first unrelated one and
		// overwrite somebody else's sign-off template.
		foreach ($rows as $row) {
			$matchesApp = ((string)($row['sourceApp'] ?? '') === $sourceApp);
			$matchesRef = ((string)($row['externalReference'] ?? '') === $externalReference);
			if ($matchesApp === true && $matchesRef === true) {
				return $row;
			}
		}

		return null;

	}//end findRoute()

	/**
	 * Read an object's id out of either shape OpenRegister returns.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null The id, or null when absent.
	 */
	private function idOf(array $row): ?string {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($id === '') {
			return null;
		}

		return $id;

	}//end idOf()

}//end class
