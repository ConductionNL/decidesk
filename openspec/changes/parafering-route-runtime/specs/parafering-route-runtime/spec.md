# parafering-route-runtime Specification

**Status**: in progress
**Scope**: decidiq

## Purpose

The approval-route engine owns the parafering runtime dossiq retires: the
stage-typed vocabulary, mandated delegate signing, the return-to-sender
conclusion, parallel co-signing, a conclusion announced from every concluding
path, and the ask mirrored onto OpenRegister's task surface.

## ADDED Requirements

### Requirement: REQ-PRR-001 A completing verb fits its stage type

The engine SHALL refuse a completing verb the stage's type did not ask for:
`advisory` completes with `advised`, `endorsement` with `endorsed`,
`decisive` with `approved`. A `returned` action SHALL require a non-empty
comment and an `advised` action a non-empty advice text, refused BEFORE any
row is written.

#### Scenario: An approved on an advisory stage is refused

- **GIVEN** a route whose active stage is advisory
- **WHEN** `approved` is recorded against it
- **THEN** the action is refused and no approval-action row exists

`@e2e exclude` engine-internal refusal with no UI surface of its own; pinned by
mutation-checked unit tests (ParaferingRouteRuntimeTest), and the REST path
returns the engine's own message which existing controller tests cover.

#### Scenario: A reasonless return is refused before anything is written

- **GIVEN** an active stage
- **WHEN** `returned` is recorded with no comment
- **THEN** it is refused, no action row exists and every stage status is unchanged

`@e2e exclude` same engine-internal refusal; unit-pinned.

### Requirement: REQ-PRR-002 A mandated delegate may sign, and only a mandated delegate

When the actor is not the stage's person, the engine SHALL accept the action
only when `onBehalfOf` names exactly the stage's person AND a non-empty
mandate reference is presented. A mandate reference that resolves in the
local `bevoegdheidstoedeling` register SHALL additionally be `effective`,
within its validity window, and name the acting delegate — a resolvable but
wrong mandate refuses. A reference that resolves to nothing SHALL be
recorded verbatim as an external mandate.

This check gates a sign-off stage action, not a Decision lifecycle
transition, and therefore does not collide with REQ-DMR-006.

#### Scenario: A delegate with the principal's mandate signs

- **GIVEN** a stage assigned to alice and an actor bob presenting
  `onBehalfOf: alice` with a mandate reference this register does not hold
- **WHEN** the action is recorded
- **THEN** it is accepted and the row carries actor bob, onBehalfOf alice and
  the mandate verbatim

`@e2e exclude` cross-register mandate fixtures cannot be seeded from the e2e
account; the acceptance and each refusal below are unit-pinned and
mutation-checked (dropping the check turns three tests red).

#### Scenario: A withdrawn local mandate refuses the delegate

- **GIVEN** a local toedeling that is `withdrawn`, `lapsed` or out of window
- **WHEN** a delegate presents it
- **THEN** the action is refused

`@e2e exclude` unit-pinned (ParaferingRouteRuntimeTest, MandateDirectoryTest);
no e2e seed path writes toedeling rows.

#### Scenario: A delegate for the wrong principal is refused

- **GIVEN** a stage assigned to alice
- **WHEN** bob acts with `onBehalfOf: dave`, or with no mandate at all
- **THEN** the action is refused and nothing is recorded

`@e2e exclude` unit-pinned; the refusal message is the REST surface's 400 body
already covered by controller tests.

### Requirement: REQ-PRR-003 A return naming no step concludes the route to its sender

A `returned` action without a `returnToStep` SHALL conclude the route: the
addressed stage records outcome `returned`, every other active or pending
stage returns to `pending` with its outcome cleared, and NO stage remains
active. The approvers after the returning one are never asked. A
`returnToStep` of at least 1 SHALL keep the existing rewind semantics.

#### Scenario: A terugsturen asks nobody else

- **GIVEN** a three-stage route on its second stage
- **WHEN** the second approver returns it with a reason and no target step
- **THEN** no stage is active, the second stage's outcome is `returned`, the
  third stage is `pending` with no outcome, and a further action is refused

`@e2e exclude` unit-pinned and mutation-checked (a no-op terminal return turns
two tests red); the cross-app effect is asserted on the dossiq side.

### Requirement: REQ-PRR-004 Steps sharing an order sign side by side

Steps declaring the same `order` SHALL instantiate as stages sharing that
sequence, all active together. The group SHALL advance only when its last
live member completes, and each actor's action SHALL land on the stage that
names them. A stage's sequence SHALL be the step's own `order`, falling back
to its position only when no order is declared.

#### Scenario: One signature does not advance a co-signing group

- **GIVEN** two endorsement steps both declaring order 1
- **WHEN** the first co-signer endorses
- **THEN** the sibling stage stays active and the next sequence stays pending
- **AND** when the sibling endorses too, the next sequence activates

`@e2e exclude` unit-pinned (dropping the group hold turns a named test red).

#### Scenario: A route numbered 10 and 20 projects those numbers

- **GIVEN** a route whose steps declare order 10 and 20
- **WHEN** it is instantiated
- **THEN** the stages carry sequence 10 and 20

`@e2e exclude` unit-pinned; the numbering is exactly what dossiq's surfaces read
and dossiq's own suite asserts the display side.

### Requirement: REQ-PRR-005 A conclusion is announced from every concluding path

Whichever path decides a route's final stage — the cross-app action command,
the REST surface, or an answered projected task — the system SHALL announce
one `ApprovalRouteConcludedEvent` through a single announcer. The event SHALL
carry the subject, outcome, final actor, the route's provenance pair, the
subject schema, and the FULL chronological action record, so the producer can
keep who-signed-what-when as case data without reading this register back.
A route with no source app SHALL announce nothing.

#### Scenario: A REST-path conclusion reaches the producer

- **GIVEN** a route held for `dossiq` whose final stage is decided over
  `/api/approval-routes/actions`
- **WHEN** the action is recorded
- **THEN** an `ApprovalRouteConcludedEvent` is dispatched carrying
  `sourceApp: dossiq`, the external reference, and every recorded action in
  order

`@e2e exclude` a typed in-process event is invisible to a browser; the payload
is pinned by ApprovalRouteConclusionAnnouncerTest and consumed (and asserted
again) by dossiq's ParaferingConcludedListener suite.

#### Scenario: An internal route announces nothing

- **GIVEN** a route instantiated by this app itself
- **WHEN** its final stage is decided
- **THEN** no conclusion event is dispatched

`@e2e exclude` unit-pinned, same reason.

### Requirement: REQ-PRR-006 The ask rides the task surface, and only rides

The system SHALL mirror every active person-assigned stage onto an
OpenRegister flow-task marked `decidiq:approval-stage`, close that task when
the stage stops waiting, and turn a COMPLETED projected task back into an
engine action under the engine's own rules. The projection SHALL be best
effort: its absence, refusal or failure changes where an ask is seen, never
whether the route advances. A consumed, cancelled or terminated task SHALL
never echo into the engine.

#### Scenario: An answered inbox task is the same signature

- **GIVEN** an active decisive stage projected as a task
- **WHEN** its assignee completes the task
- **THEN** the engine records `approved` by that completer and the conclusion
  is announced if the route finished

`@e2e exclude` requires an OpenRegister release carrying the task surface inside
the e2e instance; the translation, the marker filter and the consumed-task
non-echo are unit-pinned (ApprovalTaskDecisionListenerTest).

#### Scenario: No task surface costs visibility, nothing else

- **GIVEN** an instance whose OpenRegister lacks the flow-task service
- **WHEN** routes are instantiated, travelled and concluded
- **THEN** every outcome is identical, and no task linkage is written

`@e2e exclude` unit-pinned (ApprovalStageTaskProjectorTest).
