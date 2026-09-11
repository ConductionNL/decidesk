# process-configuration Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [process-templates-as-lifecycle-policies](../../) (this delta)

## Purpose

A process template stops declaring its own state machine and becomes a policy
on the Decision lifecycle OpenRegister already runs. Per action it states who
may take the step and what must be true first. The rules are enforced by one
OpenRegister lifecycle guard on every write, not only on decidiq's own
endpoint. See design.md D1 to D7.

## ADDED Requirements

### Requirement: Transition policies on the Decision lifecycle

A process template SHALL carry an optional `transitionPolicies[]`. Each entry
SHALL name an `action` of the Decision schema's `x-openregister-lifecycle`
map, and MAY set `chairOnly` (boolean, default false) and `guards` (an array
drawn from a declared `items.enum`). A template SHALL NOT declare states,
transitions or an initial state of its own. The transition map SHALL be the
Decision annotation alone.

The server SHALL refuse a template naming an action the live Decision
annotation does not declare (HTTP 400, naming the action). An entry whose
action is unknown at evaluation time SHALL have no effect, so an overlay can
never loosen the map.

The template-wide flags keep their meaning: `quorumRequired` SHALL require
the linked meeting's quorum before `openVoting`, and `allowDecideWithoutVote`
SHALL permit the `decideWithoutVote` action.

#### Scenario: An admin makes deciding without a vote available and it takes effect

- GIVEN a governance body whose template does not allow deciding without a vote
- AND a decision of that body in `deliberating`
- WHEN an administrator opens the template in the admin settings, ticks "Allow deciding without a vote" and saves
- THEN a non-superuser member of `decidiq-administrators` MAY move that decision from `deliberating` to `decided` through OpenRegister's object API
- AND before the save the same write MUST be refused with `lifecycle-guard-denied`
- e2e: `tests/e2e/workflows/decision-lifecycle-policy.spec.ts`

#### Scenario: A body without a usable template gets its domain default

@e2e exclude policy resolution with no UI surface of its own; covered by PHPUnit on the policy resolver (no template, a template that fails to load, an unknown domain)
- GIVEN a decision whose body has no template, or a template that cannot be loaded
- WHEN the lifecycle guard resolves the policy
- THEN it MUST use the domain default for the decision's domain
- AND an unknown domain MUST get the default-deny policy
- AND a template that cannot be loaded MUST NOT yield anything looser than that default

#### Scenario: A template naming an unknown action is refused

@e2e exclude server-side save validation with no UI path to an unknown action (the editor lists only the lifecycle's own actions); covered by PHPUnit on `ProcessTemplateService`
- GIVEN a template payload with `transitionPolicies: [{"action": "ratify"}]`
- AND the Decision lifecycle declares no `ratify` action
- WHEN the template is saved through `/api/process-templates`
- THEN the server MUST answer HTTP 400 naming `ratify`
- AND no template MUST be stored

#### Scenario: The editor lists the lifecycle's own actions

- GIVEN an administrator opening a template in the admin settings
- WHEN the policy editor renders
- THEN it MUST list every action of the Decision lifecycle, in lifecycle order, with its from and to states
- AND it MUST offer no control to add a state or a transition
- e2e: `tests/e2e/spec-coverage/process-configuration.spec.ts`

### Requirement: The guard vocabulary is declared and every token is enforced

The `guards` vocabulary SHALL be declared as `items.enum` on the template
schema. Every token in it SHALL be enforced by the Decision lifecycle guard.
A token nothing enforces SHALL NOT be in the vocabulary.

- `all_amendments_resolved` SHALL refuse the action while any Decision whose
  `amends` names this decision is in `draft`, `proposed`, `deliberating` or
  `voting`.
- `legal_review_complete` SHALL NOT be accepted: no schema records a legal
  review.
- `quorum_met` SHALL NOT be accepted as a token: `quorumRequired` is the
  switch that is enforced.
- `chair_only` SHALL NOT be accepted as a token: `chairOnly` is the flag.

#### Scenario: Opening the vote waits for the amendments

@e2e exclude guard contract; PHPUnit covers allow and deny with an undecided and a decided amendment, and the E2E suite proves the guard runs through OpenRegister's object API in `decision-lifecycle-policy.spec.ts`
- GIVEN a template whose `openVoting` policy lists `all_amendments_resolved`
- AND a motion of that body with one amendment still in `deliberating`
- WHEN anyone moves the motion to `voting`
- THEN the write MUST be refused with `lifecycle-guard-denied` naming the undecided amendment
- AND once the amendment is `decided` the same write MUST succeed

#### Scenario: A retired token is not accepted

@e2e exclude schema enum; covered by PHPUnit over the register declaration
- GIVEN a template payload whose `guards` lists `legal_review_complete`
- WHEN it is validated against the template schema
- THEN it MUST be refused as outside the enum

### Requirement: Stored template graphs are converted to policies

An idempotent repair step SHALL convert every stored `process-template` and
`decision-template` row that still carries `stateMachine`. For each legacy
transition carrying `chairOnly` or guard tokens it SHALL find the Decision
action whose `from` contains the transition's `from` and whose `to` equals its
`to`, and merge into that action's policy: `chairOnly` as the OR of every
contribution, `guards` as the union within the vocabulary. `chair_only` SHALL
set `chairOnly`. `quorum_met` and `legal_review_complete` SHALL be dropped. An
edge with no matching Decision action SHALL be dropped. An absent
`quorumRequired` SHALL become `true`. The row SHALL then be stored without
`stateMachine` and `initialState`.

Every dropped token and edge, and every chair-only edge that widens to an
action with more than one source state, SHALL be logged naming the template.
A row without `stateMachine` SHALL be skipped, so a second run SHALL change
nothing. The step SHALL run without a user session and SHALL NOT depend on
one.

#### Scenario: A council template is converted

@e2e exclude repair-step behaviour; covered by PHPUnit on the repair step with the shipped Municipal Council template as the fixture
- GIVEN a stored template whose `deliberating → voting` edge is chair-only with `quorum_met` and `all_amendments_resolved`, and whose `voting → decided` edge is chair-only
- WHEN the repair step runs
- THEN the row MUST carry `openVoting: {chairOnly: true, guards: [all_amendments_resolved]}` and `decide: {chairOnly: true}`
- AND it MUST carry no `stateMachine` and no `initialState`
- AND the dropped `quorum_met` token MUST be logged naming the template

#### Scenario: Running the conversion twice changes nothing

@e2e exclude repair-step idempotency; covered by PHPUnit running the step twice over the same rows
- GIVEN an instance where the repair step has run
- WHEN it runs again
- THEN no row MUST be written

## MODIFIED Requirements

### Requirement: Process Template Management

The system MUST support creating, editing, duplicating, listing and deleting
process templates. Each template MUST carry its transition policies on the
Decision lifecycle, a default voting rule, a quorum requirement and an
optional decide-without-vote flag. A template MUST NOT carry a state machine
of its own. Templates MUST be stored as OpenRegister objects in the
`decidesk` register using the `processTemplate` schema and MUST be managed
through an admin-gated surface (`#[AuthorizedAdminSetting]`). Built-in
templates MUST be read-only (edit and delete refused) but MUST be duplicable
into an editable copy. A governance body MUST be able to pick any stored
template as its default through the body's template tab.

#### Scenario: Create a process template for ALV decisions

- GIVEN an administrator on the Decidiq admin process-templates section
- WHEN they create a template "ALV Standard Decision"
- THEN the template MUST persist as a `processTemplate` object with its transition policies
- AND the template MUST carry a default voting rule (simple-majority)
- AND the template MUST be assignable to a body via the body's `processTemplate` identifier

#### Scenario: Duplicate and customize an existing template

- GIVEN an existing process template (built-in or custom)
- WHEN the administrator duplicates it
- THEN the new template MUST be a copy with a fresh slug and `builtIn` cleared
- AND the administrator MUST be able to change the copy's policies and rules independently
- AND the original template MUST remain unchanged

#### Scenario: Built-in templates are read-only

- GIVEN a built-in template (`builtIn: true`)
- WHEN the administrator attempts to edit or delete it
- THEN the system MUST refuse the operation
- AND the administrator MUST still be able to duplicate it

#### Scenario: A body picks a stored template

- GIVEN a stored template "Municipal Council"
- WHEN an administrator opens a governance body's template tab
- THEN the default-template choices MUST be the stored templates, by name
- AND saving MUST store that template's identifier on the body's `processTemplate`
- e2e: `tests/e2e/workflows/decision-lifecycle-policy.spec.ts`

### Requirement: Built-in Process Templates

The system MUST ship with built-in process templates for common governance
contexts: association ALV, association board, corporate board (BV), municipal
council, and operational team. Built-in templates MUST be seeded so a fresh
install has usable templates immediately. Each built-in MUST carry
`builtIn: true`, transition policies appropriate to its context and a default
voting rule.

#### Scenario: Use built-in ALV template without customization

@e2e exclude Built-in seed presence and usability is asserted via Newman (the list returns the built-ins) and the read-only built-in row in process-configuration.spec.ts; no distinct UI surface for "use without customization".

- GIVEN a fresh Decidiq installation
- WHEN the administrator selects the built-in "Association ALV" template for a body
- THEN the template MUST be immediately usable with its seeded policies and voting rule, without further configuration

### Requirement: Unified DecisionTemplate schema declaration

The system SHALL declare a `DecisionTemplate` schema in the `decidesk`
register (slug `decision-template`) via a
`lib/Settings/register.d/68-unified-decision-templates.json` fragment
(ADR-037, additive, never editing `decidesk_register.json` or the legacy
fragments in place). The schema SHALL carry every property `ProcessTemplate`
carries (`name`, `description`, `context`, `builtIn`, `transitionPolicies[]`,
`votingRule` `{voteThreshold, abstentionHandling, tieBreakRule}`,
`quorumRequired`, `quorumRule`, `allowDecideWithoutVote`, and `urgencyPolicy`
`{allowedTriggerRoles, minimumNoticeFloorHours, responseDeadlineHours, ratificationRequired, ratifyingBody}`
folded in natively rather than as a bolt-on delta), with `transitionPolicies[]`
in the same shape and the same guard vocabulary as on `ProcessTemplate`, plus
three new properties: `decisionType` (optional string, one of the
`Decision.decisionType` enum values: `motion`, `amendment`, `resolution`,
`contract`, `contract-renewal`, `report-adoption`, `appointment`,
`management-point`, `policy`, `meeting-outcome`; absent means the template is
the generic default for its `context`, mirroring
`GovernanceBody.processTemplate`'s existing default-template semantics, while
a populated value mirrors `GovernanceBody.additionalTemplates[]`'s existing
per-decision-type semantics), `templateCategory` (optional string, a finer
classification within `decisionType` for domains that need one, e.g. the VvE
ALV categories `discharge`, `annual-accounts`, `reserve-fund-contribution`,
`mjop-adoption`, `authorisation-above-threshold`,
`amendment-internal-regulations`, `other`), and `proposedText` +
`regulationSource` (both optional strings, ported unchanged from
`VveDecisionTemplate`). `context` SHALL use the same enum as
`ProcessTemplate.context` (`association`, `corporate`, `legislative`,
`operations`, `citizen`) and remains required. This schema declaration alone
creates no new consumer: resolution against a `GovernanceBody` or a
`Decision.decisionType` continues to use `ProcessTemplate` until the
consumer-rewrite change lands. The legacy `initialState` and `stateMachine`
properties MAY stay declared, deprecated and read only by the conversion
repair step, until a follow-up change removes them.

@e2e exclude register-shape assertions with no browser flow of their own. MigrateLegacyTemplatesToDecisionTemplateTest covers the port: ::testRunMigratesProcessTemplateFieldsVerbatim for a generic default and ::testRunMapsVveDecisionTemplateFields for a VvE template. The schema lives in `lib/Settings/register.d/68-unified-decision-templates.json`. The `/decision-templates` pages added since are only opened by every-index-route-resolves.spec.ts; nothing checks the schema's properties in a browser.

#### Scenario: Fragment adds DecisionTemplate without touching existing schemas

- **GIVEN** the register fragment `68-unified-decision-templates.json` is loaded
- **WHEN** the decidesk register imports
- **THEN** the `decision-template` schema exists with `decisionType`,
  `context`, `templateCategory`, `transitionPolicies`, `votingRule`,
  `quorumRequired`/`quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy`,
  `proposedText`, `regulationSource`, and `checklist[]`
- **AND** its `transitionPolicies` declaration is identical in shape and
  guard vocabulary to `process-template`'s

#### Scenario: A generic default template has no decisionType

- **GIVEN** a built-in `DecisionTemplate` ported from `ProcessTemplate`
  (e.g. "Municipal Council")
- **WHEN** the template is inspected
- **THEN** `decisionType` is absent, `context=legislative`, and the template
  is understood as the default for any decision under that context, the
  same role `GovernanceBody.processTemplate` plays today

#### Scenario: A specialized template narrows by decisionType and templateCategory

- **GIVEN** a built-in `DecisionTemplate` ported from `VveDecisionTemplate`
  "Decharge bestuur"
- **WHEN** the template is inspected
- **THEN** `context=association`, `decisionType=resolution`,
  `templateCategory=discharge`, `proposedText` carries the besluittekst, and
  `regulationSource` carries `"BW 2:48/2:49"`

### Requirement: Legacy template schemas superseded, non-destructively

`ProcessTemplate` SHALL be marked superseded by `DecisionTemplate`:
`x-openregister.active` SHALL be set to `false` via the
`68-unified-decision-templates.json` fragment's deep-merge (ADR-037), with a
schema `description` note naming `decision-template` as the successor.
Existing `process-template` objects SHALL remain readable, and every consumer
that resolves a body's template (`ProcessTemplateService`, the Decision
lifecycle guard's policy resolution, `ProcessTemplateController` and the
admin `ProcessTemplates.vue` surface) SHALL keep reading `process-template`
until the consumer-rewrite change repoints it. `active: false` on a schema
governs whether NEW objects may be created against it (an OpenRegister
create-time guard), not whether existing objects remain readable.

@e2e exclude schema/register-shape assertions (an `x-openregister.active` flag flip and its ADR-037 deep-merge rollback semantics); no UI surface, and the admin `ProcessTemplates.vue` surface this requirement names is exercised by tests/e2e/spec-coverage/process-configuration.spec.ts.

#### Scenario: ProcessTemplate is marked inactive but remains fully functional

- **GIVEN** the `68-unified-decision-templates.json` fragment is loaded
- **WHEN** the `process-template` schema is inspected
- **THEN** `x-openregister.active` is `false` and the description names
  `decision-template` as the successor
- **AND** every existing `process-template` object remains readable
- **AND** `ProcessTemplateService::list()`, `::get()` and
  `::resolvePolicyForBody()` still read `process-template`

#### Scenario: Rollback restores ProcessTemplate to active

- **GIVEN** the `68-unified-decision-templates.json` fragment is removed
  (rollback)
- **WHEN** the register reloads
- **THEN** `process-template.x-openregister.active` reverts to `true`
  automatically (ADR-037 deep-merge, no separate un-patch step needed)

### Requirement: Live legacy template objects are repaired into DecisionTemplate objects

Because OpenRegister seed import is create-only (new seeds in
`68-unified-decision-templates.json` never touch objects an existing install
already created from `43-process-config-v1.json` / `57-vve-alv-pack.json`),
the system SHALL provide an idempotent repair migration that reads every
live `process-template` and `vve-decision-template` object and creates the
equivalent `decision-template` object, carrying forward every field
(including `transitionPolicies`, and `stateMachine`/`initialState` on a row
the policy conversion has not reached yet) plus a provenance marker
(`migratedFrom`: the source object's schema slug + UUID). Re-running the
migration on an already-migrated instance SHALL be a no-op (matched by the
provenance marker) and SHALL create no duplicate objects. The migration SHALL
never modify or delete the source `process-template` /
`vve-decision-template` objects.

@e2e exclude migration/repair-step behaviour verified by PHPUnit: tests/Unit/Migration/MigrateLegacyTemplatesToDecisionTemplateTest.php exercises both scenarios under this requirement; not independently UI-observable.

#### Scenario: A live custom ProcessTemplate is repaired

- **GIVEN** a Decidiq install with an administrator-created custom
  `process-template` object "Waterschap Bestuur" (not a built-in seed)
- **WHEN** the repair migration runs
- **THEN** an equivalent `decision-template` object is created with the same
  `name`, `transitionPolicies`, `votingRule`, `quorumRequired`/`quorumRule`,
  and `allowDecideWithoutVote`, `builtIn=false`, and `migratedFrom` naming the
  source `process-template` UUID
- **AND** the original `process-template` object is unchanged

#### Scenario: Re-running the migration is a no-op

- **GIVEN** an install where the repair migration has already run once
- **WHEN** the migration runs again
- **THEN** no additional `decision-template` objects are created and the
  existing migrated objects are unchanged

## REMOVED Requirements

### Requirement: State Machine Configuration

**Reason:** A template's own state machine was never walked. Only its
chair-only edges and two flags reached the guard, and three of its four guard
tokens were validated and enforced nowhere. The Decision lifecycle already has
one map, declared on the schema and run by OpenRegister (ADR-022, ADR-031).
Validating a second, decorative graph is the app-local state machine gate 23
rejects.

**Migration:** Stored graphs are converted into transition policies by the
repair step in "Stored template graphs are converted to policies". The
graph-validation endpoint `POST /api/process-templates/validate` and
`StateMachineValidator` are removed. The policy behaviour the old requirement
described (chair-only, quorum, decide-without-vote, template over domain
default, fail-safe fallback) continues under "Transition policies on the
Decision lifecycle" and the decision-management delta.
