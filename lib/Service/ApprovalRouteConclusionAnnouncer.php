<?php

/**
 * Decidiq approval-route conclusion announcer.
 *
 * The ONE place a finished route becomes an `ApprovalRouteConcludedEvent`.
 * Three paths can decide a route's final stage — the cross-app action command,
 * this app's own REST surface, and a projected task answered from the inbox —
 * and before this class existed only the first announced. A producer that
 * delegated its runtime here would then have waited forever on a conclusion
 * that fired only when the final signature happened to arrive over the seam.
 *
 * It asks the ENGINE for the stages rather than querying them itself
 * (REQ-ARE-004: no second reader of route state), resolves the provenance pair
 * from the route the stages back-reference, and carries the full sign-off
 * record on the event so the producer can keep who-signed-what-when as case
 * data without reading this register back (ADR-022).
 *
 * A route with NO source app announces nothing: an internally instantiated
 * route has no producer waiting, and decisions set this precedent — an
 * internal decision emits no DecisionConcludedEvent either.
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
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Event\ApprovalRouteConcludedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Announces a concluded route, from whichever path concluded it.
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class ApprovalRouteConclusionAnnouncer {

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteService $engine The route engine every rule lives in.
	 * @param RegisterObjectStore $store Resolves the route row and the action trail.
	 * @param IEventDispatcher $dispatcher Dispatches the conclusion.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ApprovalRouteService $engine,
		private readonly RegisterObjectStore $store,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Announce the subject's conclusion, when it has one.
	 *
	 * Safe to call after every recorded action: a route that still holds an
	 * active stage is not concluded and announces nothing, and a subject with
	 * no stages at all never travelled a route.
	 *
	 * Never throws: the action that concluded the route is already recorded,
	 * and an announcement failure must not read as a refusal of the signature.
	 *
	 * @param string $subject The subject uuid.
	 * @param string $correlationId Correlation id to echo when the caller holds
	 *        one (the cross-app command path does); resolved from the route's
	 *        external reference otherwise.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function announceIfConcluded(string $subject, string $correlationId = ''): void {
		try {
			$this->announce(subject: $subject, correlationId: $correlationId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: could not announce a concluded approval route',
				['subject' => $subject, 'error' => $e->getMessage()]
			);
		}
	}//end announceIfConcluded()

	/**
	 * The unguarded announcement.
	 *
	 * @param string $subject The subject uuid.
	 * @param string $correlationId Correlation override from the caller.
	 *
	 * @return void
	 */
	private function announce(string $subject, string $correlationId): void {
		$conclusion = $this->conclusionOf(stages: $this->engine->stagesFor(subject: $subject));
		if ($conclusion === null) {
			return;
		}

		[$subjectSchema, $routeId, $outcome] = $conclusion;

		[$sourceApp, $externalReference] = $this->provenanceOf(routeId: $routeId);
		if ($sourceApp === '') {
			// Internal route: no producer is waiting on an event.
			return;
		}

		$actions = $this->actionTrail(subject: $subject);
		$actor = '';
		if ($actions !== []) {
			$actor = (string)($actions[(count($actions) - 1)]['actor'] ?? '');
		}

		if ($correlationId === '') {
			$correlationId = $externalReference;
		}

		$this->dispatcher->dispatchTyped(
			new ApprovalRouteConcludedEvent(
				subject: $subject,
				sourceApp: $sourceApp,
				outcome: $outcome,
				actor: $actor,
				correlationId: $correlationId,
				subjectSchema: $subjectSchema,
				externalReference: $externalReference,
				actions: $actions,
			)
		);
	}//end announce()

	/**
	 * What the stages say, when they say "concluded".
	 *
	 * Null when the subject never travelled a route, is still travelling (an
	 * active stage remains), or was instantiated and never touched (no stage
	 * ever decided anything).
	 *
	 * @param array<int, array<string, mixed>> $stages The subject's stages.
	 *
	 * @return array{0: string, 1: string, 2: string}|null [subjectSchema, routeId, outcome].
	 */
	private function conclusionOf(array $stages): ?array {
		if ($stages === []) {
			return null;
		}

		$subjectSchema = '';
		$routeId = '';
		$outcome = '';
		foreach ($stages as $stage) {
			if ((string)($stage['status'] ?? '') === 'active') {
				// Still travelling.
				return null;
			}

			$stageOutcome = (string)($stage['outcome'] ?? '');
			if ($stageOutcome !== '') {
				$outcome = $stageOutcome;
			}

			if ($subjectSchema === '') {
				$subjectSchema = (string)($stage['note'] ?? '');
			}

			if ($routeId === '') {
				$routeId = (string)($stage['route'] ?? '');
			}
		}

		if ($outcome === '') {
			// No stage ever decided: instantiated and untouched, not concluded.
			return null;
		}

		return [$subjectSchema, $routeId, $outcome];
	}//end conclusionOf()

	/**
	 * The provenance pair of the route these stages came from.
	 *
	 * Resolved with find(), NOT findAll() with a top-level 'id' filter: that
	 * filter runs against the object's own JSON properties, where no `id`
	 * lives, so it matched NOTHING — every route's provenance resolved to
	 * empty, read as "internal route, no producer waiting", and the
	 * conclusion was silently skipped. dossiq never heard a single one.
	 *
	 * @param string $routeId The route id the stages back-reference.
	 *
	 * @return array{0: string, 1: string} [sourceApp, externalReference].
	 */
	private function provenanceOf(string $routeId): array {
		if ($routeId === '') {
			return ['', ''];
		}

		$row = $this->store->find(schema: 'approval-route', uuid: $routeId);
		if ($row === null) {
			return ['', ''];
		}

		return [
			(string)($row['sourceApp'] ?? ''),
			(string)($row['externalReference'] ?? ''),
		];
	}//end provenanceOf()

	/**
	 * Every action recorded against the subject, chronological.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 */
	private function actionTrail(string $subject): array {
		$rows = $this->store->findAll(schema: 'approval-action', filters: ['subject' => $subject]);
		usort(
			$rows,
			static fn (array $a, array $b): int => strcmp(
				(string)($a['recordedAt'] ?? ''),
				(string)($b['recordedAt'] ?? '')
			)
		);

		return $rows;
	}//end actionTrail()

}//end class
