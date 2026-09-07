# approval-route-events Specification

## Purpose

An in-process command seam over the existing approval-route engine, so another
installed fleet app can hold a sign-off route, travel a subject down it, and
record actions against it. Per ADR-041 a cross-app command travels as a typed
event, not as REST.

## Requirements

### Requirement: REQ-ARE-001 A route carries where it came from

`ApprovalRoute` SHALL carry `sourceApp` and `externalReference`, additively.
Together they identify the originating record in the producing app, and they
are the key the seam resolves on so a repeated command updates one route rather
than creating a second.

#### Scenario: The pair is additive

- **GIVEN** the register fragment
- **WHEN** the register imports
- **THEN** `ApprovalRoute` has `sourceApp` and `externalReference`
- **AND** its `required` list is unchanged, so every stored route stays valid
- **AND** the seeded `collegeadvies-standaard` route leaves both empty

### Requirement: REQ-ARE-002 Holding a route is a typed command

Decidiq SHALL register a listener for `ApprovalRouteRequestedEvent` that
upserts the template and writes `routeId`, `created` and `handled` onto the
dispatched instance.

#### Scenario: A command holds a route and answers with its id

- **GIVEN** no route for `(dossiq, pr-1)`
- **WHEN** a producer dispatches `ApprovalRouteRequestedEvent` for it
- **THEN** an `ApprovalRoute` exists with the commanded steps
- **AND** the event reports `handled = true`, `created = true` and a non-empty `routeId`

#### Scenario: A repeated command updates rather than duplicating

- **GIVEN** a handled command for `(dossiq, pr-1)`
- **WHEN** the identical command is dispatched again
- **THEN** exactly one `ApprovalRoute` exists for that pair
- **AND** the event reports `created = false` with the same id

#### Scenario: A route with no steps is refused

- **WHEN** a command carries an empty `steps[]`
- **THEN** it is refused, nothing is written, and `handled` is false

#### Scenario: A failure leaves the event unhandled and throws nothing

- **GIVEN** the store rejects the write
- **WHEN** the event is dispatched
- **THEN** `handled` is false and no exception escapes the dispatcher

### Requirement: REQ-ARE-003 A command may also start a subject travelling

When `ApprovalRouteRequestedEvent` names a subject, the listener SHALL
instantiate the route against it through the EXISTING
`ApprovalRouteService::instantiate()` and report `stageCount`.

#### Scenario: Naming a subject materialises its stages

- **GIVEN** a command naming subject `voorstel-1` and a three-step route
- **WHEN** it is handled
- **THEN** three `DecisionStage` rows exist for that subject, the first `active`
- **AND** the event reports `stageCount = 3`

#### Scenario: Instantiating twice does not double the stages

- **GIVEN** a subject already travelling the route
- **WHEN** the identical command is dispatched again
- **THEN** the subject still has exactly three stages

#### Scenario: An edited template does not rewrite a sign-off in flight

- **GIVEN** a subject travelling a three-step route, with step 1 decided
- **WHEN** a command updates the template to four steps WITHOUT naming that subject
- **THEN** that subject still has three stages, and step 1 keeps its outcome

### Requirement: REQ-ARE-004 Recording an action is a typed command

Decidiq SHALL register a listener for `ApprovalActionRequestedEvent` that
records the action through the EXISTING `ApprovalRouteService::record()` and
reports `recorded`, `completed` and `handled`.

The command service SHALL NOT re-implement which stage is active, what a return
does, or which actors may act. Those rules live in `ApprovalRouteService` and
the seam delegates to them.

#### Scenario: An approval advances the route

- **GIVEN** a subject on step 1 of three
- **WHEN** an approval by the named actor is dispatched
- **THEN** step 1 is `decided`, step 2 is `active`
- **AND** the event reports `handled = true`, `completed = false`

#### Scenario: The last approval completes the route

- **GIVEN** a subject on the final step
- **WHEN** an approval is dispatched
- **THEN** no stage is left active
- **AND** the event reports `completed = true`

#### Scenario: An actor the stage does not name is refused

- **GIVEN** a stage assigned to another person
- **WHEN** a different actor dispatches an action
- **THEN** it is refused, no action row is written, no stage changes, and `handled` is false

#### Scenario: A return sends the route back through the same engine

- **GIVEN** a subject on step 3
- **WHEN** a `returned` action naming step 2 is dispatched
- **THEN** step 2 is `active` with its outcome cleared and step 3 is `pending`

### Requirement: REQ-ARE-005 The seam and the service cannot diverge

Recording an action through the seam SHALL produce the same stored state as
recording the identical action through `ApprovalRouteService::record()`
directly.

#### Scenario: Both paths agree

- **GIVEN** two identical subjects each travelling the same route
- **WHEN** one is advanced through the seam and the other through the service
- **THEN** their stage rows are identical apart from their ids

### Requirement: REQ-ARE-006 A completed route announces itself

When an action decides the final stage, the listener SHALL dispatch
`ApprovalRouteConcludedEvent` carrying the request's `correlationId`, the
subject, and the final outcome.

#### Scenario: The conclusion echoes the correlation

- **GIVEN** a final approval carrying `correlationId = abc`
- **WHEN** it is handled
- **THEN** an `ApprovalRouteConcludedEvent` is dispatched with `correlationId = abc`

#### Scenario: A non-final action announces nothing

- **GIVEN** an approval on step 1 of three
- **WHEN** it is handled
- **THEN** no `ApprovalRouteConcludedEvent` is dispatched
