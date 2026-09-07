<?php

/**
 * Decidiq cross-app event registrar.
 *
 * Wires the typed events other fleet apps dispatch to command Decidiq, and the
 * listeners that answer them. Split out of DomainServiceRegistrar because the
 * set grew: what was one event/listener pair for decisions is now five, one per
 * thing another app can ask Decidiq to do or read back, and each pair costs
 * that class two more imports — enough to push it past the coupling threshold, which is how
 * this extraction was found rather than guessed.
 *
 * Per ADR-041 a cross-app COMMAND travels as a typed event. Decidiq's REST
 * controllers are the door for EXTERNAL callers; they refuse a request with no
 * signed-in user, which is exactly the state an in-process migration runs in.
 *
 * @category AppInfo
 * @package  OCA\Decidiq\AppInfo\Registrar
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

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\Decidiq\Event\ApprovalActionRequestedEvent;
use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCA\Decidiq\Event\DecisionStateRequestedEvent;
use OCA\Decidiq\Event\GovernanceBodyRequestedEvent;
use OCA\Decidiq\Listener\ApprovalActionRequestedListener;
use OCA\Decidiq\Listener\ApprovalRouteRequestedListener;
use OCA\Decidiq\Listener\ApprovalTaskDecisionListener;
use OCA\Decidiq\Listener\DecisionRequestedListener;
use OCA\Decidiq\Listener\DecisionStateRequestedListener;
use OCA\Decidiq\Listener\GovernanceBodyRequestedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers every inbound cross-app command listener.
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class CrossAppEventRegistrar {

	/**
	 * The command contract, as event class => listener class.
	 *
	 * A map rather than four call sites, so adding a command is one line and
	 * the whole inbound surface reads as one list.
	 *
	 * @var array<class-string, class-string>
	 */
	private const COMMANDS = [
		// Raise a governance Decision for a consumer's object, and conclude it
		// back through DecisionConcludedEvent.
		DecisionRequestedEvent::class => DecisionRequestedListener::class,

		// Report what became of a Decision the consumer already raised. The
		// READ half of the pair above: the conclusion is ANNOUNCED by
		// DecisionConcludedEvent, and this is what a consumer consults when
		// that announcement never reached it. It reuses the outcome envelope
		// and the outcome-read guard rather than deriving either again.
		DecisionStateRequestedEvent::class => DecisionStateRequestedListener::class,

		// Hold a governance body — a committee, a board — with its roster.
		GovernanceBodyRequestedEvent::class => GovernanceBodyRequestedListener::class,

		// Hold a sign-off route, and travel a subject down it. Both delegate to
		// the EXISTING ApprovalRouteService: these add a door, not a second
		// engine, so the event path and the REST path cannot answer differently
		// for the same action.
		ApprovalRouteRequestedEvent::class => ApprovalRouteRequestedListener::class,
		ApprovalActionRequestedEvent::class => ApprovalActionRequestedListener::class,
	];

	/**
	 * Register every command listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::COMMANDS as $event => $listener) {
			$context->registerEventListener(event: $event, listener: $listener);
		}

		$this->registerTaskDecisionListener(context: $context);

	}//end register()

	/**
	 * Register the listener that turns an answered approval-stage task into an
	 * engine action.
	 *
	 * The event class is OpenRegister's and only newer releases ship it, so it
	 * is named as an FQN STRING and registered only when it exists — the same
	 * both-worlds posture the fleet's cross-app listeners use. `::class` on an
	 * absent class would not fail here (it is compile-time), but registering a
	 * listener for an event nothing can dispatch is a standing lie in the
	 * registration table.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function registerTaskDecisionListener(IRegistrationContext $context): void {
		$event = 'OCA\OpenRegister\Event\TaskTerminalEvent';
		if (class_exists('\\' . $event) === false) {
			return;
		}

		$context->registerEventListener(event: $event, listener: ApprovalTaskDecisionListener::class);

	}//end registerTaskDecisionListener()

}//end class
