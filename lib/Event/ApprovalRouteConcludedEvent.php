<?php

/**
 * Decidiq ApprovalRouteConcludedEvent
 *
 * Emitted when an approval action decides the FINAL stage of a route, so the
 * consuming app learns a sign-off finished without polling for it. Carries the
 * request's correlationId home, the same way DecisionConcludedEvent does.
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
 * A subject has reached the end of its approval route.
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class ApprovalRouteConcludedEvent extends Event {

	/**
	 * Construct the conclusion event.
	 *
	 * The trailing parameters are DEFAULTED because the ctor is a published
	 * positional cross-app contract: a producer built against the five-argument
	 * shape keeps working, and a consumer duck-types the getters it wants.
	 *
	 * @param string $subject The subject that finished travelling
	 * @param string $sourceApp App id of the producer the route was held for
	 * @param string $outcome The final stage's outcome
	 * @param string $actor Nextcloud UID of whoever decided the final stage
	 * @param string $correlationId Correlation id echoed from the request
	 * @param string $subjectSchema Schema slug the subject was instantiated under
	 * @param string $externalReference The producer's own id for the route travelled
	 * @param array<int, array<string, mixed>> $actions The sign-off record: every
	 *        ApprovalAction recorded against the subject, chronological. Carried
	 *        so the producer can keep who-signed-what-when — actor, onBehalfOf,
	 *        mandate, comment, advice — as case data without reading this app's
	 *        register back (ADR-022).
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function __construct(
		private readonly string $subject,
		private readonly string $sourceApp,
		private readonly string $outcome,
		private readonly string $actor = '',
		private readonly string $correlationId = '',
		private readonly string $subjectSchema = '',
		private readonly string $externalReference = '',
		private readonly array $actions = [],
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the subject that finished travelling.
	 *
	 * @return string The subject id
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getSubject(): string {
		return $this->subject;

	}//end getSubject()

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
	 * Get the final stage's outcome.
	 *
	 * @return string The outcome
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getOutcome(): string {
		return $this->outcome;

	}//end getOutcome()

	/**
	 * Get whoever decided the final stage.
	 *
	 * @return string The actor uid
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getActor(): string {
		return $this->actor;

	}//end getActor()

	/**
	 * Get the correlation id echoed from the request.
	 *
	 * @return string The correlation id
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;

	}//end getCorrelationId()

	/**
	 * Get the schema slug the subject was instantiated under.
	 *
	 * @return string The schema slug
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function getSubjectSchema(): string {
		return $this->subjectSchema;

	}//end getSubjectSchema()

	/**
	 * Get the producer's own id for the route travelled.
	 *
	 * @return string The external reference
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function getExternalReference(): string {
		return $this->externalReference;

	}//end getExternalReference()

	/**
	 * Get the sign-off record: every action recorded, chronological.
	 *
	 * @return array<int, array<string, mixed>> The actions
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function getActions(): array {
		return $this->actions;

	}//end getActions()

}//end class
