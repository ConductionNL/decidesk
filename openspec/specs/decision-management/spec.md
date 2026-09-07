---
status: in-progress
status-note: >-
  Completed 2026-06-12 via decision-state-machine-v1 (guarded 7-state transition map + per-domain policy, lifecycle/voting detail tabs) on top of decision-evolution-and-cascade + p2-minutes-and-decisions. In progress 2026-06-14 via unify-decision-supertype (Decision becomes the universal supertype: decisionType discriminator, folded motion/amendment/resolution fields, declarative lifecycle, contract attachments). Completed 2026-08-19 via appointment-decision-type-schema + appointment-decision-type-membership (both archived — completes ADR-005's reserved-but-unimplemented decisionType=appointment: folded nomination fields, retirement of the parallel Voordracht schema/register.d/61, and imperative Membership materialization on adoption).
openspec-changes:
  - unify-decision-supertype
  - decision-detail-fullpicture
  - urgent-decision-procedure
  - appointment-decision-type-schema
  - appointment-decision-type-membership
  - decision-facet-composition
---

# Decision Management Specification

## Purpose

Decision management is the core capability of Decidiq. A decision represents a formal choice made by a governance body, association, corporate board, or operational team. Each decision follows a configurable state machine lifecycle from proposal through deliberation, voting, and resolution. This specification covers the decision entity, status transitions, the Symfony Workflow-backed state machine, and audit trail recording.

**Standards**: Schema.org (`Action`, `VoteAction`, `ChooseAction`), Akoma Ntoso (`decision`, `judgment`), OpenRaadsinformatie (`Besluit`)
**Feature tier**: MVP

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Decision entity definition including property tables, Schema.org mappings, Akoma Ntoso alignment, and OpenRaadsinformatie mapping.

## Requirements

---

### Requirement: Decision Creation

The system MUST support creating decision records linked to a meeting, agenda item, or body. Each decision MUST have a `title`, a `body` (governing body reference), and an initial status of `draft`. Decisions MUST be stored as OpenRegister objects in the `decidesk` register using the `decision` schema.

**Feature tier**: MVP

#### Scenario: Create a decision from a meeting agenda item

- GIVEN a user with decision-making access and an active meeting with agenda items
- WHEN they create a new decision linked to agenda item "Budget Approval 2026"
- THEN the system MUST create an OpenRegister object in the `decidesk` register with the `decision` schema
- AND the object MUST have `@type` set to `schema:ChooseAction`
- AND the `status` MUST be set to `draft`
- AND the decision MUST reference the agenda item and meeting

#### Scenario: Create a standalone decision outside a meeting

- GIVEN a user with decision-making access
- WHEN they create a decision with title "Appoint new treasurer" and body "Board of Directors"
- THEN the system MUST create the decision with status `draft`
- AND the decision MUST NOT require a meeting or agenda item reference
- AND the decision MUST reference the body "Board of Directors"

#### Scenario: Fail to create a decision without a title

- GIVEN a user with decision-making access
- WHEN they submit a new decision form without a title
- THEN the system MUST reject the request with a validation error
- AND no OpenRegister object MUST be created

---

### Requirement: Decision State Machine

The system MUST enforce a configurable state machine for decision lifecycle management, implemented as a guarded transition map (`DecisionTransitionGuard` in `lib/Lifecycle/` — the decidiq lifecycle pattern, no workflow-library dependency). The lifecycle MUST be stored in an additive `lifecycle` field on the `Decision` schema and MUST include the states `draft`, `proposed`, `deliberating`, `voting`, `decided`, `enacted`, `archived`. Only valid transitions MUST be allowed; an invalid transition MUST be rejected with an error naming the allowed transitions from the current state. Transition policy MUST be configurable per governance domain (quorum enforcement, chair-only transitions, decide-without-vote for operational domains) with a default-deny fallback for unknown domains. Entering `voting` in a quorum-enforced domain with a linked meeting MUST be blocked while the meeting's quorum is not met. Chair-only transitions MUST be rejected when the caller is not the resolved meeting chair, and MUST fail closed when no chair can be resolved. The `enact` transition MUST require `outcome=adopted` and MUST record the enacted date. Every transition MUST be appended to the hash-chained audit log with actor and timestamp.

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
- THEN the system MUST return the current lifecycle state and exactly the actions permitted by the transition map and the domain policy

#### Scenario: Quorum gate blocks opening the vote

@e2e exclude backend quorum-guard contract — exhaustively covered by the PHPUnit guard matrix and the Newman 422 contract; the dev seed has no unmet-quorum meeting to drive a UI flow against
- GIVEN a decision in `deliberating` status linked to a meeting whose quorum is not met, in a quorum-enforced domain
- WHEN a user triggers the "openVoting" transition
- THEN the system MUST reject the transition with a quorum error
- AND the decision status MUST remain `deliberating`

#### Scenario: Chair-only transition is enforced per domain

@e2e exclude authorization contract — covered by PHPUnit (chair mismatch + unresolvable-chair fail-closed) and Newman; not a UI flow (the UI only offers actions the server allows)
- GIVEN a decision in a domain whose policy marks the transition chair-only
- WHEN an authenticated user who is not the resolved meeting chair triggers that transition
- THEN the system MUST reject the transition
- AND when no chair can be resolved at all the transition MUST also be rejected (fail closed)

---

### Requirement: Terminal-state completeness of outcome and decision date

`outcome` and `decisionDate` MUST be required **only in terminal outcome states**, never in flight. A decision in `draft`, `proposed`, `deliberating` or `voting` MUST be creatable and savable with neither field — an in-flight motion has no legal outcome, and `lifecycle` is orthogonal to `outcome` (ADR-005). A `withdrawn` decision MUST likewise never require them: it is terminal in the lifecycle graph but was never decided, so it has no `adopted`/`rejected` result. Accordingly the `Decision` schema's `required[]` MUST list only `title`, `text` and `decisionType`.

The terminal outcome states are `decided`, `enacted` and `archived` — `decided` is the first state past the vote (the schema's own `lifecycle` description states that `outcome` is "the voting result, set when reaching `decided`"), and the other two are reachable only through it. A decision MUST NOT be able to ENTER any of those states without both an `outcome` drawn from the schema enum (`adopted`|`rejected`) and a non-empty `decisionDate`; a value outside the enum (e.g. a `pending` placeholder) MUST NOT satisfy the requirement. The rejection MUST name the missing fields.

This rule MUST NOT be expressed as a JSON-Schema `if`/`then` block on the schema, because OpenRegister does not enforce conditional `required`: `Schema::getSchemaObject()` rebuilds the validated schema from a fixed key list, so the block never reaches the validator and the constraint would be decorative. Enforcement MUST therefore live at the transition boundary, where the state is actually entered.

**Feature tier**: MVP
**Legal reference**: Awb 3:40-3:45 (a besluit takes effect on its decision date)

#### Scenario: An in-flight motion is created without an outcome

@e2e exclude schema-contract invariant — verified by PHPUnit over the register `required[]` plus a live OpenRegister validation probe; not browser-observable
- GIVEN a motion in lifecycle `voting` carrying neither `outcome` nor `decisionDate`
- WHEN it is written to the `decision` schema
- THEN it MUST be accepted
- AND the register MUST NOT report "The required properties (decisionDate, outcome) are missing"

#### Scenario: A decision cannot reach a terminal state without its result

@e2e exclude transition-guard contract — covered by PHPUnit on the guard and the lifecycle service; the UI only offers server-allowed actions
- GIVEN a decision in lifecycle `voting` carrying neither `outcome` nor `decisionDate`
- WHEN the `decide` transition is attempted
- THEN the transition MUST be rejected with a message naming `outcome` and `decisionDate`
- AND the decision MUST NOT be persisted in the `decided` state

#### Scenario: A placeholder outcome does not count as a result

@e2e exclude transition-guard contract — covered by PHPUnit on the guard's outcome vocabulary check
- GIVEN a decision in lifecycle `voting` whose `outcome` is `pending` (outside the schema enum)
- WHEN the `decide` transition is attempted
- THEN the transition MUST be rejected naming `outcome`

#### Scenario: Withdrawal never demands an outcome

@e2e exclude lifecycle-graph invariant — covered by PHPUnit on the terminal-state list
- GIVEN a decision in any non-terminal state
- WHEN it is withdrawn
- THEN no `outcome` or `decisionDate` MUST be demanded

#### Scenario: Shipped demo data obeys the rule

@e2e exclude seed-data invariant — verified by PHPUnit over the seeded decision objects, not browser-observable
- GIVEN the shipped decision seed objects
- WHEN each is inspected
- THEN every seed in a terminal outcome state MUST carry an enum `outcome` and a `decisionDate`
- AND at least one seed MUST be in flight carrying neither

---

### Requirement: Decision Audit Trail

The system MUST maintain a complete audit trail for every decision, recording all state transitions, modifications, votes, and comments with timestamps and actor identities. The audit trail MUST be immutable.

**Feature tier**: MVP
**Legal reference**: WBTR (Wet bestuur en toezicht rechtspersonen) documentation requirements

#### Scenario: View the complete history of a decision

- GIVEN a decision that has moved through draft, proposed, deliberating, voting, and decided
- WHEN a user views the decision's audit trail
- THEN the system MUST display all transitions in chronological order
- AND each entry MUST show the timestamp, actor name, previous state, new state, and optional comment

#### Scenario: Audit trail entries are immutable

- GIVEN a decision with audit trail entries
- WHEN any user (including admin) attempts to modify or delete an audit trail entry
- THEN the system MUST reject the modification
- AND the original entry MUST remain unchanged

---

### Requirement: Decision List and Search

The system MUST provide a list view of all decisions with search, sort, and filter capabilities. Users MUST be able to filter by status, body, date range, and decision type.

**Feature tier**: MVP

#### Scenario: Filter decisions by status

- GIVEN the decision list contains decisions in various statuses
- WHEN the user filters by status "voting"
- THEN only decisions currently in the `voting` state MUST be displayed
- AND the result count MUST be shown

#### Scenario: Search decisions by title

- GIVEN decisions exist with titles "Budget 2026", "New parking policy", "Staff expansion"
- WHEN the user searches for "budget"
- THEN the decision "Budget 2026" MUST appear in the results
- AND the search MUST be case-insensitive

---

### Requirement: Decision Detail View

The system MUST provide a detail view for each decision using the `CnDetailPage` and `CnObjectSidebar` components, declared via the manifest registry pattern. The detail view MUST show decision metadata, a **Lifecycle** sidebar tab with state machine visualization (every lifecycle state marked done/current/upcoming plus the allowed next transitions as actions), a **Voting** sidebar tab with voting results (for/against/abstain tallies from the voting-round and vote objects linked through the decision's motion), the linked agenda item/meeting, and the audit trail.

**Feature tier**: MVP

#### Scenario: View decision detail with voting results

- GIVEN a decision in `decided` status with completed voting
- WHEN the user navigates to the decision detail view
- THEN the page MUST display the decision title, body, status badge, and description
- AND the voting results MUST show for/against/abstain counts
- AND the state machine visualization MUST highlight the current state
- AND the sidebar MUST show metadata, linked meeting, and action buttons

#### Scenario: State machine visualization highlights the current state

- GIVEN a decision in any lifecycle state
- WHEN the user opens the Lifecycle tab on the decision detail view
- THEN all seven lifecycle states MUST be rendered in order
- AND the decision's current state MUST be visually highlighted
- AND the allowed next transitions MUST be presented as actions

### Requirement: Decision type discriminator

The `decision` schema SHALL carry a required `decisionType` enum discriminator with values `motion`, `amendment`, `resolution`, `contract`, `appointment`, `management-point`, `policy`, and `meeting-outcome`. `Decision` SHALL be the single universal supertype for every governance outcome; motion, amendment, resolution, contract, appointment, management point, policy and generic meeting outcome SHALL NOT be modelled as separate schemas but as values of `decisionType` (ADR-005, ADR-006). The decision register list, search and detail page SHALL be one surface filtered by `decisionType`; a typed nav entry such as "Moties" SHALL be the decision register pre-filtered to `decisionType=motion`, never a separate store.

#### Scenario: Create a decision with a required type

@e2e exclude validation contract — covered by PHPUnit/Newman on the required-field rule, not a distinct UI flow
- **GIVEN** a user with decision-making access creating a decision
- **WHEN** they submit a decision without selecting a `decisionType`
- **THEN** the system MUST reject the create with a validation error naming `decisionType` as required

#### Scenario: A typed nav filter is the same store

@e2e exclude store-sourcing invariant — covered by the unified-store filter test; not browser-observable beyond the list already exercised
- **GIVEN** decisions exist with `decisionType` values `motion` and `resolution`
- **WHEN** the user opens the "Moties" nav entry
- **THEN** the decision register list is shown pre-filtered to `decisionType=motion`, sourced from the same `decision` store as all other decisions

---

### Requirement: Folded type-specific fields with progressive disclosure

The `decision` schema SHALL absorb the type-specific fields formerly carried by the retired `motion`, `amendment`, and `resolution` schemas: motion fields (`motionType`, `proposer`, `coSigners`, `text`), amendment fields (`proposedText` and an `amends` relation to the parent decision), and resolution fields (`resolutionNumber`, resolution `type`, `voteType`, `voteThreshold`, `fullText`, `background`, `adoptionDate`, `effectiveDate`). These fields SHALL render only when the matching `decisionType` is selected (progressive disclosure, ADR-004 Rule 2). Required-field enforcement SHALL be keyed on `decisionType`: a `motion` decision SHALL require `proposer`; a `resolution` decision SHALL require `resolutionNumber` and `voteThreshold`; an `amendment` decision SHALL require the `amends` relation to a parent decision.

#### Scenario: Motion fields appear only for a motion decision

@e2e exclude progressive-disclosure UI binding — covered by vitest on the form's conditional field rendering
- **GIVEN** a user creating a decision
- **WHEN** they select `decisionType = motion`
- **THEN** the form reveals the `proposer`, `coSigners`, and `motionType` fields, and these fields are hidden when `decisionType` is `meeting-outcome`

#### Scenario: Type-specific required field is enforced

@e2e exclude validation contract — covered by PHPUnit/Newman on the type-keyed required-field rule
- **GIVEN** a user creating a decision with `decisionType = resolution`
- **WHEN** they submit without `resolutionNumber` or `voteThreshold`
- **THEN** the create is rejected with a validation error naming the missing resolution fields

#### Scenario: Amendment links to its parent decision

@e2e exclude relation-storage contract — covered by PHPUnit/Newman on the OpenRegister `amends` relation
- **GIVEN** an existing `decisionType = motion` decision
- **WHEN** a user creates a `decisionType = amendment` decision and sets its `amends` relation to that motion decision
- **THEN** the amendment decision is stored with an OpenRegister relation to the parent motion decision

---

### Requirement: Declarative decision lifecycle

The decision lifecycle SHALL be declared as an `x-openregister-lifecycle` block on the `decision` schema in `lib/Settings/decidesk_register.json` (ADR-031 — declarative, NOT a Service-class state machine). The lifecycle SHALL retain the existing guarded states `draft → proposed → deliberating → voting → decided → enacted → archived` and SHALL add a terminal `withdrawn` state reachable from any non-terminal state before `enacted`. Lifecycle status SHALL be orthogonal to `outcome` (the voting result) and `isPublished` (citizen visibility). Transitions SHALL be guarded by the declared transition map; no imperative `DecisionService` transition method SHALL be introduced by this change.

#### Scenario: Lifecycle is declared in the register

@e2e exclude register-structure invariant — verified by register-import + PHPUnit on the `x-openregister-lifecycle` block, not browser-observable
- **GIVEN** the decidesk register definition
- **WHEN** the `decision` schema is inspected
- **THEN** it contains an `x-openregister-lifecycle` block declaring the guarded transition map including the `withdrawn` terminal state

#### Scenario: A decision can be withdrawn before enactment

@e2e exclude declarative-lifecycle transition contract — covered by PHPUnit/Newman on the declared transition map
- **GIVEN** a decision in lifecycle `deliberating`
- **WHEN** an authorised user withdraws it
- **THEN** the decision transitions to `withdrawn` and no further forward transition is permitted

#### Scenario: A guarded transition is rejected

@e2e exclude declarative-lifecycle guard contract — covered by PHPUnit/Newman (rejected illegal transition); UI only offers server-allowed actions
- **GIVEN** a decision in lifecycle `draft`
- **WHEN** a transition directly to `enacted` is attempted
- **THEN** the transition is rejected by the declared lifecycle guard and the status remains `draft`

---

### Requirement: Contract decisions carry offer/order/product attachments

A `decisionType = contract` decision SHALL be able to carry `offer`, `order`, and `product` objects as attachments via `x-openregister-relations` on the `decision` schema (ADR-005). The `offer`, `order`, and `product` schema.org schemas SHALL NOT exist as orphaned standalone entities in the nav; they SHALL be reachable as attachments of a contract decision. Non-contract decision types SHALL NOT require these attachments.

#### Scenario: Attach an offer to a contract decision

@e2e exclude relation-storage contract — covered by PHPUnit/Newman on the `offer` OpenRegister relation
- **GIVEN** a `decisionType = contract` decision
- **WHEN** an `offer` object is related to it
- **THEN** the offer is stored as an OpenRegister relation on the contract decision and appears in the decision's attachments

#### Scenario: Procurement schemas are not orphaned nav items

@e2e exclude navigation-structure invariant — verified by manifest/nav assertion, not a distinct UI flow
- **GIVEN** the decidiq navigation
- **WHEN** the nav is rendered
- **THEN** `offer`, `order`, and `product` do not appear as standalone top-level stores; they are reached through contract decisions

---

### Requirement: Re-seeded typed decision demo data

The shipped demo data SHALL include at least one `decision` seed object per `decisionType` value so every type is demonstrable on install (ADR-016). The former `motion`, `amendment`, and `resolution` seed objects SHALL be re-seeded as `decision` objects with the matching `decisionType`, preserving their slugs where reasonable and re-pointing their relations (amendment→motion becomes an `amends` relation between decisions). Seed data SHALL use general organisation domains (municipality, corporate board, consultancy, travel agency).

#### Scenario: Every decision type has a seed

@e2e exclude seed-data invariant — verified by register-import + PHPUnit over the seeded objects, not browser-observable
- **GIVEN** a freshly installed decidesk register
- **WHEN** the decision register is listed
- **THEN** at least one decision exists for each `decisionType` value (`motion`, `amendment`, `resolution`, `contract`, `appointment`, `management-point`, `policy`, `meeting-outcome`)

#### Scenario: Migrated amendment seed links to its motion

@e2e exclude seed-data relation invariant — verified by register-import + PHPUnit on the seeded `amends` relation
- **GIVEN** the re-seeded demo data
- **WHEN** a `decisionType = amendment` seed (e.g. `amendement-cultuursubsidie`) is inspected
- **THEN** it carries an `amends` relation to a `decisionType = motion` decision

### Requirement: Decision route relation

The `Decision` schema SHALL support a `route` relation to `DecisionStage` objects (one Decision → many DecisionStage), representing the ordered path the decision travels across decision-makers. The route SHALL be optional: a Decision with an empty route SHALL remain valid and behave as a single-body decision, preserving all behaviour of decisions created before this change. Adding or removing stages SHALL NOT change the Decision's own `lifecycle` field; the route is orthogonal to (and complements) the decision-to-decision relations owned by the `decision-relations` change.

#### Scenario: A decision exposes its route

@e2e exclude relation-resolution contract — covered by PHPUnit/Newman on the `route`→stages resolution
- **GIVEN** a Decision with three related DecisionStage objects
- **WHEN** the decision is loaded
- **THEN** its `route` resolves to the stages in `sequence` order without altering the decision's `lifecycle`

#### Scenario: Existing single-body decisions are unaffected

@e2e exclude backward-compatibility invariant — covered by PHPUnit on empty-route behaviour, not browser-observable
- **GIVEN** a Decision created before this change with no stages
- **WHEN** it is loaded
- **THEN** its `route` is empty and every existing field and lifecycle transition behaves exactly as before

### Requirement: Declarative route-progress fields on Decision

The `Decision` schema SHALL expose declarative route-progress fields (ADR-031), computed from its related DecisionStage objects with no imperative Service code: `currentStage` (the first stage whose `status` is neither `decided` nor `skipped`, by `sequence`; null when the route is complete), `stageCount`, `decidedStageCount`, `skippedStageCount`, and `routeComplete`. These fields SHALL be derived/materialised by OpenRegister calculations and aggregations, mirroring the existing declarative pattern already used on the Meeting schema. They SHALL NOT introduce a new Service or modify the Decision lifecycle transition map.

#### Scenario: Route progress is materialised on the decision

@e2e exclude declarative-derivation contract — covered by register/Newman, not a UI flow

- **GIVEN** a Decision with a route of three stages, two `decided` and one `active`
- **WHEN** the decision is loaded
- **THEN** `currentStage` points at the active stage, `stageCount` is 3, `decidedStageCount` is 2, and `routeComplete` is false — all derived declaratively

### Requirement: Typed decision-to-decision modification relations

The `Decision` schema SHALL support typed modification relations to other decisions, stored as OpenRegister object relations on the source decision: `supersedes` and `repeals` (effect-bearing) and `implements` and `refersTo` (informational). The existing `amends` relation (decisionType=amendment → its parent motion) SHALL retain its current meaning and SHALL be widened in description to cover "this decision modifies that decision"; a second `amends` relation SHALL NOT be introduced. Inverse views (superseded-by, repealed-by, implemented-by, referenced-by) SHALL be derived from OpenRegister relation queries and SHALL NOT be stored separately. Creating or removing an effect-bearing relation SHALL require the same governance-body authority as decision state transitions; informational relations SHALL require decision write access. Every relation addition and removal SHALL be recorded in the immutable audit trail of both the source and the target decision. The relation types SHALL map to the cited standards: Akoma Ntoso active/passive modifications, OpenRaadsinformatie `Besluit` relations, and schema.org `replacer`/`replacee` for supersession.

#### Scenario: Declare that a decision supersedes another

@e2e exclude relation-storage + audit contract — covered by PHPUnit/Newman on the stored `supersedes` relation and dual audit entries
- **GIVEN** a staff member with governance-body authority editing decision "Programmabegroting 2027"
- **WHEN** they add a `supersedes` relation to the enacted decision "Programmabegroting 2026"
- **THEN** the relation is stored on "Programmabegroting 2027", and both decisions' audit trails record the relation with actor and timestamp

#### Scenario: Inverse relation is derived

@e2e exclude derived-query contract — covered by PHPUnit/Newman on the relation query; the UI scenario lives in the relations tab
- **GIVEN** decision A with a stored `supersedes` relation to decision B
- **WHEN** decision B's incoming relations are queried
- **THEN** B reports "superseded by A" derived from the OpenRegister relation query, and no inverse relation is stored on B

#### Scenario: Effect-bearing relation requires authority

@e2e exclude API authorization contract — covered by Newman, not a UI flow
- **WHEN** an authenticated user without governance-body authority attempts to add a `repeals` relation
- **THEN** the request is rejected with HTTP 403 and no relation or audit entry is created

---

### Requirement: Relation integrity validation

The system SHALL validate relations at write time: self-references SHALL be rejected for all relation types; cycles in the effect-bearing subgraph (`supersedes`/`repeals`) SHALL be rejected via a bounded graph walk with a clear error naming the conflicting decision; relation targets MUST be decisions in the same decidesk register. Effect-bearing relations MAY be declared at any source status but SHALL exert effect only while the source decision is in status `decided` or `enacted`. Validation SHALL be expressed declaratively where OpenRegister supports it (relation constraints), otherwise via a thin server-side validation seam — relation CRUD itself SHALL remain on the OpenRegister object API (no pass-through controller, per ADR-022).

#### Scenario: Self-reference rejected

@e2e exclude relation-validation contract — covered by PHPUnit on the self-reference guard
- **WHEN** a user attempts to add any relation from a decision to itself
- **THEN** the relation is rejected with a validation error and nothing is stored

#### Scenario: Cycle rejected

@e2e exclude graph-validation contract — covered by PHPUnit on the relation validation
- **GIVEN** decision A supersedes decision B
- **WHEN** a user attempts to add a `supersedes` relation from B to A
- **THEN** the relation is rejected with an error naming the existing A→B relation and nothing is stored

#### Scenario: Draft relation exerts no effect yet

@e2e exclude derived effective-status contract — covered by PHPUnit on the read-time derivation gated by source status
- **GIVEN** a decision in status `draft` carrying a `repeals` relation to an enacted decision
- **WHEN** the target decision is displayed
- **THEN** the target still presents as in force, and only when the source reaches `decided`/`enacted` does the target's effective status become `repealed`

---

### Requirement: Derived effective status

The system SHALL compute an `effectiveStatus` for every decision, derived at read time from inbound effect-bearing relations and never stored as a lifecycle state: `repealed` when a decided/enacted decision `repeals` it, else `superseded` when a decided/enacted decision `supersedes` it, else the decision's lifecycle status. The lifecycle status and its audit trail SHALL remain unchanged by relations. The derivation SHALL be expressed declaratively as an OpenRegister calculation where the inverse-relation lookup is expressible; otherwise the detail view SHALL compute it client-side from the same incoming-relation query the relations tab uses. The precedence (`repealed` > `superseded` > lifecycle) SHALL hold regardless of mechanism. A declarative ADR-031 notification rule SHALL notify the governance body when a decision becomes superseded or repealed.

#### Scenario: Enacted supersession flips effective status

@e2e exclude derived effective-status contract — covered by PHPUnit on the read-time derivation (lifecycle + audit unchanged)
- **GIVEN** enacted decision "Programmabegroting 2026" and decision "Programmabegroting 2027" carrying `supersedes` → "Programmabegroting 2026"
- **WHEN** "Programmabegroting 2027" is enacted
- **THEN** "Programmabegroting 2026" presents effectiveStatus `superseded` while its lifecycle status remains `enacted` and its audit trail is unchanged

#### Scenario: Repeal outranks supersession

@e2e exclude precedence rule — covered by PHPUnit on the derivation
- **GIVEN** a decision targeted by both an enacted `supersedes` and an enacted `repeals` relation
- **WHEN** its effectiveStatus is computed
- **THEN** the result is `repealed`

#### Scenario: Body notified on repeal

@e2e exclude declarative notification dialect — verified by the notification-dialect gate plus PHPUnit on the rule import
- **WHEN** a decision's effectiveStatus becomes `repealed`
- **THEN** recipients defined in the ADR-031 rule receive an NC notification naming the repealing decision

---

### Requirement: In-force visibility in list and detail views

The decision list SHALL offer an in-force filter exposing the values `in force`, `superseded`, and `repealed`, computed from the derived `effectiveStatus`. The decision detail view SHALL display a prominent banner when `effectiveStatus` differs from the lifecycle status, naming the effecting decision with navigation to it. The lifecycle status badge SHALL always remain visible alongside the effective status.

#### Scenario: Filter the register to decisions in force

@e2e exclude effective-status filter contract — covered by PHPUnit/Newman on the in-force filter over derived status
- **GIVEN** a register containing enacted, superseded, and repealed decisions
- **WHEN** the user filters the decision list by `in force`
- **THEN** superseded and repealed decisions are excluded from the results and the result count reflects only decisions in force

#### Scenario: Superseded banner with chain navigation

@e2e exclude detail-view derived-banner binding — covered by vitest on the banner component fed the derived effectiveStatus
- **GIVEN** a decision whose effectiveStatus is `superseded` by "Programmabegroting 2027"
- **WHEN** the user opens its detail view
- **THEN** a banner states it is superseded by "Programmabegroting 2027" with its date, activating the banner navigates to "Programmabegroting 2027", and the original lifecycle badge remains visible

### Requirement: Publication state is owned by the publication flow

The `Decision` fields `isPublished` and `publishedAt` SHALL be derived outputs of the public-publication flow: set on publish, cleared on withdraw, and rejected when written directly through object update requests. The decision detail view SHALL expose publish and withdraw actions to staff with governance-body authority, visible only when the decision meets the publication eligibility gates. Publish and withdraw events SHALL be recorded in the decision's immutable audit trail with actor, timestamp, and (for withdraw) reason.

#### Scenario: Publish action visible only when eligible

- **GIVEN** a staff member viewing a decision in status `enacted`
- **WHEN** they open the decision detail view
- **THEN** a publish action is available, while the same view for a `draft` decision offers no publish action

#### Scenario: Direct client write to isPublished rejected

@e2e exclude server-side field guard — covered by Newman attempting a direct OR object update
- **WHEN** a client sends an object update setting `isPublished: true` on a decision outside the publication flow
- **THEN** the write to `isPublished`/`publishedAt` is rejected and the stored values are unchanged

#### Scenario: Publication events in the audit trail

- **GIVEN** a decision that has been published and later withdrawn
- **WHEN** a user views the decision's audit trail
- **THEN** both the publish and the withdraw appear in chronological order with timestamp, actor, and the withdraw reason

### Requirement: Appointment decision type carries folded nomination fields

`Decision` MUST expose a set of appointment-specific fields, revealed via
progressive disclosure (ADR-004 Rule 2) only when `decisionType = appointment`:
`targetBody` (reference to the `GovernanceBody` the appointment is for),
`targetPosts` (zero or more references to `Post`), `targetRole` (the Membership
role being appointed to), `candidates` (one or more structured candidates, each
carrying either a `person` reference or a free-text `externalName` for a
not-yet-registered candidate), and `nominatingParty` (the fractie/orgaan/persoon
that made the nomination). These fields replace the retired `Voordracht` schema's
`body`/`post`/`targetRole`/`kandidaten`/`nominatingParty` fields one-for-one
(ADR-005, ADR-006 — one schema per concept, discriminator over parallel entity).

@e2e exclude no current e2e test opens the decision form with `decisionType = appointment` and asserts field disclosure/validation; the sibling motion field-disclosure pattern is covered elsewhere but appointment-specific disclosure and the candidates-required-before-propose guard are untested — genuine coverage gap tracked as e2e debt.

#### Scenario: Appointment fields appear only for an appointment decision

- GIVEN the decision form
- WHEN `decisionType = appointment` is selected
- THEN `targetBody`, `targetPosts`, `targetRole`, `candidates`, and
  `nominatingParty` are revealed
- AND motion/amendment/resolution-specific fields stay hidden

#### Scenario: A non-appointment decision does not require appointment fields

- GIVEN a decision with `decisionType = motion`
- WHEN it is created without `targetBody` or `candidates`
- THEN it is accepted — these fields are appointment-only and are enforced at
  the form/spec layer, not in the JSON-schema `required[]`, matching the
  established per-type required-field pattern (motion/resolution)

#### Scenario: At least one candidate is expected before submitting an appointment

- GIVEN a `decisionType = appointment` decision being edited in `lifecycle = draft`
- WHEN `candidates` is empty
- THEN the form marks `candidates` as required before allowing the `propose`
  action — matching the established form-only enforcement for other per-type
  required fields (e.g. motion's `proposer`, resolution's `resolutionNumber`);
  no server-side JSON-schema or service-layer guard exists for this, exactly
  as none exists for the sibling per-type required fields

### Requirement: Appointment decisions reuse the Decision lifecycle, not a bespoke one

An appointment decision MUST use `Decision`'s existing declarative 7-state
lifecycle (`draft → proposed → deliberating → voting → decided → enacted →
archived`, plus terminal `withdrawn`) rather than the retired `Voordracht`
schema's bespoke 5-state lifecycle (`submitted → handled → appointed |
not-appointed`, `withdrawn`). This follows the same reuse decision the archived
`unify-decision-supertype` change made for motion/amendment/resolution (design
D2): the proven, already-declarative `x-openregister-lifecycle` block on
`Decision` is not duplicated per type.

@e2e exclude the shared lifecycle transition mechanism (draft→proposed→deliberating→voting→decided) is exercised generically for motion/resolution decisionTypes by tests/e2e/spec-coverage/decision-management.spec.ts (`transition-a-decision-from-draft-to-proposed`, `transition-a-decision-to-enacted-after-approval`), but no test creates a `decisionType = appointment` decision and drives it through the same transitions — no e2e file carries an @e2e tag for this exact scenario.

#### Scenario: An appointment decision progresses through the shared lifecycle

- GIVEN a `decisionType = appointment` decision in `lifecycle = draft`
- WHEN it is proposed, deliberated, and voted on
- THEN it moves through `proposed → deliberating → voting` exactly as any other
  decision type does, using the single `x-openregister-lifecycle` block declared
  once on `Decision`

#### Scenario: Adoption is expressed the same way as any other decision type

- GIVEN a `decisionType = appointment` decision reaching `lifecycle = decided`
- WHEN the vote outcome is recorded
- THEN `outcome = adopted` or `outcome = rejected` is set exactly as for any
  other `decisionType`, subject to the existing terminal-completeness rule
  (`x-decidesk-terminal-completeness`)

### Requirement: Adopted appointments record their materialized Memberships

`Decision` MUST expose a nullable, server-set `appointedMemberships` field
(array of references to `Membership`) on the `decisionType = appointment`
folded field set. This field is declared here so the schema is complete in one
place; it is populated by the imperative Membership-materialization service
shipped in the dependent change `appointment-decision-type-membership`
(`depends_on` this change) — no service code ships in this change.

@e2e exclude schema/register-shape assertion (a nullable server-set field is present and empty pre-materialization) — no UI surface; the materialization service itself (once shipped) is verified by tests/Unit/Service/DecisionLifecycleServiceTest.php, see the requirement below.

#### Scenario: The field exists and accepts no client writes before materialization ships

- GIVEN a freshly-adopted `decisionType = appointment` decision, before the
  dependent change ships
- WHEN the decision is inspected
- THEN `appointedMemberships` is present on the schema and empty/absent — no
  error, no orphaned reference

### Requirement: The Voordracht schema is retired in favor of decisionType=appointment

The standalone `Voordracht` schema (`lib/Settings/register.d/61-appointments-and-terms.json`)
MUST be removed. Its 3 demo seed objects MUST be re-authored as `Decision`
seeds with `decisionType = appointment`, mapping the retired lifecycle onto the
shared `Decision` lifecycle (`submitted→proposed`, `handled→deliberating`,
`appointed→decided` with `outcome=adopted`, `not-appointed→decided` with
`outcome=rejected`, `withdrawn→withdrawn`). `TermijnRegeling`,
`RoosterVanAftreden`, and `RoosterRegel` in the same register fragment are
**not** part of this requirement — they reference `Membership`, never
`Voordracht`, and are unaffected.

@e2e exclude schema/register-shape and manifest-shape assertions (schema removal, re-authored seeds, nav-entry removal) — checkable by inspecting `lib/Settings/register.d/61-appointments-and-terms.json` and `src/manifest.d/appointments-and-terms.json` directly; no dedicated PHPUnit or e2e test exists yet for this specific removal, and no UI surface distinct from existing decision e2e coverage exercises it — genuine coverage gap tracked as e2e debt.

#### Scenario: Voordracht is absent from the register after this change

- GIVEN the decidesk register
- WHEN `components.schemas` in `register.d/61-appointments-and-terms.json` is
  inspected
- THEN `Voordracht` is absent and `TermijnRegeling`, `RoosterVanAftreden`,
  `RoosterRegel` are present, unchanged

#### Scenario: Every retired voordracht seed has a re-authored decision seed

- GIVEN a freshly installed register
- WHEN the `Decision` seed objects are inspected
- THEN 3 `decisionType = appointment` seeds exist, one per retired `voordracht`
  seed (`voordracht-auditcommissie-lid`, `voordracht-rvc-vanduin`,
  `voordracht-auditcommissie-vz`), each carrying the equivalent
  `targetBody`/`targetRole`/`candidates`/`nominatingParty` data and the mapped
  lifecycle/outcome

#### Scenario: The Voordrachten nav pages are removed, Rooster/Termijnregeling pages are untouched

- GIVEN `src/manifest.d/appointments-and-terms.json`
- WHEN the `menu` and `pages` arrays are inspected
- THEN the `Voordrachten` menu entry and the `Voordrachten`/`VoordrachtDetail`
  pages are absent
- AND `Roosters`, `RoosterDetail`, `Roosterregels`, `RoosterregelDetail`,
  `Termijnregelingen`, `TermijnRegelingDetail` are present, unchanged

### Requirement: Appointment adoption materializes Membership records

When a `decisionType = appointment` decision transitions into `lifecycle =
enacted` with `outcome = adopted`, `DecisionLifecycleService` MUST create one
`Membership` object per entry in `candidates`, each carrying
`role = targetRole`, `governanceBody = targetBody`, the paired `post` (per the
pairing rule below), `startDate = enactedAt`, and either `person` (when the
candidate carries one) or `label = externalName` (for a not-yet-registered
candidate). The created Membership ids MUST be written back onto the
decision's `appointedMemberships` field. This activates the field
`appointment-decision-type-schema` declared but left inert.

**Post pairing rule**: when `targetPosts` is empty, every Membership is
created with no `post` (role-only appointment). When `targetPosts` has
exactly one entry, every Membership is created with that Post. When
`targetPosts` has more than one entry, its length MUST equal `candidates`'
length and posts are paired to candidates by array index — see the transition
guard requirement below for the enforcement point.

@e2e exclude unit-level service/algorithm behaviour (Membership materialization + pairing logic in `DecisionLifecycleService`), covered by tests/Unit/Service/DecisionLifecycleServiceTest.php: testMaterializesSingleRoleOnlyMembershipForPersonCandidate, testMaterializesExternalCandidateByLabel, testMaterializesMultipleCandidatesPairedByIndex, testMaterializesSharedPostForAllCandidatesWhenExactlyOneTargetPost, testRejectedOutcomeNeverMaterializesAMembership, testMaterializationDoesNotRunTwice — not independently UI-observable beyond the existing enact-transition e2e coverage.

#### Scenario: A single-candidate, role-only appointment materializes one Membership

- GIVEN a `decisionType = appointment` decision with one candidate carrying a
  `person` reference, `targetRole = member`, `targetBody` set, and no
  `targetPosts`
- WHEN the decision transitions from `decided` (`outcome = adopted`) to
  `enacted`
- THEN exactly one `Membership` is created with `person` set to the
  candidate's person, `role = member`, `governanceBody = targetBody`, no
  `post`, and `startDate` equal to the decision's `enactedAt`
- AND the decision's `appointedMemberships` contains the new Membership's id

#### Scenario: An external (not-yet-registered) candidate is materialized by name

- GIVEN a `decisionType = appointment` decision with one candidate carrying
  only `externalName` (no `person`)
- WHEN the decision is enacted with `outcome = adopted`
- THEN the created `Membership` has `label` set to the candidate's
  `externalName` and no `person` reference

#### Scenario: Multiple candidates for multiple posts pair by index

- GIVEN a `decisionType = appointment` decision with 2 candidates and
  `targetPosts` containing 2 Post references
- WHEN the decision is enacted with `outcome = adopted`
- THEN 2 Memberships are created, each pairing `candidates[i]` with
  `targetPosts[i]`

#### Scenario: A rejected appointment materializes no Memberships

- GIVEN a `decisionType = appointment` decision reaching `lifecycle = decided`
  with `outcome = rejected`
- WHEN the decision is later transitioned (e.g. to `archived` per the shared
  lifecycle)
- THEN no `Membership` is created and `appointedMemberships` stays empty

#### Scenario: Materialization does not run twice

- GIVEN a `decisionType = appointment` decision that has already been enacted
  and has a non-empty `appointedMemberships`
- WHEN `applyPostTransitionEffects` runs again for any reason
- THEN no additional `Membership` objects are created (idempotency guard)

### Requirement: The enact transition rejects an unpairable candidates/posts mismatch

`DecisionLifecycleService::resolveRejection()` MUST reject the `enact` action
for a `decisionType = appointment` decision when `targetPosts` has more than
one entry and its length does not equal `candidates`' length — before the
lifecycle write happens, following the same fail-closed pattern as the
existing quorum-before-`voting` and outcome-before-`enact` gates.

@e2e exclude unit-level service/algorithm behaviour, covered by tests/Unit/Service/DecisionLifecycleServiceTest.php::testEnactRejectsMismatchedPostsCandidatesCount (mismatch case) and testMaterializesSharedPostForAllCandidatesWhenExactlyOneTargetPost / testMaterializesSingleRoleOnlyMembershipForPersonCandidate (zero/one-post cases that must NOT block) — not independently UI-observable.

#### Scenario: A mismatched posts/candidates count blocks enactment

- GIVEN a `decisionType = appointment` decision with 3 candidates and
  `targetPosts` containing 2 Post references
- WHEN the `enact` transition is attempted
- THEN it is rejected with a message identifying the posts/candidates count
  mismatch, and the decision's `lifecycle` is unchanged

#### Scenario: Zero or one target post never blocks enactment

- GIVEN a `decisionType = appointment` decision with any number of candidates
  and either zero or exactly one `targetPosts` entry
- WHEN the `enact` transition is attempted (all other gates satisfied)
- THEN the pairing guard does not reject it

### Requirement: Appointment fields render on the Decision detail page

The `DecisionDetail` page's `decision-content` widget MUST include
`targetBody`, `targetPosts`, `targetRole`, `candidates`, `nominatingParty`,
and `appointedMemberships` in its `content.include` scope, so an appointment
decision's nomination data is visible without a bespoke Vue component — the
same generic manifest-driven rendering that already shows `motionType`/
`proposer` for `decisionType = motion` on this widget.

@e2e exclude the generic `decision-content` widget rendering mechanism is exercised for `decisionType = motion` by tests/e2e/spec-coverage/decision-management.spec.ts (`view-decision-detail-with-voting-results`), but no test opens a `decisionType = appointment` decision and asserts `candidates`/`targetBody`/`targetPosts`/`nominatingParty`/`appointedMemberships` render — no e2e file carries an @e2e tag for this exact scenario.

#### Scenario: An appointment decision's candidates are visible on its detail page

- GIVEN a `decisionType = appointment` decision with `candidates` and
  `targetBody` set
- WHEN its `DecisionDetail` page is opened
- THEN the `Content` widget displays `candidates`, `targetBody`, `targetRole`,
  `targetPosts`, `nominatingParty`, and `appointedMemberships` alongside the
  existing generic fields

### Requirement: Decision detail surfaces referencing consultations (REQ-DFC-001)

The Decision Detail page MUST render three declarative `object-list` widgets, each reverse-filtered on the current decision's id, for the three consultation kinds that can reference a Decision: `public-consultation` (filtered on its `decision` property), `member-consultation` (filtered on its `decision` property), and `consultation-request` — the WOR traject — (filtered on its `relatedDecision` property). Each widget MUST link its rows to the consultation kind's existing detail route and MUST render an empty-state message when no matching records exist.

#### Scenario: A decision referenced by a public consultation

- GIVEN a `PublicConsultation` object whose `decision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Public consultations" widget lists that consultation
- AND clicking the row navigates to `ConsultationDetail` for that consultation

@e2e exclude tests/e2e/spec-coverage/facets-decision-detail.spec.ts only exercises this widget's EMPTY state ("No public consultations reference this decision yet."); this scenario's populated-list assertion (a real PublicConsultation linked and its row navigating to ConsultationDetail) is untested — genuine coverage gap tracked as e2e debt.

#### Scenario: A decision with no member consultations

- GIVEN Decision D has no `MemberConsultation` object referencing it
- WHEN a user opens Decision D's detail page
- THEN the "Member consultations" widget renders its configured empty-state text instead of an empty table

@e2e exclude exercised by tests/e2e/spec-coverage/facets-decision-detail.spec.ts ("DecisionDetail: consultation, advisory-opinion, zienswijze and confidentiality facets render their real empty states" — asserts "No member consultations reference this decision yet."); that test's own @e2e anchor still targets the pre-archival openspec/changes/decision-facet-composition/... path so this gate does not match it — recorded here rather than reported as a gap.

#### Scenario: A decision referenced by a WOR consultation request

- GIVEN a `ConsultationRequest` object whose `relatedDecision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Works council (WOR)" widget lists that request
- AND clicking the row navigates to `WorksCouncilConsultationDetail` for that request

@e2e exclude tests/e2e/spec-coverage/facets-decision-detail.spec.ts only exercises this widget's EMPTY state ("No works-council consultation requests reference this decision yet."); this scenario's populated-list assertion (a real ConsultationRequest linked and its row navigating to WorksCouncilConsultationDetail) is untested — genuine coverage gap tracked as e2e debt.

### Requirement: Decision detail surfaces advisory-opinion requests (REQ-DFC-002)

The Decision Detail page MUST render a declarative `object-list` widget listing `adviceRequest` (Adviesaanvraag) objects whose `relatedDecision` property equals the current decision's id, linking each row to `AdviesaanvraagDetail`. The widget is not required to resolve or list the `Advies` records answering each request (those remain reachable one click away, on the Adviesaanvraag's own detail page).

#### Scenario: A decision with an open advisory-opinion request

- GIVEN an `Adviesaanvraag` object whose `relatedDecision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Advisory opinions" widget lists that request with its subject and lifecycle status
- AND clicking the row navigates to `AdviesaanvraagDetail`

@e2e exclude tests/e2e/spec-coverage/facets-decision-detail.spec.ts only exercises this widget's EMPTY state ("No advisory-opinion requests reference this decision yet."); this scenario's populated-list assertion (a real Adviesaanvraag linked and its row navigating to AdviesaanvraagDetail) is untested — genuine coverage gap tracked as e2e debt.

### Requirement: Decision detail surfaces zienswijzerondes and zienswijzen (REQ-DFC-003)

The Decision Detail page MUST render two declarative `object-list` widgets: one listing `zienswijzeronde` objects whose `decision` property equals the current decision's id, and one listing `zienswijze` objects whose `decision` property equals the current decision's id. Both MUST link their rows to `ZienswijzerondeDetail` (zienswijze records have no standalone detail route in the shipped `shared-governance-bodies` fragment; they are viewed through their parent ronde, matching that fragment's own index-page convention).

#### Scenario: A decision is a shared body's closing vaststellingsbesluit

- GIVEN a `Zienswijzeronde` object whose `decision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Zienswijzerondes" widget lists that ronde

@e2e exclude tests/e2e/spec-coverage/facets-decision-detail.spec.ts only exercises this widget's EMPTY state ("This decision is not a shared body's vaststellingsbesluit for any zienswijzeronde."); this scenario's populated-list assertion (a real Zienswijzeronde linked) is untested — genuine coverage gap tracked as e2e debt.

#### Scenario: A decision is a participant council's raadsbesluit adopting a zienswijze

- GIVEN a `Zienswijze` object whose `decision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Zienswijzen" widget lists that zienswijze
- AND clicking the row navigates to `ZienswijzerondeDetail` for its parent ronde

@e2e exclude tests/e2e/spec-coverage/facets-decision-detail.spec.ts only exercises this widget's EMPTY state ("No zienswijzen adopted this decision as their raadsbesluit yet."); this scenario's populated-list assertion (a real Zienswijze linked and its row navigating to ZienswijzerondeDetail) is untested — genuine coverage gap tracked as e2e debt.

### Requirement: Decision detail surfaces commitments (REQ-DFC-004)

The Decision Detail page MUST render a declarative `object-list` widget listing `toezegging` objects whose `relatedMotion` property equals the current decision's id, linking each row to `ToezeggingDetail`. This widget is separate from the existing `decision-actions` widget (ActionItemsSurface), which projects Deck-board action items rather than griffie commitments.

#### Scenario: A motion produced a commitment

- GIVEN a `Toezegging` object whose `relatedMotion` field is set to Decision D (decisionType `motion`)
- WHEN a user opens Decision D's detail page
- THEN the "Commitments" widget lists that commitment with its deadline and lifecycle status

@e2e exclude exercised by tests/e2e/spec-coverage/facets-decision-detail.spec.ts ("DecisionDetail: commitments facet lists a toezegging linked via relatedMotion" — creates a real toezegging referencing the decision and asserts it renders); that test's own @e2e anchor still targets the pre-archival openspec/changes/decision-facet-composition/... path so this gate does not match it — recorded here rather than reported as a gap.

### Requirement: Decision detail surfaces confidentiality status (REQ-DFC-005)

The Decision Detail page MUST render a read-only declarative `object-list` widget listing `geheimhouding` objects whose `targetDecision` property equals the current decision's id (the case where this decision, or the content it represents, is itself under geheimhouding), showing the resolved ground, the lifecycle state, and the `ratificationDeadline`. This widget MUST NOT offer create/edit actions (`allowCreate: false`) — geheimhouding is imposed through the geheimhoudingenregister's own imposing flow, not from the Decision detail page. Widgets for `Geheimhouding.ratificationDecision` and `Geheimhouding.dissolutionDecision` (this decision acting as another record's confirming or lifting besluit) are explicitly out of scope for this requirement.

#### Scenario: A decision is under active geheimhouding

- GIVEN a `Geheimhouding` object in lifecycle state `opgelegd` whose `targetDecision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Confidentiality" widget shows one row with the geheimhouding's ground, lifecycle state, and ratification deadline
- AND the widget offers no add action

@e2e exclude tests/e2e/spec-coverage/facets-decision-detail.spec.ts only exercises this widget's EMPTY state ("This decision has no confidentiality restriction."); this scenario's populated-list assertion (a real opgelegd Geheimhouding linked, showing ground/lifecycle/deadline, no add action) is untested — genuine coverage gap tracked as e2e debt.

#### Scenario: A decision with no confidentiality restriction

- GIVEN Decision D has no `Geheimhouding` object referencing it as `targetDecision`
- WHEN a user opens Decision D's detail page
- THEN the "Confidentiality" widget renders its configured empty-state text

@e2e exclude exercised by tests/e2e/spec-coverage/facets-decision-detail.spec.ts ("DecisionDetail: consultation, advisory-opinion, zienswijze and confidentiality facets render their real empty states" — asserts "This decision has no confidentiality restriction."); that test's own @e2e anchor still targets the pre-archival openspec/changes/decision-facet-composition/... path so this gate does not match it — recorded here rather than reported as a gap.

## User Stories

1. **Board secretary creating a structured decision**: As a board secretary, I want to create a structured decision proposal with options analysis, risk assessment, and financial impact, so that the board can make well-informed strategic decisions. (Source: intelligence DB #15)

2. **Supervisory board reviewing proposals**: As a supervisory board chair, I want to review strategic proposals with full context and approve or reject them digitally, so that governance oversight is exercised efficiently. (Source: intelligence DB #16)

3. **Secretary recording decisions in real-time**: As a secretary, I want to record decisions in real-time during the MT meeting with a structured format (decision text, type, vote, conditions), so that there is immediate clarity on what was decided. (Source: intelligence DB #89)

4. **Chair circulating written resolution**: As chair, I want to circulate a proposal for written decision to all board members and collect their votes electronically so that urgent decisions can be made between meetings per BW 2:40. (Source: intelligence DB #68)

5. **Member tracking decision implementation**: As chair, I want to track the implementation status of ALV decisions with responsible persons and deadlines so that I can report progress at the next ALV. (Source: intelligence DB #77)

## Acceptance Criteria

- Decisions are stored as OpenRegister objects with `@type` of `schema:ChooseAction`
- State machine enforces valid transitions only (Symfony Workflow Component)
- All transitions are recorded in an immutable audit trail
- Decision list supports search, sort, and filter by status/body/date
- Detail view uses CnDetailPage + CnObjectSidebar with state machine visualization
- OpenRaadsinformatie `Besluit` mapping is available for each decision
