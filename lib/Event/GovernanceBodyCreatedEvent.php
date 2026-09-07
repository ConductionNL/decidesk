<?php

/**
 * Decidiq GovernanceBodyCreatedEvent
 *
 * Emitted after Decidiq has raised or matched a GovernanceBody in response to a
 * GovernanceBodyRequestedEvent. Carries the request's correlationId home so the
 * producing app can attach the resulting id to whatever it dispatched for,
 * without holding a reference to the request instance.
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
 * Conclusion event for a cross-app governance-body command.
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */
class GovernanceBodyCreatedEvent extends Event {

	/**
	 * Construct the conclusion event.
	 *
	 * @param string $governanceBodyId The id Decidiq created or matched
	 * @param string $sourceApp App id of the producer the command came from
	 * @param string $externalReference The producer's own id for the originating record
	 * @param boolean $created True when this command minted the body, false when one matched
	 * @param string $correlationId Correlation id echoed from the request
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function __construct(
		private readonly string $governanceBodyId,
		private readonly string $sourceApp,
		private readonly string $externalReference,
		private readonly bool $created,
		private readonly string $correlationId = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the resolved GovernanceBody id.
	 *
	 * @return string The id
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getGovernanceBodyId(): string {
		return $this->governanceBodyId;

	}//end getGovernanceBodyId()

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
	 * Get the producer's own reference.
	 *
	 * @return string The external reference
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getExternalReference(): string {
		return $this->externalReference;

	}//end getExternalReference()

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
	 * Get the correlation id echoed from the request.
	 *
	 * @return string The correlation id
	 *
	 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;

	}//end getCorrelationId()

}//end class
