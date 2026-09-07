# decidesk-decision-events Specification (delta)

## ADDED Requirements

### Requirement: REQ-DDE-005 — Public DecisionStateRequestedEvent contract class

The system SHALL provide an autoloaded public event class
`OCA\Decidiq\Event\DecisionStateRequestedEvent` extending `OCP\EventDispatcher\Event`, available
whenever decidiq is installed, that a consumer app dispatches to ask decidiq what became of a
Decision it already raised. The event SHALL expose immutable getters for `sourceApp`, `decisionId`
and `actorId`, all supplied at construction. Because Nextcloud typed dispatch is synchronous, the
event SHALL carry result slots — `setHandled(bool)` / `isHandled(): bool`, `setPermitted(bool)` /
`isPermitted(): bool`, `setFound(bool)` / `isFound(): bool` and `setEnvelope(array)` /
`getEnvelope(): ?array`, plus a derived `getStatus(): ?string` reading the envelope's status — that
decidiq's listener writes so the dispatching producer can read the answer back off the same
instance. The request getters SHALL NOT be mutable.

The event SHALL NOT be a second delivery mechanism for a conclusion: `DecisionConcludedEvent`
(REQ-DDE-002 / REQ-DDE-004) remains how a concluded Decision reaches a consumer, and this event
exists for the case where that announcement was missed.

#### Scenario: A consumer reads back the decision it raised

@e2e exclude backend cross-app event contract — synchronous DecisionStateRequestedEvent dispatch + envelope read-back is verified by PHPUnit; no decidiq UI flow exercises it
- GIVEN a consumer app holds the `decisionId` decidiq returned when it raised a Decision
- WHEN it constructs a `DecisionStateRequestedEvent` with that id and the identity that raised it,
  and dispatches it via `IEventDispatcher`
- THEN after dispatch `isHandled()` is true, `isPermitted()` is true, `isFound()` is true and
  `getEnvelope()` holds the outcome envelope

#### Scenario: Three different negative answers are distinguishable

@e2e exclude backend value-object contract — the handled/permitted/found slot combinations are verified by PHPUnit; not a UI flow
- GIVEN a consumer dispatches a state read
- WHEN decidiq could not resolve the Decision at all, when the Decision does not exist, and when the
  caller may not read it
- THEN the three answers are respectively `handled=false`; `handled=true, permitted=true,
  found=false`; and `handled=true, permitted=false`, so a consumer can tell "ask me again" from
  "stop waiting" from "you may not see this"

---

### Requirement: REQ-DDE-006 — Listener answers a state read from the existing envelope and the existing guard

The system SHALL register a listener `OCA\Decidiq\Listener\DecisionStateRequestedListener`
(implementing `OCP\EventDispatcher\IEventListener`) bound to `DecisionStateRequestedEvent` in
`CrossAppEventRegistrar::COMMANDS`. On handling, the listener SHALL derive the reported state by
calling `DecisionIntegrationService::getOutcomeEnvelope()` — it SHALL NOT derive a status of its own
— and SHALL authorize the read through `DecisionIntegrationAuthorizationGuard` (REQ-DCDH-101),
SHALL NOT restate that rule. No exception SHALL escape into the dispatcher.

The listener SHALL leave the event UNHANDLED when the read could not be resolved, and SHALL mark it
handled in every case where it produced an answer — including a refusal and a miss. A Decision that
does not exist SHALL be reported as `found=false` with `permitted=true`, mirroring the endpoint's
choice to answer 404 rather than turn a 403 into an existence oracle.

#### Scenario: The reported status is the announced status

@e2e exclude backend derivation-reuse contract — verified by PHPUnit over the real service; not a UI flow
- GIVEN a delegated Decision has concluded with `lifecycle=decided` and `outcome=adopted`
- WHEN a consumer reads its state back through the seam
- THEN the reported status is `approved` — the same value `DecisionConcludedEvent` carried — and the
  envelope is the same array `getOutcomeEnvelope()` builds

#### Scenario: A withdrawn decision is reported as withdrawn, not as rejected

@e2e exclude backend derivation contract — verified by PHPUnit; not a UI flow
- GIVEN a delegated Decision was withdrawn
- WHEN a consumer reads its state back
- THEN the reported status is `withdrawn`, so the consumer can refuse to proceed rather than treat
  it as a decision against the thing

#### Scenario: An unreachable store is not reported as a refusal

@e2e exclude backend failure-mode contract — verified by PHPUnit; not a UI flow
- GIVEN OpenRegister cannot be resolved when a state read arrives
- WHEN the listener handles it
- THEN the event is left unhandled, so the consumer waits and retries rather than failing its run on
  an authorization error it never had

---

### Requirement: REQ-DDE-007 — A state read is scoped to a named actor and never elevated

The system SHALL authorize a `DecisionStateRequestedEvent` AS the uid the event names. An event
carrying an empty `actorId`, or an empty `decisionId`, SHALL be refused — marked handled with
`permitted=false` — and SHALL NOT be treated as a system, anonymous or administrator caller. There
SHALL be no administrator bypass on this path.

`DecisionIntegrationAuthorizationGuard` SHALL expose `resolveOutcomeReadAccess()` reporting
`allowed`, `denied` or `unresolved`, and `isAuthorizedToReadOutcome()` SHALL delegate to it so the
HTTP path and the event path apply one rule. The boolean's collapse of `unresolved` onto `false`
(fail closed) SHALL be unchanged.

#### Scenario: A nameless caller is refused rather than elevated

@e2e exclude backend authorization contract — verified by PHPUnit; not a UI flow
- GIVEN a `DecisionStateRequestedEvent` is dispatched with an empty `actorId`
- WHEN the listener handles it
- THEN the event is marked handled with `permitted=false` and carries no envelope

#### Scenario: A caller who did not raise the decision learns nothing about it

@e2e exclude backend authorization contract — verified by PHPUnit; not a UI flow
- GIVEN an internal (unpublished) Decision owned by another identity
- WHEN a different actor reads its state through the seam
- THEN `permitted` is false, `found` is false, and neither the envelope nor the status is reported
