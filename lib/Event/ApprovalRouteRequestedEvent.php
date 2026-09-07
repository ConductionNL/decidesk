<?php

/**
 * Decidiq ApprovalRouteRequestedEvent
 *
 * Public cross-app command event: a consumer app asks Decidiq to hold a
 * sign-off route, and optionally to start one subject travelling it. Per
 * ADR-041 a cross-app COMMAND travels as a typed event;
 * ApprovalRouteController is the door for EXTERNAL callers and refuses an
 * in-process call, which has no session to authenticate with.
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
 * A consumer app asks Decidiq to hold an approval route.
 *
 * All request fields are immutable. Nextcloud typed dispatch is synchronous, so
 * the result slots are written by Decidiq's listener and read by the producer
 * right after dispatch — the same shape GovernanceBodyRequestedEvent uses.
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class ApprovalRouteRequestedEvent extends Event {

	/**
	 * The id of the route Decidiq held or matched (result slot).
	 *
	 * @var string|null
	 */
	private ?string $routeId = null;

	/**
	 * Whether the route was newly created (result slot).
	 *
	 * @var boolean
	 */
	private bool $created = false;

	/**
	 * How many stages the named subject was given (result slot).
	 *
	 * @var integer
	 */
	private int $stageCount = 0;

	/**
	 * Whether Decidiq handled this command (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Construct the command event.
	 *
	 * @param string $sourceApp App id of the producer (e.g. dossiq)
	 * @param string $externalReference The producer's own id for the route
	 * @param string $name Admin-facing route name
	 * @param array<int, array<string, mixed>> $steps Ordered steps of {order, stageType, actorType, actor, mandatory, label}
	 * @param string $subjectType What kind of thing travels this route (e.g. collegeadvies)
	 * @param string $description When this route applies
	 * @param boolean $isDefault Whether it is the default for its subjectType
	 * @param string $subject Optional subject id to start travelling the route now
	 * @param string $subjectSchema Schema slug of that subject
	 * @param string $actorId Nextcloud UID on whose behalf the command runs
	 * @param string $correlationId Correlation id echoed on the conclusion event
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) This parameter list is a
	 * PUBLISHED CROSS-APP CONTRACT, not an internal signature. A consumer app
	 * constructs the event POSITIONALLY through a class-string so it stays
	 * installable without Decidiq. Collapsing these into an array would move the
	 * contract out of the signature and into an undocumented key set.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$isDefault` is a FIELD of
	 * ApprovalRoute, carried across the boundary unchanged, not a flag that
	 * selects between two behaviours of this constructor. The rule's usual
	 * remedy — split the method in two — would mean two event classes that
	 * differ only in one stored boolean.
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $externalReference,
		private readonly string $name,
		private readonly array $steps,
		private readonly string $subjectType = '',
		private readonly string $description = '',
		private readonly bool $isDefault = false,
		private readonly string $subject = '',
		private readonly string $subjectSchema = '',
		private readonly string $actorId = '',
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
	 * Get the producer's own reference.
	 *
	 * @return string The external reference
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getExternalReference(): string {
		return $this->externalReference;

	}//end getExternalReference()

	/**
	 * Get the route name.
	 *
	 * @return string The name
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getName(): string {
		return $this->name;

	}//end getName()

	/**
	 * Get the ordered steps.
	 *
	 * @return array<int, array<string, mixed>> The steps
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getSteps(): array {
		return $this->steps;

	}//end getSteps()

	/**
	 * Get what kind of thing travels this route.
	 *
	 * @return string The subject type
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getSubjectType(): string {
		return $this->subjectType;

	}//end getSubjectType()

	/**
	 * Get the route description.
	 *
	 * @return string The description
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getDescription(): string {
		return $this->description;

	}//end getDescription()

	/**
	 * Whether this is the default route for its subject type.
	 *
	 * @return boolean The default flag
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function isDefault(): bool {
		return $this->isDefault;

	}//end isDefault()

	/**
	 * Get the subject to start travelling the route, if any.
	 *
	 * @return string The subject id, or an empty string
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getSubject(): string {
		return $this->subject;

	}//end getSubject()

	/**
	 * Get the subject's schema slug.
	 *
	 * @return string The schema slug
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getSubjectSchema(): string {
		return $this->subjectSchema;

	}//end getSubjectSchema()

	/**
	 * Get the acting Nextcloud UID.
	 *
	 * @return string The actor id
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getActorId(): string {
		return $this->actorId;

	}//end getActorId()

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
	 * Get the resolved route id.
	 *
	 * @return string The id, or an empty string when unhandled
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getRouteId(): string {
		return ($this->routeId ?? '');

	}//end getRouteId()

	/**
	 * Record the resolved route id (result slot).
	 *
	 * @param string $routeId The id Decidiq held or matched
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function setRouteId(string $routeId): void {
		$this->routeId = $routeId;

	}//end setRouteId()

	/**
	 * Whether the route was newly created rather than matched.
	 *
	 * @return boolean The created flag
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function isCreated(): bool {
		return $this->created;

	}//end isCreated()

	/**
	 * Record whether the route was newly created (result slot).
	 *
	 * @param boolean $created True when this command minted the route
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function setCreated(bool $created): void {
		$this->created = $created;

	}//end setCreated()

	/**
	 * How many stages the named subject holds.
	 *
	 * @return integer The stage count
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getStageCount(): int {
		return $this->stageCount;

	}//end getStageCount()

	/**
	 * Record the subject's stage count (result slot).
	 *
	 * @param integer $stageCount The number of stages
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function setStageCount(int $stageCount): void {
		$this->stageCount = $stageCount;

	}//end setStageCount()

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

}//end class
