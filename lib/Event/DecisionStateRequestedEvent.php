<?php

/**
 * Decidiq DecisionStateRequestedEvent
 *
 * The READ half of the cross-app decision contract. `DecisionRequestedEvent`
 * lets a consumer app raise a Decision and read its id back; this event lets
 * the same consumer come back later, holding only that id, and ask what
 * happened to it.
 *
 * 🔴 THIS IS NOT A SECOND DELIVERY MECHANISM. A concluded Decision is
 * ANNOUNCED by `DecisionConcludedEvent`, and that is how a consumer normally
 * learns the outcome. This event exists for the case where the announcement
 * was missed — the consumer was mid-upgrade, its listener threw, the run that
 * was waiting had already been resumed by something else — and the consumer
 * has nothing to consult. It makes the state READABLE; it delivers nothing.
 *
 * @category Event
 * @package  OCA\Decidiq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app read event: a consumer app asks Decidiq what became of a Decision.
 *
 * The request fields are immutable; the answer is written into result slots by
 * `DecisionStateRequestedListener` and read by the producer straight after
 * `dispatchTyped()` — the same synchronous request/response-over-the-bus shape
 * `DecisionRequestedEvent` already uses, so a consumer that can raise a
 * decision can read one back without learning a second mechanism.
 *
 * 🔴 THREE ANSWERS, NOT TWO. "I could not read it", "it is not there" and "you
 * may not see it" are different facts and a consumer acts differently on each:
 * an unreadable seam is worth waiting through, a vanished Decision is not, and
 * a refusal is a misconfiguration to surface rather than a state to poll. So
 * `isHandled()`, `isFound()` and `isPermitted()` are three separate slots and
 * an absent envelope never has to be interpreted.
 *
 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
 */
class DecisionStateRequestedEvent extends Event {

	/**
	 * Whether Decidiq's listener answered this request at all (result slot).
	 *
	 * False means the seam could not answer — Decidiq absent, its listener
	 * unregistered, or the read itself failed. It never means "no decision".
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Whether the named actor may read this Decision's outcome (result slot).
	 *
	 * @var boolean
	 */
	private bool $permitted = false;

	/**
	 * Whether a Decision carrying this id exists (result slot).
	 *
	 * Only meaningful once `isPermitted()` is true: an unauthorized caller is
	 * never told whether the id it named resolves to anything.
	 *
	 * @var boolean
	 */
	private bool $found = false;

	/**
	 * The outcome envelope, when the caller may read it and it exists.
	 *
	 * The SAME array `DecisionIntegrationService::getOutcomeEnvelope()` builds
	 * and `DecisionConcludedEvent::fromEnvelope()` consumes, so the announced
	 * path and the read-back path cannot describe one Decision differently.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $envelope = null;

	/**
	 * Construct the read request.
	 *
	 * @param string $sourceApp The consumer app asking (logged; never a grant)
	 * @param string $decisionId The id of the Decision to report on
	 * @param string $actorId Nextcloud UID the read is authorized AS — empty is refused, never elevated
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $decisionId,
		private readonly string $actorId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the consumer app asking for the state.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Get the id of the Decision being asked about.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function getDecisionId(): string {
		return $this->decisionId;
	}//end getDecisionId()

	/**
	 * Get the Nextcloud UID the read is authorized as.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * Whether Decidiq's listener answered this request.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Mark whether Decidiq's listener answered this request.
	 *
	 * @param bool $handled True when Decidiq resolved the request to an answer
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()

	/**
	 * Whether the named actor may read this Decision's outcome.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function isPermitted(): bool {
		return $this->permitted;
	}//end isPermitted()

	/**
	 * Mark whether the named actor may read this Decision's outcome.
	 *
	 * @param bool $permitted True when the authorization guard allows the read
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function setPermitted(bool $permitted): void {
		$this->permitted = $permitted;
	}//end setPermitted()

	/**
	 * Whether a Decision carrying this id exists.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function isFound(): bool {
		return $this->found;
	}//end isFound()

	/**
	 * Mark whether a Decision carrying this id exists.
	 *
	 * @param bool $found True when the Decision resolved
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function setFound(bool $found): void {
		$this->found = $found;
	}//end setFound()

	/**
	 * Get the outcome envelope, or null when there is none to report.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function getEnvelope(): ?array {
		return $this->envelope;
	}//end getEnvelope()

	/**
	 * Set the outcome envelope (written by Decidiq's listener).
	 *
	 * @param array<string, mixed> $envelope The envelope from getOutcomeEnvelope()
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function setEnvelope(array $envelope): void {
		$this->envelope = $envelope;
	}//end setEnvelope()

	/**
	 * The derived outcome status, when one was reported.
	 *
	 * `approved` / `rejected` / `withdrawn` / `pending` — the SAME vocabulary
	 * `DecisionConcludedEvent::getStatus()` carries, deliberately, so a
	 * consumer maps it once. Null when there is no envelope to read it from.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function getStatus(): ?string {
		if ($this->envelope === null) {
			return null;
		}

		return (string)($this->envelope['status'] ?? 'pending');
	}//end getStatus()
}//end class
