<?php

/**
 * Decidiq approval-route step mapper.
 *
 * Pure shaping of a route's declared steps into the stage fields the engine
 * writes: ordering, the step's own order as its sequence, the person/body
 * split, and the first-group activation status. Split out of
 * ApprovalRouteService the way dossiq split ParaferingActionMapper out of its
 * action service: every method here is side-effect free — no store, no
 * logging, no events — so the engine keeps only the rules and the writes.
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

/**
 * Shapes route steps into stage fields.
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class ApprovalRouteStepMapper {

	/**
	 * A route's steps, ordered.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return array<int, array<string, mixed>> The steps.
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function orderedSteps(array $route): array {
		$steps = ($route['steps'] ?? []);
		if (is_string($steps) === true) {
			$decoded = json_decode($steps, true);
			if (is_array($decoded) === false) {
				return [];
			}

			$steps = $decoded;
		}

		if (is_array($steps) === false) {
			return [];
		}

		$list = [];
		foreach ($steps as $step) {
			if (is_array($step) === true && isset($step['stageType']) === true) {
				$list[] = $step;
			}
		}

		usort($list, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)));

		return $list;
	}//end orderedSteps()

	/**
	 * The sequence a step's stage carries: the step's own order.
	 *
	 * The parafering surfaces read the step number and it must mean what the
	 * route meant; two steps DECLARING the same order are a parallel group.
	 * A step without an order falls back to its position.
	 *
	 * @param array<string, mixed> $step The route step.
	 * @param int $index The zero-based position, the fallback.
	 *
	 * @return int The sequence.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function sequenceOf(array $step, int $index): int {
		$order = (int)($step['order'] ?? 0);
		if ($order > 0) {
			return $order;
		}

		return ($index + 1);
	}//end sequenceOf()

	/**
	 * How a step's actor is recorded on the stage.
	 *
	 * @param array<string, mixed> $step The route step.
	 *
	 * @return string Either `person` or `body`.
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function decisionMakerType(array $step): string {
		if ((string)($step['actorType'] ?? 'role') === 'body') {
			return 'body';
		}

		return 'person';
	}//end decisionMakerType()

	/**
	 * The stage's assignedPerson, when the step names one.
	 *
	 * A `role` actor is NOT written here: a role is resolved by the consuming
	 * context, and storing the role token in a field that means "this person"
	 * would make every actor check compare a uid against a role name and refuse
	 * everyone.
	 *
	 * @param array<string, mixed> $step The route step.
	 *
	 * @return string The person, or ''.
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function assignedPerson(array $step): string {
		if ((string)($step['actorType'] ?? 'role') !== 'person') {
			return '';
		}

		return (string)($step['actor'] ?? '');
	}//end assignedPerson()

	/**
	 * The stage's assignedBody, when the step names one.
	 *
	 * @param array<string, mixed> $step The route step.
	 *
	 * @return string The body, or ''.
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function assignedBody(array $step): string {
		if ((string)($step['actorType'] ?? 'role') !== 'body') {
			return '';
		}

		return (string)($step['actor'] ?? '');
	}//end assignedBody()

	/**
	 * The label a step's stage carries, derived when the step has none.
	 *
	 * The decision-stage schema REQUIRES a label: the route timeline displays
	 * it, so a stage without one is not a valid stage. That requirement is
	 * kept — the fix lives here instead, because a route held over the
	 * cross-app seam (dossiq's parafering routes) carries no step labels at
	 * all. Writing '' for those stored a NULL, and the patch path re-validates
	 * the whole stage on every advance, so the FIRST sign-off on such a route
	 * 400'd with "Property 'label' should be type 'string' but is 'null'".
	 * instantiate() must produce a stage that validates, whoever sent the
	 * route; a stage the engine itself cannot advance is not a stage.
	 *
	 * The fallback is mechanical — the stage type plus the step number, e.g.
	 * "Endorsement (step 2)" — so the timeline still tells the signer which
	 * step they are looking at.
	 *
	 * @param array<string, mixed> $step The route step.
	 * @param int $sequence The stage's sequence.
	 *
	 * @return string A non-empty label.
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function labelOf(array $step, int $sequence): string {
		$label = trim((string)($step['label'] ?? ''));
		if ($label !== '') {
			return $label;
		}

		return ucfirst((string)$step['stageType']) . ' (step ' . $sequence . ')';
	}//end labelOf()

	/**
	 * The status a stage at this sequence starts in.
	 *
	 * Every stage in the FIRST parallel group is active immediately: a route
	 * whose every stage is pending is indistinguishable from one nobody has
	 * started, and nothing would ever start it.
	 *
	 * @param int $sequence The stage's sequence.
	 * @param int $firstSequence The route's first (lowest) sequence.
	 *
	 * @return string Either `active` or `pending`.
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function initialStatus(int $sequence, int $firstSequence): string {
		if ($sequence === $firstSequence) {
			return 'active';
		}

		return 'pending';
	}//end initialStatus()

}//end class
