# decision-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [process-templates-as-lifecycle-policies](../../) (this delta)

## Purpose

The Decision lifecycle keeps one transition map, the `x-openregister-lifecycle`
block OpenRegister runs, and gains one OpenRegister lifecycle guard that
enforces the body's policy on every write. The decidiq-local transition map
(`DecisionTransitionGuard`) goes. See design.md D1, D2, D4 and D5.

## MODIFIED Requirements

### Requirement: Decision State Machine

The system MUST enforce the decision lifecycle through OpenRegister: the
transition map is the `x-openregister-lifecycle` block on the `decision`
schema, and OpenRegister's `LifecycleValidationListener` applies it to every
update of a decision, whoever writes it. decidiq MUST NOT hold a transition
map of its own. The lifecycle MUST be stored in the `lifecycle` field on the
`Decision` schema and MUST include the states `draft`, `proposed`,
`deliberating`, `voting`, `decided`, `enacted`, `archived` and `withdrawn`.
Only declared transitions MUST be allowed; decidiq's decision endpoint MUST
reject an invalid transition with an error naming the allowed actions from the
current state, read from the annotation.

Every declared transition MUST name the decidiq lifecycle guard in `requires`.
The guard MUST implement OpenRegister's `LifecycleGuardInterface`, MUST NOT
mutate the object, and MUST refuse, on every write path:

- `decideWithoutVote` when the body's policy does not allow deciding without a
  vote;
- `openVoting` while the linked meeting's quorum is not met, when the policy
  requires quorum;
- an action whose policy lists `all_amendments_resolved` while an amendment of
  the decision is undecided;
- entering `enacted` unless `outcome` is `adopted`;
- a chair-only action by anyone but the resolved meeting chair, failing
  closed when no chair can be resolved (scope per design.md Q2; this text
  states the recommended answer).

The body's policy MUST be its template's transition policies when it has a
usable template, and otherwise the domain default, with a default-deny policy
for an unknown domain. A template that cannot be loaded MUST fall back to the
domain default, never to something looser. The guard's context reads (meeting,
body, template, amendments) MUST NOT depend on what the caller may read.

The `enact` transition MUST record the enacted date. Every transition made
through decidiq's endpoint MUST be appended to the hash-chained audit log with
actor and timestamp.

**Feature tier**: MVP
**Legal reference**: Awb 3:40-3:45 (formal decision requirements), Gemeentewet 56 (council decision procedures)

#### Scenario: Transition a decision from draft to proposed

- GIVEN a decision in `draft` status with all required fields completed
- WHEN the decision owner triggers the "propose" transition
- THEN the status MUST change to `proposed`
- AND the transition MUST be recorded in the audit trail with timestamp and actor
- AND notifications MUST be sent to all members of the governing body

#### Scenario: Reject an invalid state transition

- GIVEN a decision in `draft` status
- WHEN a user attempts to transition directly to `decided`
- THEN the system MUST reject the transition with an error indicating the allowed transitions from `draft`
- AND the decision status MUST remain `draft`

#### Scenario: Transition a decision to enacted after approval

- GIVEN a decision in `decided` status with a positive voting outcome
- WHEN the decision owner triggers the "enact" transition
- THEN the status MUST change to `enacted`
- AND the system MUST generate a resolution record (see resolution-minutes spec)
- AND the enacted date MUST be recorded

#### Scenario: Available transitions are exposed for the current state

- GIVEN a decision in any lifecycle state
- WHEN the available transitions are requested for the decision
- THEN the system MUST return the current lifecycle state and exactly the actions the annotation declares from it, minus those the body's policy forbids, each marked chair-only where the policy says so

#### Scenario: A policy refusal holds on OpenRegister's object API

- GIVEN a governance body whose template does not allow deciding without a vote
- AND a decision of that body in `deliberating`, owned by another account
- WHEN a non-superuser member of `decidiq-administrators` writes `lifecycle: decided` straight to OpenRegister's object API
- THEN OpenRegister MUST refuse the write with `errors.code` `lifecycle-guard-denied`
- AND the stored lifecycle MUST remain `deliberating`
- e2e: `tests/e2e/workflows/decision-lifecycle-policy.spec.ts`

#### Scenario: An allowed transition passes on OpenRegister's object API

- GIVEN a governance body whose template does not require quorum
- AND a decision of that body in `deliberating`, owned by another account
- WHEN a non-superuser member of `decidiq-administrators` writes `lifecycle: voting` straight to OpenRegister's object API
- THEN the write MUST succeed and the stored lifecycle MUST be `voting`
- e2e: `tests/e2e/workflows/decision-lifecycle-policy.spec.ts`

#### Scenario: Quorum gate blocks opening the vote

@e2e exclude guard contract; covered by PHPUnit on the guard with a met and an unmet quorum, and the object-API path is proven by the policy refusal scenario above
- GIVEN a decision in `deliberating` status linked to a meeting whose quorum is not met, in a body whose policy requires quorum
- WHEN anyone moves it to `voting`, through decidiq or through OpenRegister's object API
- THEN the write MUST be refused with a quorum message
- AND the decision status MUST remain `deliberating`

#### Scenario: Chair-only transition is enforced per domain

@e2e exclude authorization contract; covered by PHPUnit (chair, non-chair, empty uid, unresolvable chair); the object-API path is proven by the policy refusal scenario above
- GIVEN a decision in a body whose policy marks the action chair-only
- WHEN an authenticated user who is not the resolved meeting chair takes that action
- THEN the write MUST be refused
- AND when no chair can be resolved at all the write MUST also be refused (fail closed)

### Requirement: Declarative decision lifecycle

The decision lifecycle SHALL be declared as an `x-openregister-lifecycle`
block on the `decision` schema in `lib/Settings/decidesk_register.json`
(ADR-031, declarative, NOT a Service-class state machine). The block SHALL
use OpenRegister's action-keyed transition map, so every transition has a
name OpenRegister passes to its guard and its `TransitionEngine` can look up.
The actions SHALL be `propose`, `deliberate`, `openVoting`, `decide`,
`decideWithoutVote`, `enact`, `archive` and `withdraw`, covering the guarded
states `draft → proposed → deliberating → voting → decided → enacted →
archived` and a terminal `withdrawn` state reachable from any non-terminal
state before `enacted`. Every transition SHALL name the decidiq lifecycle
guard in `requires`. Lifecycle status SHALL be orthogonal to `outcome` (the
voting result) and `isPublished` (citizen visibility).

#### Scenario: Lifecycle is declared in the register

@e2e exclude register-structure invariant, verified by register import and PHPUnit on the `x-openregister-lifecycle` block, not browser-observable
- **GIVEN** the decidesk register definition
- **WHEN** the `decision` schema is inspected
- **THEN** its `x-openregister-lifecycle.transitions` is a map keyed by the eight action names
- **AND** every transition names the decidiq lifecycle guard in `requires`

#### Scenario: A decision can be withdrawn before enactment

@e2e exclude declarative-lifecycle transition contract, covered by PHPUnit/Newman on the declared transition map
- **GIVEN** a decision in lifecycle `deliberating`
- **WHEN** an authorised user withdraws it
- **THEN** the decision transitions to `withdrawn` and no further forward transition is permitted

#### Scenario: A guarded transition is rejected

@e2e exclude declarative-lifecycle guard contract, covered by PHPUnit/Newman (rejected illegal transition); UI only offers server-allowed actions
- **GIVEN** a decision in lifecycle `draft`
- **WHEN** a transition directly to `enacted` is attempted
- **THEN** the transition is rejected by the declared lifecycle and the status remains `draft`

### Requirement: Terminal-state completeness of outcome and decision date

`outcome` and `decisionDate` MUST be required **only in terminal outcome
states**, never in flight. A decision in `draft`, `proposed`, `deliberating`
or `voting` MUST be creatable and savable with neither field: an in-flight
motion has no legal outcome, and `lifecycle` is orthogonal to `outcome`
(ADR-005). A `withdrawn` decision MUST likewise never require them: it is
terminal in the lifecycle graph but was never decided, so it has no
`adopted`/`rejected` result. Accordingly the `Decision` schema's `required[]`
MUST list only `title`, `text` and `decisionType`.

The terminal outcome states are `decided`, `enacted` and `archived`.
`decided` is the first state past the vote (the schema's own `lifecycle`
description states that `outcome` is "the voting result, set when reaching
`decided`"), and the other two are reachable only through it. A decision MUST
NOT be able to ENTER any of those states without both an `outcome` drawn from
the schema enum (`adopted`|`rejected`) and a non-empty `decisionDate`; a value
outside the enum (e.g. a `pending` placeholder) MUST NOT satisfy the
requirement. The rejection MUST name the missing fields.

This rule MUST NOT be expressed as a JSON-Schema `if`/`then` block on the
schema, because OpenRegister does not enforce conditional `required`:
`Schema::getSchemaObject()` rebuilds the validated schema from a fixed key
list, so the block never reaches the validator and the constraint would be
decorative. Enforcement MUST live in the decidiq lifecycle guard, where the
state is actually entered, so it holds on every write path, including a write
straight to OpenRegister's object API.

**Feature tier**: MVP
**Legal reference**: Awb 3:40-3:45 (a besluit takes effect on its decision date)

#### Scenario: An in-flight motion is created without an outcome

@e2e exclude schema-contract invariant, verified by PHPUnit over the register `required[]` plus a live OpenRegister validation probe; not browser-observable
- GIVEN a motion in lifecycle `voting` carrying neither `outcome` nor `decisionDate`
- WHEN it is written to the `decision` schema
- THEN it MUST be accepted
- AND the register MUST NOT report "The required properties (decisionDate, outcome) are missing"

#### Scenario: A decision cannot reach a terminal state without its result

@e2e exclude guard contract, covered by PHPUnit on the guard; the UI only offers server-allowed actions
- GIVEN a decision in lifecycle `voting` carrying neither `outcome` nor `decisionDate`
- WHEN it is moved to `decided`, through decidiq or through OpenRegister's object API
- THEN the write MUST be refused with a message naming `outcome` and `decisionDate`
- AND the decision MUST NOT be persisted in the `decided` state

#### Scenario: A placeholder outcome does not count as a result

@e2e exclude guard contract, covered by PHPUnit on the guard's outcome vocabulary check
- GIVEN a decision in lifecycle `voting` whose `outcome` is `pending` (outside the schema enum)
- WHEN it is moved to `decided`
- THEN the write MUST be refused naming `outcome`

#### Scenario: Withdrawal never demands an outcome

@e2e exclude lifecycle-graph invariant, covered by PHPUnit on the terminal-state list
- GIVEN a decision in any non-terminal state
- WHEN it is withdrawn
- THEN no `outcome` or `decisionDate` MUST be demanded

#### Scenario: Shipped demo data obeys the rule

@e2e exclude seed-data invariant, verified by PHPUnit over the seeded decision objects, not browser-observable
- GIVEN the shipped decision seed objects
- WHEN each is inspected
- THEN every seed in a terminal outcome state MUST carry an enum `outcome` and a `decisionDate`
- AND at least one seed MUST be in flight carrying neither
