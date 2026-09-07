<?php

/**
 * Decidiq approval-route engine.
 *
 * Turns an `ApprovalRoute` template into `DecisionStage` rows against a subject,
 * and advances them as `ApprovalAction`s arrive.
 *
 * WHY THIS CLASS EXISTS. `DecisionStage` modelled the stages of a route and
 * NOTHING in this app ever wrote one — six seeded rows, two readers, and a route
 * tab whose own header says it is read-only. A schema without an engine is a
 * description of a capability, not the capability.
 *
 * THE ENGINE IS FAIL-CLOSED. An action by an actor the active stage does not
 * name is refused; a skip of a mandatory stage is refused; a return that points
 * forwards is refused. A sign-off route is only worth anything if the sequence
 * is enforced, and a guard whose result the caller may ignore is not a guard.
 *
 * THE PARAFERING RUNTIME LIVES HERE NOW (parafering-route-runtime). dossiq's
 * pipeline owned four things this engine did not: a stage-typed action
 * vocabulary, mandated delegate signing, a return that goes back to the sender
 * rather than to an earlier step, and steps that run in parallel. All four are
 * absorbed below, so dossiq can retire its route advancement the way it retired
 * decision authoring — raise, wait for the conclusion, record.
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
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use RuntimeException;

/**
 * Instantiates approval routes and advances them.
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The engine owns every route
 *   rule (REQ-ARE-004 forbids a second engine), so the mandate directory and
 *   the task projector attach here rather than growing a sibling.
 */
class ApprovalRouteService {
	/**
	 * Actions that COMPLETE the active stage, mapped to the outcome they record.
	 *
	 * `returned` is deliberately absent: it does not complete a stage, it
	 * re-opens an earlier one or concludes the route back to its sender, and
	 * treating it as a completion is precisely the mistake `rejected` and
	 * `deferred` invite.
	 *
	 * @var array<string, string>
	 */
	private const COMPLETING_ACTIONS = [
		'approved' => 'approved',
		'endorsed' => 'endorsed',
		'advised' => 'advised',
		'skipped' => 'skipped',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes the objects a route is made of.
	 * @param ApprovalStageGuard $guard The fail-closed gate every action passes through.
	 * @param ApprovalRouteStepMapper $mapper Pure shaping of steps into stage fields.
	 * @param ApprovalStageTaskProjector|null $projector Mirrors active stages
	 *        onto OpenRegister's task surface. Nullable so the engine's rules
	 *        never depend on the projection: a missing task surface changes
	 *        where the ask is SEEN, never whether the route advances.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ApprovalStageGuard $guard,
		private readonly ApprovalRouteStepMapper $mapper,
		private readonly ?ApprovalStageTaskProjector $projector = null,
	) {
	}//end __construct()

	/**
	 * Refuse a caller who cannot reach the subject.
	 *
	 * A REAL authorisation check, not an authentication one. Instantiating a
	 * route writes sign-off stages against someone else's object, so "is signed
	 * in" is not the question — "may this user see this subject" is.
	 *
	 * The check is delegated to OpenRegister: the read runs as the acting user,
	 * so OR's register RBAC and multitenancy decide. A user who cannot reach the
	 * subject gets nothing back, and nothing back is a refusal.
	 *
	 * @param string $subject The subject's uuid.
	 * @param string $subjectSchema The subject's schema slug.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the subject cannot be reached.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	public function assertSubjectAccessible(string $subject, string $subjectSchema): void {
		if ($subject === '' || $subjectSchema === '') {
			throw new RuntimeException('A subject and its schema are required.');
		}

		// Resolved with find(), NOT findAll() + a top-level 'id' filter: OpenRegister
		// applies filters to the object's own JSON properties and its identity
		// lives in `@self`, so the filter form matches nothing and reads as
		// "unreachable" for every subject that exists (dossiq#1686's class).
		if ($this->store->find(schema: $subjectSchema, uuid: $subject) === null) {
			throw new RuntimeException('This subject cannot be reached, so no route may be started on it.');
		}
	}//end assertSubjectAccessible()

	/**
	 * Materialise a route's steps as stages against a subject.
	 *
	 * Idempotent: a subject that already has stages is left alone, so a repeated
	 * call cannot give it a second route.
	 *
	 * A stage's `sequence` is the step's OWN `order`, not its position: the
	 * parafering surfaces read the step number and it must mean what the route
	 * meant, and two steps DECLARING the same order are a parallel group that
	 * signs side by side. Steps without an order fall back to their position.
	 *
	 * @param array<string, mixed> $route The ApprovalRoute object.
	 * @param string $subject The subject's uuid.
	 * @param string $subjectSchema The subject's schema.
	 *
	 * @return array<int, array<string, mixed>> The stages, existing or created.
	 *
	 * @throws RuntimeException When the route declares no usable steps.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function instantiate(array $route, string $subject, string $subjectSchema): array {
		$existing = $this->stagesFor(subject: $subject);
		if ($existing !== []) {
			return $existing;
		}

		$steps = $this->mapper->orderedSteps(route: $route);
		if ($steps === []) {
			throw new RuntimeException('This route declares no steps, so there is nothing to travel.');
		}

		$routeId = (string)($route['id'] ?? ($route['@self']['id'] ?? ''));
		$firstSequence = $this->mapper->sequenceOf(step: $steps[0], index: 0);

		$created = [];
		foreach ($steps as $index => $step) {
			$sequence = $this->mapper->sequenceOf(step: $step, index: $index);
			$created[] = $this->store->save(
				schema: 'decision-stage',
				object: [
					'sequence' => $sequence,
					'stageType' => (string)$step['stageType'],
					// Every stage in the FIRST parallel group is active
					// immediately. A route whose every stage is pending is
					// indistinguishable from one nobody has started, and
					// nothing would ever start it.
					'status' => $this->mapper->initialStatus(sequence: $sequence, firstSequence: $firstSequence),
					'decisionMakerType' => $this->mapper->decisionMakerType(step: $step),
					// Derived when the step carries none: the schema REQUIRES a
					// label, an empty string stores as NULL, and a NULL label
					// 400s the patch that records the FIRST sign-off — after
					// the action row was already appended. Cross-app routes
					// (dossiq) never carry step labels, so they always hit it.
					'label' => $this->mapper->labelOf(step: $step, sequence: $sequence),
					'mandatory' => (bool)($step['mandatory'] ?? true),
					'decision' => $subject,
					'assignedPerson' => $this->mapper->assignedPerson(step: $step),
					'assignedBody' => $this->mapper->assignedBody(step: $step),
					'note' => $subjectSchema,
					'route' => $routeId,
				],
			);
		}

		$this->projectTasks(subject: $subject);

		return $created;
	}//end instantiate()

	/**
	 * Record an action and advance the route.
	 *
	 * THE STAGE WRITE COMES FIRST, the action row after. The old order appended
	 * the action and THEN patched the stage, so a stage write that failed left
	 * an orphan action row claiming a sign-off the route never took — and the
	 * signer's retry appended another. With this order a failed stage write
	 * throws before any action row exists, so the retry starts clean. The
	 * inverse gap (stage advanced, action append failed) surfaces loudly: the
	 * caller gets the throw, and a retry is refused by the guard because the
	 * next stage names a different actor.
	 *
	 * @param array<string, mixed> $action The action: subject, step, actor, action, and optional fields.
	 *
	 * @return array<string, mixed> The recorded action.
	 *
	 * @throws RuntimeException When the action is refused.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function record(array $action): array {
		$subject = (string)($action['subject'] ?? '');
		$actor = (string)($action['actor'] ?? '');
		$verb = (string)($action['action'] ?? '');
		if ($subject === '' || $actor === '' || $verb === '') {
			throw new RuntimeException('An action needs a subject, an actor and a verb.');
		}

		$stages = $this->stagesFor(subject: $subject);
		$actives = $this->activeStages(stages: $stages);
		if ($actives === []) {
			throw new RuntimeException('This subject has no active stage; there is nothing to act on.');
		}

		$active = $this->guard->stageForAction(actives: $actives, action: $action);

		if ($verb === 'returned') {
			$this->guard->assertRequiredFields(verb: $verb, action: $action);
			// Validated BEFORE any write: a refused return must leave no
			// action row, the same promise every other refusal keeps.
			$this->guard->assertReturnTargetValid(action: $action, active: $active);
			$this->applyReturnVerb(action: $action, stages: $stages, active: $active);
			$recorded = $this->appendAction(action: $action, stage: $active);
			$this->projectTasks(subject: $subject);

			return $recorded;
		}

		if (isset(self::COMPLETING_ACTIONS[$verb]) === false) {
			throw new RuntimeException('Unknown action: ' . $verb);
		}

		if ($verb === 'skipped' && (bool)($active['mandatory'] ?? true) === true) {
			throw new RuntimeException('This stage is mandatory and cannot be skipped.');
		}

		$this->guard->assertVerbFitsStage(stage: $active, verb: $verb);
		$this->guard->assertRequiredFields(verb: $verb, action: $action);

		$this->completeAndAdvance(stages: $stages, active: $active, verb: $verb);
		$recorded = $this->appendAction(action: $action, stage: $active);
		$this->projectTasks(subject: $subject);

		return $recorded;
	}//end record()

	/**
	 * Route a `returned` action to its meaning.
	 *
	 * Naming a step rewinds the route to it. Naming none concludes the route
	 * back to its sender: that is what dossiq's terugsturen has always meant —
	 * the voorstel goes back to the steller, and the approvers after this one
	 * are never asked.
	 *
	 * @param array<string, mixed> $action The returned action.
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The addressed active stage.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function applyReturnVerb(array $action, array $stages, array $active): void {
		$target = (int)($action['returnToStep'] ?? 0);
		if ($target > 0) {
			$this->applyReturn(action: $action, stages: $stages, active: $active);

			return;
		}

		$this->applyTerminalReturn(stages: $stages, active: $active);
	}//end applyReturnVerb()

	/**
	 * Conclude the route back to its sender.
	 *
	 * The addressed stage records the `returned` outcome. Every OTHER stage
	 * that is still active or pending goes back to `pending` with its outcome
	 * cleared — never `skipped`, because nobody chose to skip it; the route
	 * simply ended before it. No stage is left active, which is the engine's
	 * own definition of a concluded route, and `finalOutcomeOf` then reads
	 * `returned` off the last decided stage.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The stage whose actor returned it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function applyTerminalReturn(array $stages, array $active): void {
		$this->store->patch(
			schema: 'decision-stage',
			data: [
				'status' => 'decided',
				'outcome' => 'returned',
				'decidedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			],
			uuid: (string)$active['id'],
		);

		foreach ($stages as $stage) {
			if ((string)$stage['id'] === (string)$active['id']) {
				continue;
			}

			$status = (string)($stage['status'] ?? '');
			if ($status !== 'active' && $status !== 'pending') {
				continue;
			}

			$this->store->patch(
				schema: 'decision-stage',
				data: ['status' => 'pending', 'outcome' => null, 'decidedAt' => null],
				uuid: (string)$stage['id'],
			);
		}
	}//end applyTerminalReturn()

	/**
	 * Complete the addressed stage and make the next group active.
	 *
	 * A PARALLEL group advances only when its last live member completes: a
	 * group with a sibling still active stays where it is. When no later stage
	 * remains the route is finished, and NO stage is left active — a completed
	 * route that still shows an active stage would keep inviting actions on a
	 * decision already taken.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The addressed stage.
	 * @param string $verb The action verb.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function completeAndAdvance(array $stages, array $active, string $verb): void {
		$status = 'decided';
		if ($verb === 'skipped') {
			$status = 'skipped';
		}

		$this->store->patch(
			schema: 'decision-stage',
			data: [
				'status' => $status,
				'outcome' => self::COMPLETING_ACTIONS[$verb],
				'decidedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			],
			uuid: (string)$active['id'],
		);

		$sequence = (int)$active['sequence'];
		if ($this->groupStillSigning(stages: $stages, active: $active) === true) {
			// A parallel sibling is still signing; the group is not done.
			return;
		}

		$nextSequence = $this->nextPendingSequence(stages: $stages, after: $sequence);
		if ($nextSequence === null) {
			return;
		}

		foreach ($stages as $stage) {
			if ((int)$stage['sequence'] === $nextSequence && (string)($stage['status'] ?? '') === 'pending') {
				$this->store->patch(schema: 'decision-stage', data: ['status' => 'active'], uuid: (string)$stage['id']);
			}
		}
	}//end completeAndAdvance()

	/**
	 * Whether the addressed stage's parallel group still has a live signer.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The addressed stage.
	 *
	 * @return boolean True when a sibling at the same sequence is still active.
	 */
	private function groupStillSigning(array $stages, array $active): bool {
		$sequence = (int)$active['sequence'];
		foreach ($stages as $stage) {
			$sibling = ((int)$stage['sequence'] === $sequence && (string)$stage['id'] !== (string)$active['id']);
			if ($sibling === true && (string)($stage['status'] ?? '') === 'active') {
				return true;
			}
		}

		return false;
	}//end groupStillSigning()

	/**
	 * Re-open an earlier stage and reset everything after it.
	 *
	 * The outcomes of the reset stages are CLEARED, because a stage that is
	 * pending again while still showing an outcome reads as decided to every
	 * consumer that looks at the outcome rather than the status.
	 *
	 * The ApprovalActions are NOT touched. They are what happened; the stages
	 * are where the route is.
	 *
	 * @param array<string, mixed> $action The returned action.
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The active stage.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the target step is not before the active one.
	 */
	private function applyReturn(array $action, array $stages, array $active): void {
		$target = (int)($action['returnToStep'] ?? 0);
		$activeSequence = (int)$active['sequence'];
		if ($target < 1 || $target >= $activeSequence) {
			throw new RuntimeException('A return must name a step BEFORE the active one.');
		}

		foreach ($stages as $stage) {
			$sequence = (int)$stage['sequence'];
			if ($sequence < $target) {
				continue;
			}

			$status = 'pending';
			if ($sequence === $target) {
				$status = 'active';
			}

			$this->store->patch(
				schema: 'decision-stage',
				data: ['status' => $status, 'outcome' => null, 'decidedAt' => null],
				uuid: (string)$stage['id'],
			);
		}
	}//end applyReturn()

	/**
	 * Append the action as a new object.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<string, mixed> $stage The active stage.
	 *
	 * @return array<string, mixed> The stored action.
	 */
	private function appendAction(array $action, array $stage): array {
		return $this->store->save(
			schema: 'approval-action',
			object: [
				'subject' => (string)$action['subject'],
				'subjectSchema' => (string)($action['subjectSchema'] ?? ''),
				'step' => (int)($action['step'] ?? $stage['sequence']),
				'actor' => (string)$action['actor'],
				'actorType' => (string)($action['actorType'] ?? 'user'),
				'onBehalfOf' => (string)($action['onBehalfOf'] ?? ''),
				'mandate' => (string)($action['mandate'] ?? ''),
				'action' => (string)$action['action'],
				'returnToStep' => ($action['returnToStep'] ?? null),
				'comment' => (string)($action['comment'] ?? ''),
				'advice' => (string)($action['advice'] ?? ''),
				'recordedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			],
		);
	}//end appendAction()

	/**
	 * The subject's stages, ordered by sequence.
	 *
	 * PUBLIC because the cross-app command seam needs to answer "did that action
	 * finish the route" and must do it by asking THIS class, not by running its
	 * own query. A second reader of decision-stage rows is how the seam and the
	 * engine start to disagree about what a route's state is.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function stagesFor(string $subject): array {
		$rows = $this->store->findAll(schema: 'decision-stage', filters: ['decision' => $subject]);
		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));

		return $rows;
	}//end stagesFor()

	/**
	 * Every active stage — one ordinarily, several in a parallel group.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages.
	 *
	 * @return array<int, array<string, mixed>> The active stages.
	 */
	private function activeStages(array $stages): array {
		$actives = [];
		foreach ($stages as $stage) {
			if ((string)($stage['status'] ?? '') === 'active') {
				$actives[] = $stage;
			}
		}

		return $actives;
	}//end activeStages()

	/**
	 * The lowest pending sequence after the given one.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages.
	 * @param int $after The sequence to search past.
	 *
	 * @return int|null The next sequence, or null when none remains.
	 */
	private function nextPendingSequence(array $stages, int $after): ?int {
		foreach ($stages as $stage) {
			if ((int)$stage['sequence'] > $after && (string)($stage['status'] ?? '') === 'pending') {
				return (int)$stage['sequence'];
			}
		}

		return null;
	}//end nextPendingSequence()

	/**
	 * Mirror the subject's stages onto the task surface, best effort.
	 *
	 * The projection changes where an ask is SEEN, never whether the route
	 * advances, so a missing or failing task surface is logged by the
	 * projector and swallowed here.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return void
	 */
	private function projectTasks(string $subject): void {
		$this->projector?->sync(subject: $subject, stages: $this->stagesFor(subject: $subject));
	}//end projectTasks()
}//end class
