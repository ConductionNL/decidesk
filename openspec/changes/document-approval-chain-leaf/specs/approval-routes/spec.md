# approval-routes Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [approval-routes](../../../approval-routes/) (the engine)
- [approval-route-events](../../../approval-route-events/) (the seam)
- [document-approval-chain-leaf](../../) (this delta)

## Purpose

Extends the approval-route engine with an ad-hoc route from named users, a
deadline split over the steps, and a render-surface leaf (ADR-066) so a
sibling app shows and works a route on a document without an engine of its
own. Requested by the dossiq competitor analysis, finding B23 and triage #8.

## ADDED Requirements

### Requirement: REQ-AR-008 A route can be held from named users

`ApprovalRouteService::holdFor(subject, actors, deadline, kind)` SHALL build
a route with `origin: adhoc` and one stage per actor, in the given order,
without a stored `ApprovalRoute` template, and SHALL then follow the
instantiate path of REQ-AR-004. `HoldApprovalRouteRequestedEvent` SHALL
accept an optional ordered `actors[]` with the same effect.

#### Scenario: A clerk names three reviewers

- GIVEN a document object and three user ids
- WHEN the clerk holds a review route with those users in order
- THEN a route with three stages exists, stage one assigned to the first user
- @e2e exclude service path; covered by PHPUnit on `ApprovalRouteService::holdFor()`

### Requirement: REQ-AR-009 One deadline splits over the steps

`DecisionStage` SHALL carry `dueAt`. When a route is held with a `deadline`,
the service SHALL divide the working days until the deadline evenly over the
stages, the last stage on the deadline. The holder MAY edit a stage's
`dueAt`. A stage past `dueAt` without an action SHALL render as overdue; this
is a computed view, not a lifecycle state.

#### Scenario: Nine working days over three steps

- GIVEN a deadline nine working days from now and three stages
- WHEN the route is held
- THEN the stages carry `dueAt` on working days three, six and nine
- @e2e exclude date arithmetic; covered by PHPUnit with a fixed clock

### Requirement: REQ-AR-010 The route is a render-surface leaf

decidiq SHALL register a leaf `decidiq-approval-chain` of kind
`render-surface` on both halves under one id, with a `tab` (the timeline
with every action and reason) and a `widget` (the current step, its due date,
and for the current actor the approve and reject actions, reject requiring a
reason). Start, approve and reject SHALL run through decidiq's own service
and controller. The leaf SHALL NOT invoke any action in the consuming app
(ADR-066 decision 2).

#### Scenario: A reviewer approves from the case's documents tab

- GIVEN dossiq places `decidiq-approval-chain` on a document and the user is the current actor
- WHEN the user approves in the widget
- THEN an `ApprovalAction` is recorded, the next stage becomes current and the widget shows the next actor
- e2e: `tests/e2e/approval-chain-leaf.spec.ts`

#### Scenario: A user who is not the current actor sees no buttons

- GIVEN a route whose current stage belongs to someone else
- WHEN the widget renders for this user
- THEN it shows the timeline and no approve or reject action
- e2e: `tests/e2e/approval-chain-leaf.spec.ts`

### Requirement: REQ-AR-011 The decisions leaf names its tab

The `decidesk-decisions` leaf SHALL declare a `tab` with a label and icon in
both halves, keeping `mount` and `unmount` as its render pair, so a sidebar
host shows decidiq's own label instead of a fallback.

#### Scenario: The sidebar shows the decidiq label

- GIVEN a consumer sidebar hosting `decidesk-decisions`
- WHEN the sidebar renders its tabs
- THEN the tab reads the label decidiq declared, not a fallback naming decidesk
- e2e: `tests/e2e/decisions-leaf-tab.spec.ts`
