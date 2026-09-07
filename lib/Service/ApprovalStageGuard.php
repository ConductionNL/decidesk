<?php

/**
 * Decidiq approval-stage guard.
 *
 * The fail-closed gate every approval action passes through: which active
 * stage an action addresses, whether this actor (or their mandated delegate)
 * may act on it, whether the verb fits the stage's type, and whether the
 * mandatory free text is present. Split out of ApprovalRouteService the same
 * way dossiq split ParafeerStepGuard out of its action service — so the
 * question "is this caller allowed to do this here?" has exactly one owner —
 * and NOT a second engine: it advances nothing and writes nothing.
 *
 * Every path throws rather than returning a boolean, and no path has a
 * permissive default (OWASP A01:2021, ADR-005 Rule 3).
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

use RuntimeException;

/**
 * Resolves the addressed stage and authorises the action against it.
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class ApprovalStageGuard {

	/**
	 * The one completing verb each sign-off stage type accepts.
	 *
	 * Absorbed from dossiq's ParafeerStepGuard, where an advies step accepted
	 * only `advised` and an accordering step only `accorded`. An `approved` on
	 * an advisory stage is a stronger claim than the stage asked for, and a
	 * chain that accepts it records a decision nobody was asked to take.
	 *
	 * A stage type absent from this map (preparatory, ratifying) keeps the
	 * engine's original any-completing-verb behaviour: those stages predate
	 * the parafering vocabulary and no producer constrains them yet.
	 *
	 * @var array<string, string>
	 */
	private const STAGE_COMPLETING_VERBS = [
		'advisory' => 'advised',
		'endorsement' => 'endorsed',
		'decisive' => 'approved',
	];

	/**
	 * Constructor.
	 *
	 * @param MandateDirectory $mandates Judges a delegate's mandate reference.
	 */
	public function __construct(
		private readonly MandateDirectory $mandates,
	) {
	}//end __construct()

	/**
	 * The active stage this action addresses.
	 *
	 * One active stage is the ordinary case and is simply taken. A PARALLEL
	 * group holds several, and the action lands on the one naming this actor
	 * (or the delegate's principal) — falling back to a stage naming nobody.
	 * An actor no active stage will have is refused here, with the same
	 * refusal the single-stage actor check gives.
	 *
	 * @param array<int, array<string, mixed>> $actives The active stages.
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed> The addressed stage.
	 *
	 * @throws RuntimeException When no active stage accepts this actor.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function stageForAction(array $actives, array $action): array {
		if (count($actives) === 1) {
			$this->assertActorMayAct(stage: $actives[0], action: $action);

			return $actives[0];
		}

		$unassigned = null;
		foreach ($actives as $stage) {
			$assigned = (string)($stage['assignedPerson'] ?? '');
			if ($assigned === '') {
				$unassigned = ($unassigned ?? $stage);
				continue;
			}

			if ($this->actorMatchesAssignee(stage: $stage, action: $action) === true) {
				$this->assertActorMayAct(stage: $stage, action: $action);

				return $stage;
			}
		}

		if ($unassigned !== null) {
			$this->assertActorMayAct(stage: $unassigned, action: $action);

			return $unassigned;
		}

		throw new RuntimeException('This stage is assigned to someone else.');
	}//end stageForAction()

	/**
	 * Refuse a completing verb the stage's type did not ask for.
	 *
	 * @param array<string, mixed> $stage The active stage.
	 * @param string $verb The completing verb.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the verb does not fit the stage type.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function assertVerbFitsStage(array $stage, string $verb): void {
		if ($verb === 'skipped') {
			return;
		}

		$stageType = (string)($stage['stageType'] ?? '');
		$expected = (self::STAGE_COMPLETING_VERBS[$stageType] ?? '');
		if ($expected !== '' && $verb !== $expected) {
			throw new RuntimeException(
				'A ' . $stageType . ' stage completes with "' . $expected . '", not "' . $verb . '".'
			);
		}
	}//end assertVerbFitsStage()

	/**
	 * Refuse an action missing the free text its verb makes mandatory.
	 *
	 * A return without a reason strands the sender with a rejection nobody
	 * explained; an advisory sign-off without the advice is a signature on an
	 * empty page. Both rules are dossiq's, absorbed with the runtime.
	 *
	 * @param string $verb The verb.
	 * @param array<string, mixed> $action The action.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a mandatory field is missing.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function assertRequiredFields(string $verb, array $action): void {
		if ($verb === 'returned' && trim((string)($action['comment'] ?? '')) === '') {
			throw new RuntimeException('A return needs a reason.');
		}

		if ($verb === 'advised' && trim((string)($action['advice'] ?? '')) === '') {
			throw new RuntimeException('An advisory stage needs the advice text.');
		}
	}//end assertRequiredFields()

	/**
	 * Refuse a return target at or past the active stage, before anything is written.
	 *
	 * A target of zero (or none) is the terminal return and names no step, so
	 * there is nothing to validate.
	 *
	 * @param array<string, mixed> $action The returned action.
	 * @param array<string, mixed> $active The addressed active stage.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the target does not point backwards.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function assertReturnTargetValid(array $action, array $active): void {
		$target = (int)($action['returnToStep'] ?? 0);
		if ($target > 0 && $target >= (int)$active['sequence']) {
			throw new RuntimeException('A return must name a step BEFORE the active one.');
		}
	}//end assertReturnTargetValid()

	/**
	 * Refuse an actor the active stage does not name.
	 *
	 * A stage naming nobody accepts any authenticated actor — that is a
	 * deliberate route design, not a gap.
	 *
	 * A DELEGATE may act when the stage's person is their `onBehalfOf`
	 * principal AND they present a mandate. The mandate is judged by the
	 * {@see MandateDirectory}: a local toedeling that is not effective, out of
	 * window or issued to somebody else refuses; a reference this register
	 * cannot resolve is the producer's mandate and travels verbatim. Absorbed
	 * from dossiq's ParafeerStepGuard, which recorded the same fields but left
	 * the registry check "for the future MandaatService" — this is that future.
	 *
	 * @param array<string, mixed> $stage The active stage.
	 * @param array<string, mixed> $action The action, carrying actor / onBehalfOf / mandate.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the actor may not act.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function assertActorMayAct(array $stage, array $action): void {
		$assigned = (string)($stage['assignedPerson'] ?? '');
		$actor = (string)($action['actor'] ?? '');
		if ($assigned === '' || $assigned === $actor) {
			return;
		}

		$onBehalfOf = (string)($action['onBehalfOf'] ?? '');
		$mandate = trim((string)($action['mandate'] ?? ''));
		if ($onBehalfOf !== $assigned || $mandate === '') {
			throw new RuntimeException('This stage is assigned to someone else.');
		}

		$this->mandates->assertMayActUnder(mandate: $mandate, actor: $actor);
	}//end assertActorMayAct()

	/**
	 * Whether the acting identity (or their principal) is this stage's assignee.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param array<string, mixed> $action The action.
	 *
	 * @return boolean True when the stage names this action's signer.
	 */
	private function actorMatchesAssignee(array $stage, array $action): bool {
		$assigned = (string)($stage['assignedPerson'] ?? '');
		$actor = (string)($action['actor'] ?? '');
		$onBehalfOf = (string)($action['onBehalfOf'] ?? '');

		return ($assigned === $actor || ($onBehalfOf !== '' && $assigned === $onBehalfOf));
	}//end actorMatchesAssignee()

}//end class
