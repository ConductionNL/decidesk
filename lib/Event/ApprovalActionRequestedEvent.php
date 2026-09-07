<?php

/**
 * Decidiq ApprovalActionRequestedEvent
 *
 * Public cross-app command event: a consumer app records one actor's action
 * against a subject already travelling an approval route. Per ADR-041 a
 * cross-app COMMAND travels as a typed event; ApprovalRouteController is the
 * door for EXTERNAL callers and refuses an in-process call, which has no
 * session to authenticate with.
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
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * A consumer app records an approval action.
 *
 * 🔴 THE ACTOR IS A REQUEST FIELD HERE, and that is safe only because this event
 * is IN-PROCESS. `ApprovalRouteController` takes the actor from the SESSION and
 * never from the body, precisely so no caller can sign off as somebody else. A
 * producer dispatching this event is trusted code in the same PHP process, which
 * is a different trust boundary from an HTTP request. Any future path that lets
 * an untrusted party construct this event must resolve the actor itself.
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class ApprovalActionRequestedEvent extends Event {

	/**
	 * Whether the action was recorded (result slot).
	 *
	 * @var boolean
	 */
	private bool $recorded = false;

	/**
	 * Whether this action decided the final stage (result slot).
	 *
	 * @var boolean
	 */
	private bool $completed = false;

	/**
	 * Whether Decidiq handled this command (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * The reason the engine refused, when it did (result slot).
	 *
	 * @var string
	 */
	private string $refusal = '';

	/**
	 * Construct the command event.
	 *
	 * @param string $sourceApp App id of the producer
	 * @param string $subject The subject travelling the route
	 * @param string $actor Nextcloud UID of the person acting
	 * @param string $action The verb: approved, returned, advised, skipped or endorsed
	 * @param integer $returnToStep The step a `returned` action sends the route back to
	 * @param string $comment Comment or reason
	 * @param string $advice Advisory text, for advisory stages
	 * @param string $actorType Whether the actor acted directly or as a delegate
	 * @param string $onBehalfOf The principal, when acting as a delegate
	 * @param string $mandate Mandate reference for a delegate action
	 * @param string $correlationId Correlation id echoed on the conclusion event
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) This parameter list is a
	 * PUBLISHED CROSS-APP CONTRACT constructed POSITIONALLY through a
	 * class-string by consumers that must stay installable without Decidiq.
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $subject,
		private readonly string $actor,
		private readonly string $action,
		private readonly int $returnToStep = 0,
		private readonly string $comment = '',
		private readonly string $advice = '',
		private readonly string $actorType = 'user',
		private readonly string $onBehalfOf = '',
		private readonly string $mandate = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the producing app id.
	 *
	 * @return string The app id
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;

	}//end getSourceApp()

	/**
	 * Get the subject travelling the route.
	 *
	 * @return string The subject id
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getSubject(): string {
		return $this->subject;

	}//end getSubject()

	/**
	 * Get the acting Nextcloud UID.
	 *
	 * @return string The actor
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getActor(): string {
		return $this->actor;

	}//end getActor()

	/**
	 * Get the action verb.
	 *
	 * @return string The verb
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getAction(): string {
		return $this->action;

	}//end getAction()

	/**
	 * Get the step a return sends the route back to.
	 *
	 * @return integer The step number, or 0
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getReturnToStep(): int {
		return $this->returnToStep;

	}//end getReturnToStep()

	/**
	 * Get the comment.
	 *
	 * @return string The comment
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getComment(): string {
		return $this->comment;

	}//end getComment()

	/**
	 * Get the advisory text.
	 *
	 * @return string The advice
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getAdvice(): string {
		return $this->advice;

	}//end getAdvice()

	/**
	 * Get whether the actor acted directly or as a delegate.
	 *
	 * @return string The actor type
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getActorType(): string {
		return $this->actorType;

	}//end getActorType()

	/**
	 * Get the principal, when acting as a delegate.
	 *
	 * @return string The principal's uid
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getOnBehalfOf(): string {
		return $this->onBehalfOf;

	}//end getOnBehalfOf()

	/**
	 * Get the mandate reference.
	 *
	 * @return string The mandate
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getMandate(): string {
		return $this->mandate;

	}//end getMandate()

	/**
	 * Get the correlation id.
	 *
	 * @return string The correlation id
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;

	}//end getCorrelationId()

	/**
	 * Whether the action was recorded.
	 *
	 * @return boolean The recorded flag
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function isRecorded(): bool {
		return $this->recorded;

	}//end isRecorded()

	/**
	 * Record that the action was stored (result slot).
	 *
	 * @param boolean $recorded True when an action row was written
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function setRecorded(bool $recorded): void {
		$this->recorded = $recorded;

	}//end setRecorded()

	/**
	 * Whether this action decided the final stage.
	 *
	 * @return boolean The completed flag
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function isCompleted(): bool {
		return $this->completed;

	}//end isCompleted()

	/**
	 * Record that the route completed (result slot).
	 *
	 * @param boolean $completed True when no stage is left active
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function setCompleted(bool $completed): void {
		$this->completed = $completed;

	}//end setCompleted()

	/**
	 * Whether Decidiq handled the command.
	 *
	 * @return boolean The handled flag
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function isHandled(): bool {
		return $this->handled;

	}//end isHandled()

	/**
	 * Record that Decidiq handled the command (result slot).
	 *
	 * @param boolean $handled True when the command was applied
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;

	}//end setHandled()

	/**
	 * The engine's reason for refusing, when it refused.
	 *
	 * The producer needs the REASON, not just the refusal: "you are not the
	 * named actor" and "there is nothing to act on" call for different handling,
	 * and a bare false collapses them.
	 *
	 * @return string The refusal reason, or an empty string
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getRefusal(): string {
		return $this->refusal;

	}//end getRefusal()

	/**
	 * Record the engine's refusal reason (result slot).
	 *
	 * @param string $refusal The reason
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function setRefusal(string $refusal): void {
		$this->refusal = $refusal;

	}//end setRefusal()

}//end class
