<?php

/**
 * Decidiq GovernanceBodyRequestedEvent
 *
 * Public cross-app command event a consumer fleet app dispatches to ask Decidiq
 * to raise a GovernanceBody with its roster. Per ADR-041 a cross-app COMMAND
 * travels as a typed event; the REST surface on ApiController is for external
 * callers and refuses an in-process call, which has no session to authenticate
 * with. Dispatched via Nextcloud's IEventDispatcher and handled synchronously by
 * GovernanceBodyRequestedListener, so the dispatching producer reads the
 * resolved id back off the same instance.
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
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app command event: a consumer app asks Decidiq to raise a GovernanceBody.
 *
 * All request fields are immutable (constructor-injected getters). Nextcloud
 * typed dispatch is synchronous, so the result slots (governanceBodyId, created,
 * handled) are written by Decidiq's listener and read by the producer right
 * after dispatch — the same request/response-over-the-bus pattern
 * DecisionRequestedEvent uses.
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */
class GovernanceBodyRequestedEvent extends Event {

	/**
	 * The id of the GovernanceBody Decidiq created or matched (result slot).
	 *
	 * @var string|null
	 */
	private ?string $governanceBodyId = null;

	/**
	 * Whether the body was newly created (false when an existing one matched).
	 *
	 * @var boolean
	 */
	private bool $created = false;

	/**
	 * Whether Decidiq's listener handled this command (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Construct the command event.
	 *
	 * @param string $sourceApp App id of the producer (e.g. dossiq)
	 * @param string $externalReference The producer's own id for the originating record
	 * @param string $name Body name
	 * @param string $bodyType GovernanceBody bodyType (e.g. advisory-body)
	 * @param string $domain Governance domain preset
	 * @param boolean $active Whether the body may be assigned new work. Stated, never defaulted
	 * @param array<string, mixed> $attributes Further body fields (quorum, jurisdiction, statutoryBasis, termStart, termEnd, parentBody)
	 * @param array<int, array<string, mixed>> $members Roster entries of {uid, role, external, label}
	 * @param string $actorId Nextcloud UID on whose behalf the command runs
	 * @param string $correlationId Correlation id echoed on GovernanceBodyCreatedEvent
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) This parameter list is a
	 * PUBLISHED CROSS-APP CONTRACT, not an internal signature. A consumer app
	 * constructs the event POSITIONALLY through a class-string so it stays
	 * installable without Decidiq — the same constraint DecisionRequestedEvent
	 * documents. Collapsing these into an array would move the contract from the
	 * signature into an undocumented key set.
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $externalReference,
		private readonly string $name,
		private readonly string $bodyType,
		private readonly string $domain,
		private readonly bool $active,
		private readonly array $attributes = [],
		private readonly array $members = [],
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
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;

	}//end getSourceApp()

	/**
	 * Get the producer's own reference for the originating record.
	 *
	 * @return string The external reference
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getExternalReference(): string {
		return $this->externalReference;

	}//end getExternalReference()

	/**
	 * Get the body name.
	 *
	 * @return string The name
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getName(): string {
		return $this->name;

	}//end getName()

	/**
	 * Get the body type.
	 *
	 * @return string The bodyType
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getBodyType(): string {
		return $this->bodyType;

	}//end getBodyType()

	/**
	 * Get the governance domain.
	 *
	 * @return string The domain
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getDomain(): string {
		return $this->domain;

	}//end getDomain()

	/**
	 * Whether the body may be assigned new work.
	 *
	 * @return boolean The active flag
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function isActive(): bool {
		return $this->active;

	}//end isActive()

	/**
	 * Get the further body fields.
	 *
	 * @return array<string, mixed> The attribute map
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getAttributes(): array {
		return $this->attributes;

	}//end getAttributes()

	/**
	 * Get the roster entries.
	 *
	 * @return array<int, array<string, mixed>> The members
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getMembers(): array {
		return $this->members;

	}//end getMembers()

	/**
	 * Get the acting Nextcloud UID.
	 *
	 * @return string The actor id
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getActorId(): string {
		return $this->actorId;

	}//end getActorId()

	/**
	 * Get the correlation id.
	 *
	 * @return string The correlation id
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;

	}//end getCorrelationId()

	/**
	 * Get the resolved GovernanceBody id.
	 *
	 * @return string The id, or an empty string when unhandled
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getGovernanceBodyId(): string {
		return ($this->governanceBodyId ?? '');

	}//end getGovernanceBodyId()

	/**
	 * Record the resolved GovernanceBody id (result slot).
	 *
	 * @param string $governanceBodyId The id Decidiq created or matched
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function setGovernanceBodyId(string $governanceBodyId): void {
		$this->governanceBodyId = $governanceBodyId;

	}//end setGovernanceBodyId()

	/**
	 * Whether the body was newly created rather than matched.
	 *
	 * @return boolean The created flag
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function isCreated(): bool {
		return $this->created;

	}//end isCreated()

	/**
	 * Record whether the body was newly created (result slot).
	 *
	 * @param boolean $created True when this command minted the body
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function setCreated(bool $created): void {
		$this->created = $created;

	}//end setCreated()

	/**
	 * Whether Decidiq handled the command.
	 *
	 * @return boolean The handled flag
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
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
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;

	}//end setHandled()

}//end class
