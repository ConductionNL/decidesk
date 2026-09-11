---
status: in-progress
openspec-changes:
  - urgent-decision-procedure
  - unified-decision-templates
status-note: |
  2026-06-13 process-config-v1 — 4/4 requirements built. Process templates are
  OpenRegister `processTemplate` objects (ADR-037 fragment) with a structured
  JSON state machine and default voting rule; a `ProcessTemplateService` does
  CRUD + duplicate + server-side transition-graph validation (rejects
  dangling/unreachable states + unknown guard tokens, fail closed). Five
  built-in templates ship via x-openregister-seeds (association-alv,
  association-board, corporate-board, municipal-council, operational-team) and
  are read-only-but-duplicable. An admin-gated section (Settings.vue ->
  ProcessTemplates.vue + ProcessTemplateEditModal.vue + StateMachineEditor.vue)
  manages them. The assigned template drives the DecisionTransitionGuard /
  WorkflowService policy ADDITIVELY: a `ProcessTemplatePolicyResolver`
  translates the template to the guard policy shape and the guard methods take
  an optional `?array $policyOverride` (null -> the hardcoded default-deny
  domain constants, unchanged); a malformed template reverts to default-deny
  (never fail-open). Voting-round open applies the template's voteThreshold /
  abstentionHandling / tieBreakRule defaults unless the caller overrides them.
  RESIDUE (additive, non-breaking): (1) the state machine is stored as
  structured JSON, not literal Symfony Workflow YAML — a YAML import/export is
  deferred; (2) the editor renders a textual graph summary, not an SVG/visual
  diagram; (3) weighted voting is configurable on the template but the weighted
  TALLY engine is owned by the voting-system spec, not this change; (4) the
  guard override is wired through the meeting->governanceBody link — a decision
  with no meeting and no body field falls back to the domain constants.
---

# Process Configuration Specification

## Purpose

Process configuration enables administrators to define and customize decision-making workflows for different governance contexts. A process template defines the state machine, voting rules, quorum requirements, and procedural rules for a specific type of decision or meeting. The system stores state machines as structured JSON (1:1 convertible to Symfony Workflow YAML) and voting rules as a DMN-inspired rule object. This allows Decidiq to serve municipal councils, corporate boards, associations, and operational teams with their own procedural rules.

**Standards**: Symfony Workflow Component (YAML config), DMN (Decision Model and Notation) for voting rules, Schema.org (`HowTo`, `HowToStep`)
**Feature tier**: V1

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full ProcessTemplate entity definition.

## Requirements

---

### Requirement: Process Template Management

The system MUST support creating, editing, duplicating, listing and deleting
process templates. Each template MUST define a state machine (states and
transitions), default voting rule, quorum requirement and optional
decide-without-vote flag. Templates MUST be stored as OpenRegister objects in the
`decidesk` register using the `processTemplate` schema and MUST be managed through
an admin-gated surface (`#[AuthorizedAdminSetting]`). Built-in templates MUST be
read-only (edit and delete refused) but MUST be duplicable into an editable copy.

#### Scenario: Create a process template for ALV decisions

- GIVEN an administrator on the Decidiq admin process-templates section
- WHEN they create a template "ALV Standard Decision"
- THEN the template MUST persist as a `processTemplate` object with its state set
- AND the template MUST carry a default voting rule (simple-majority)
- AND the template MUST be assignable to a body via the body's `processTemplate`
  identifier

#### Scenario: Duplicate and customize an existing template

- GIVEN an existing process template (built-in or custom)
- WHEN the administrator duplicates it
- THEN the new template MUST be a copy with a fresh slug and `builtIn` cleared
- AND the administrator MUST be able to modify the copy's states, transitions and
  rules independently
- AND the original template MUST remain unchanged

#### Scenario: Built-in templates are read-only

- GIVEN a built-in template (`builtIn: true`)
- WHEN the administrator attempts to edit or delete it
- THEN the system MUST refuse the operation
- AND the administrator MUST still be able to duplicate it

---

### Requirement: State Machine Configuration

The system MUST support defining a per-template state machine as a structured
object: `states[]` (each with a name and optional metadata) and `transitions[]`
(each with `from`, `to`, optional `chairOnly` flag and optional `guards[]`). The
system MUST validate the transition graph server-side on save (fail closed): it
MUST reject a transition referencing a state absent from `states[]` (dangling), a
state with no inbound and no outbound transition that is not the declared
`initialState` (unreachable), and an unrecognized guard token. A body's assigned
template MUST drive the decision/meeting transition guards: when a body has a
template, the template's policy (chair-only transitions, quorum enforcement,
decide-without-vote) MUST be consulted; when no template is assigned, the built-in
hardcoded default-deny domain policy MUST apply unchanged. A malformed template
MUST fall back to the default-deny policy (never fail open).

#### Scenario: Reject an invalid transition graph

- GIVEN an administrator editing a template's state machine
- WHEN they add a transition whose `to` state is not declared in `states[]`
- THEN the server MUST refuse to save the template (HTTP 400)
- AND the validation error MUST identify the dangling state

#### Scenario: Assigned template drives the guard, absent template falls back

@e2e exclude Backend guard-policy resolution with no UI surface; covered by DecisionTransitionGuardTest + ProcessTemplateServiceTest (guard-consults-template-with-fallback).

- GIVEN a decision whose meeting belongs to a body with an assigned template
- WHEN the lifecycle guard evaluates an available transition
- THEN the guard MUST use the template's policy (chair-only, quorum)
- AND GIVEN a decision whose meeting belongs to a body with no template
- THEN the guard MUST use the built-in hardcoded domain policy unchanged

---

### Requirement: Voting Rule Configuration

The system MUST support a per-template default voting rule specifying
`voteThreshold` (simple-majority, qualified-majority-two-thirds,
qualified-majority-three-quarters, unanimous), `abstentionHandling` (exclude or
count) and `tieBreakRule` (rejected, chair-decides, revote) — mirroring the
VotingRound schema enums added by voting-rules-v1. When a voting round is opened
for a motion under a body that has a template, the template's rule defaults MUST
apply unless the caller explicitly overrides them; an explicit caller-supplied
value MUST always take precedence. A missing template MUST leave the built-in
method defaults in place (fail-soft).

#### Scenario: Template voting-rule defaults applied at round-open

@e2e exclude Backend round-open default resolution; covered by VotingServiceTemplateRuleTest + Newman (admin/non-admin auth). API behaviour, not a UI surface.

- GIVEN a body with a template whose default `voteThreshold` is
  `qualified-majority-two-thirds`
- WHEN a voting round is opened for a motion under that body without an explicit
  threshold
- THEN the round MUST be created with `voteThreshold = qualified-majority-two-thirds`
- AND GIVEN the caller supplies an explicit `voteThreshold`
- THEN the caller's value MUST be used instead

---

### Requirement: Built-in Process Templates

The system MUST ship with built-in process templates for common governance
contexts: association ALV, association board, corporate board (BV), municipal
council, and operational team. Built-in templates MUST be seeded via
`x-openregister-seeds` so a fresh install has usable templates immediately. Each
built-in MUST carry `builtIn: true`, a valid state machine and an appropriate
default voting rule for its context.

#### Scenario: Use built-in ALV template without customization

@e2e exclude Built-in seed presence + usability is asserted via Newman (list returns the five built-ins) and the read-only built-in row in process-configuration.spec.ts; no distinct UI surface for "use without customization".

- GIVEN a fresh Decidiq installation
- WHEN the administrator selects the built-in "Association ALV" template for a body
- THEN the template MUST be immediately usable with its seeded states and voting
  rule, without further configuration

### Requirement: Unified DecisionTemplate schema declaration

The system SHALL declare a `DecisionTemplate` schema in the `decidesk`
register (slug `decision-template`) via a
`lib/Settings/register.d/68-unified-decision-templates.json` fragment
(ADR-037 — additive, never editing `decidesk_register.json` or the legacy
fragments in place). The schema SHALL carry every property `ProcessTemplate`
carries today (`name`, `description`, `context`, `builtIn`, `initialState`,
`stateMachine` with `states[]`/`transitions[]`, `votingRule`
`{voteThreshold, abstentionHandling, tieBreakRule}`, `quorumRequired`,
`quorumRule`, `allowDecideWithoutVote`, and `urgencyPolicy`
`{allowedTriggerRoles, minimumNoticeFloorHours, responseDeadlineHours, ratificationRequired, ratifyingBody}`
folded in natively rather than as a bolt-on delta), plus three new
properties: `decisionType` (optional string, one of the `Decision.decisionType`
enum values — `motion`, `amendment`, `resolution`, `contract`,
`contract-renewal`, `report-adoption`, `appointment`, `management-point`,
`policy`, `meeting-outcome`; absent means the template is the generic default
for its `context`, mirroring `GovernanceBody.processTemplate`'s existing
default-template semantics, while a populated value mirrors
`GovernanceBody.additionalTemplates[]`'s existing per-decision-type
semantics), `templateCategory` (optional string — a finer classification
within `decisionType` for domains that need one, e.g. the VvE ALV categories
`discharge`, `annual-accounts`, `reserve-fund-contribution`,
`mjop-adoption`, `authorisation-above-threshold`,
`amendment-internal-regulations`, `other`), and `proposedText` +
`regulationSource` (both optional strings, ported unchanged from
`VveDecisionTemplate`). `context` SHALL use the same enum as
`ProcessTemplate.context` (`association`, `corporate`, `legislative`,
`operations`, `citizen`) and remains required. This schema declaration alone
creates no new consumer — resolution against a `GovernanceBody` or a
`Decision.decisionType` is unchanged and continues to use `ProcessTemplate`
until the consumer-rewrite change lands.

@e2e exclude register-shape assertions with no browser flow of their own. MigrateLegacyTemplatesToDecisionTemplateTest covers the port: ::testRunMigratesProcessTemplateFieldsVerbatim for a generic default and ::testRunMapsVveDecisionTemplateFields for a VvE template. The schema lives in `lib/Settings/register.d/68-unified-decision-templates.json`. The `/decision-templates` pages added since are only opened by every-index-route-resolves.spec.ts; nothing checks the schema's properties in a browser.

#### Scenario: Fragment adds DecisionTemplate without touching existing schemas

- **GIVEN** the register fragment `68-unified-decision-templates.json` is loaded
- **WHEN** the decidesk register imports
- **THEN** the `decision-template` schema exists with `decisionType`,
  `context`, `templateCategory`, `stateMachine`, `votingRule`,
  `quorumRequired`/`quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy`,
  `proposedText`, `regulationSource`, and `checklist[]`
- **AND** the `process-template`, `vve-decision-template`, and
  `modelreglement-preset` schemas are unmodified in shape (only their
  `x-openregister.active` flag changes, per the superseded-schemas
  requirement below)
- **AND** `ProcessTemplateService`, `ProcessTemplatePolicyResolver`, and
  `DecisionTransitionGuard` are byte-for-byte unchanged

#### Scenario: A generic default template has no decisionType

- **GIVEN** a built-in `DecisionTemplate` ported from `ProcessTemplate`
  (e.g. "Municipal Council")
- **WHEN** the template is inspected
- **THEN** `decisionType` is absent, `context=legislative`, and the template
  is understood as the default for any decision under that context — the
  same role `GovernanceBody.processTemplate` plays today

#### Scenario: A specialized template narrows by decisionType and templateCategory

- **GIVEN** a built-in `DecisionTemplate` ported from `VveDecisionTemplate`
  "Decharge bestuur"
- **WHEN** the template is inspected
- **THEN** `context=association`, `decisionType=resolution`,
  `templateCategory=discharge`, `proposedText` carries the besluittekst, and
  `regulationSource` carries `"BW 2:48/2:49"`

### Requirement: Decision template checklist

Every `DecisionTemplate` SHALL support an optional ordered `checklist[]`: an
array of check items, each with `sequence` (1-based integer), `label`
(string, required), `description` (string, optional), and `required`
(boolean, default `true`). An empty or absent `checklist[]` SHALL remain
valid — templates ported from `ProcessTemplate`/`VveDecisionTemplate` carry
no checklist by default, preserving byte-for-byte behavioural parity for
every existing built-in. This requirement declares the checklist
**definition** on the template only; instantiating a per-decision
checklist-progress record (which items are ticked, by whom) is out of scope
for this capability delta — see the proposal's Out of Scope.

@e2e exclude the checklist is a schema definition with no editor of its own. The generic `/decision-templates` pages now exist, but no test adds checklist items through them, so the first scenario is a genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277. The second is covered by MigrateLegacyTemplatesToDecisionTemplateTest::testRunMigratesProcessTemplateFieldsVerbatim, which asserts a ported template's checklist is empty.

#### Scenario: A template declares an ordered checklist

- **GIVEN** an administrator (in a future consumer-rewrite editor) defining a
  `DecisionTemplate` for `decisionType=contract`
- **WHEN** they add checklist items "Legal review complete" (required),
  "Budget holder sign-off" (required), "Communications brief drafted"
  (optional)
- **THEN** the template persists `checklist` as three ordered items with
  `sequence` 1–3 and the stated `required` flags

#### Scenario: A ported built-in template has no checklist

- **GIVEN** the built-in `DecisionTemplate` ported from `ProcessTemplate`
  "Association ALV"
- **WHEN** the template is loaded
- **THEN** `checklist` is absent or empty, and nothing about the template's
  state machine, voting rule, or quorum policy behaviour differs from the
  source `ProcessTemplate` object

### Requirement: Legacy template schemas superseded, non-destructively

`ProcessTemplate` SHALL be marked superseded by `DecisionTemplate`:
`x-openregister.active` SHALL be set to `false` via the
`68-unified-decision-templates.json` fragment's deep-merge (ADR-037), with a
schema `description` note naming `decision-template` as the successor. The
existing `process-template` objects, the `ProcessTemplateService`,
`ProcessTemplatePolicyResolver`, `DecisionTransitionGuard`,
`ProcessTemplateController`, and the admin `ProcessTemplates.vue` surface
SHALL continue to function exactly as before — `active: false` on a schema
governs whether NEW objects may be created against it (an OpenRegister
create-time guard), not whether existing objects remain readable or whether
existing service code keeps working. No PHP or Vue file is edited by this
requirement.

@e2e exclude schema/register-shape assertions (an `x-openregister.active` flag flip and its ADR-037 deep-merge rollback semantics) — no UI surface; "No PHP or Vue file is edited by this requirement" is explicit in this requirement's own text, and the existing admin `ProcessTemplates.vue` surface it describes as unaffected is already exercised by tests/e2e/spec-coverage/process-configuration.spec.ts's pre-existing (covered) scenarios.

#### Scenario: ProcessTemplate is marked inactive but remains fully functional

- **GIVEN** the `68-unified-decision-templates.json` fragment is loaded
- **WHEN** the `process-template` schema is inspected
- **THEN** `x-openregister.active` is `false` and the description names
  `decision-template` as the successor
- **AND** every existing `process-template` object remains readable
- **AND** `ProcessTemplateService::list()`, `::get()`, and
  `::resolvePolicyForBody()` behave identically to before this change

#### Scenario: Rollback restores ProcessTemplate to active

- **GIVEN** the `68-unified-decision-templates.json` fragment is removed
  (rollback)
- **WHEN** the register reloads
- **THEN** `process-template.x-openregister.active` reverts to `true`
  automatically (ADR-037 deep-merge — no separate un-patch step needed)

### Requirement: Live legacy template objects are repaired into DecisionTemplate objects

Because OpenRegister seed import is create-only (new seeds in
`68-unified-decision-templates.json` never touch objects an existing install
already created from `43-process-config-v1.json` / `57-vve-alv-pack.json`),
the system SHALL provide an idempotent repair migration that reads every
live `process-template` and `vve-decision-template` object and creates the
equivalent `decision-template` object, carrying forward every field plus a
provenance marker (`migratedFrom`: the source object's schema slug + UUID).
Re-running the migration on an already-migrated instance SHALL be a no-op
(matched by the provenance marker) and SHALL create no duplicate objects.
The migration SHALL never modify or delete the source `process-template` /
`vve-decision-template` objects.

@e2e exclude migration/repair-step behaviour verified by PHPUnit — tests/Unit/Migration/MigrateLegacyTemplatesToDecisionTemplateTest.php (testRunMigratesProcessTemplateFieldsVerbatim, testRunMapsVveDecisionTemplateFields, testRunSkipsAlreadyMigratedObject, testRunNeverDeletesSourceObjects) directly exercises both scenarios under this requirement; not independently UI-observable.

#### Scenario: A live custom ProcessTemplate is repaired

- **GIVEN** a Decidiq install with an administrator-created custom
  `process-template` object "Waterschap Bestuur" (not a built-in seed)
- **WHEN** the repair migration runs
- **THEN** an equivalent `decision-template` object is created with the same
  `name`, `stateMachine`, `votingRule`, `quorumRequired`/`quorumRule`, and
  `allowDecideWithoutVote`, `builtIn=false`, and `migratedFrom` naming the
  source `process-template` UUID
- **AND** the original `process-template` object is unchanged

#### Scenario: Re-running the migration is a no-op

- **GIVEN** an install where the repair migration has already run once
- **WHEN** the migration runs again
- **THEN** no additional `decision-template` objects are created and the
  existing migrated objects are unchanged

## User Stories

1. **Legal counsel tracking governance code compliance**: As legal counsel, I want to track compliance with each provision of the Corporate Governance Code, so that I can prepare the comply-or-explain statement for the annual report. (Source: intelligence DB #39)

2. **Supervisory board chair managing approval workflow**: As a supervisory board chair, I want a digital workflow for approving major management decisions, so that approvals can be obtained efficiently even outside scheduled meetings. (Source: intelligence DB #25)

3. **Secretary verifying voting requirements**: As secretary, I want to verify that a statute amendment vote meets the required quorum and qualified majority so that the notary can confirm proper adoption. (Source: intelligence DB #59)

## Acceptance Criteria

- Process templates are stored as OpenRegister objects with YAML state machine definitions
- State machines use Symfony Workflow Component YAML format
- Voting rules support simple, qualified, unanimous, and weighted majority
- Abstention handling is configurable (counted or excluded)
- Tie-breaking methods are configurable per template
- Built-in templates ship for ALV, board, council, and operational contexts
- Templates are duplicable for customization
- State machine visualization is available in the admin UI
