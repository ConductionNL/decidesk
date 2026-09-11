# portal-citizen-create-actions Specification

## Purpose
Lets a citizen react to an open consultation and propose a budget item through portaliq. This app declares the two create actions in its portal manifest. The server stamps every scope and lifecycle field, so a citizen can only create rows in their own name, at the intake state.
## Requirements
### Requirement: Citizen reacts to an open consultation via a scope-stamped create action (REQ-DKPCA-001)

`OCA\Decidiq\Portal\PortalContributionProvider`'s `citizen` manifest MUST
declare a `type: create` action `createReaction` against the
`consultation-reaction` (`ConsultationReaction`) schema with a client-writable
field whitelist of exactly `{consultation, body}` and a server-stamped `set` of
`{submitterId: <subjectRef>, moderationStatus: 'pending', submittedAt: <now>}`.
The owner/scope field `submitterId` MUST be stamped from the verified
`subjectRef` and MUST NOT be client-writable; `moderationStatus` MUST be stamped
to the intake state `pending` and MUST NOT be client-writable. The action MUST
be constrained to a parent `PublicConsultation` whose `status` is `open` (a
declared parent constraint or a server guard), and MUST fail closed when the
parent is not open. `minTrust` MUST be `low`.

#### Scenario: A citizen reacts to an open consultation

- GIVEN a resolved `citizen` subject and an open (`status: open`) `PublicConsultation`
- WHEN the citizen submits `createReaction` with `{consultation, body}`
- THEN a `ConsultationReaction` is created with `submitterId` stamped from `subjectRef`, `moderationStatus: 'pending'` and `submittedAt` stamped server-side
- AND a client-supplied `submitterId`, `moderationStatus`, `moderationReason` or `publicatiedatum` is ignored (not in the whitelist)
- AND a `createReaction` against a consultation whose `status` is not `open` fails closed
- @e2e exclude decidiq only declares this action in its portal manifest; portaliq renders the citizen form, so decidiq has no page to drive. PortalContributionProviderTest::testCreateReactionActionShape pins the whitelist and the stamped set, and PortalCreateOpenParentGuardListenerTest::testReactionOnClosedConsultationRejected pins the fail-closed parent guard. The browser flow is tracked in ConductionNL/decidiq#1277.

### Requirement: Citizen proposes a budget item into an open participatory budget via a scope-stamped create action (REQ-DKPCA-002)

The `citizen` manifest MUST declare a `type: create` action
`createBudgetProposal` against the `budget-proposal` (`BudgetProposal`) schema
with a client-writable field whitelist of exactly `{participatoryBudget, title,
description, requestedAmount, category}` and a server-stamped `set` of
`{submitter: <subjectRef>, status: 'submitted'}`. The owner/scope field
`submitter` MUST be stamped from the verified `subjectRef` and MUST NOT be
client-writable; `status` MUST be stamped to the intake state `submitted` and
MUST NOT be client-writable. Because `BudgetProposal` has no `submittedAt`
property, no such field is stamped. The action MUST be constrained to a parent
`ParticipatoryBudget` whose `status` is `submission` (open for submissions) and
MUST fail closed otherwise. `minTrust` MUST be `low`.

#### Scenario: A citizen submits a budget proposal into an open round

- GIVEN a resolved `citizen` subject and a `ParticipatoryBudget` in `status: submission`
- WHEN the citizen submits `createBudgetProposal` with `{participatoryBudget, title, description, requestedAmount, category}`
- THEN a `BudgetProposal` is created with `submitter` stamped from `subjectRef` and `status: 'submitted'`
- AND a client-supplied `submitter`, `status`, `votesFor` or `votesAgainst` is ignored (not in the whitelist)
- AND a `createBudgetProposal` into a participatory budget whose `status` is not `submission` fails closed
- @e2e exclude decidiq only declares this action in its portal manifest; portaliq renders the citizen form, so decidiq has no page to drive. PortalContributionProviderTest::testCreateBudgetProposalActionShape pins the whitelist and the stamped set, and PortalCreateOpenParentGuardListenerTest::testBudgetProposalOnDraftRoundRejected pins the fail-closed parent guard. The browser flow is tracked in ConductionNL/decidiq#1277.

### Requirement: Scope and lifecycle are server-owned, closing write-IDOR (REQ-DKPCA-003)

For both create actions the manifest MUST place every scope, ownership and
lifecycle field (`submitterId` / `submitter`, `moderationStatus` / `status`) in
the server-stamped `set`, NEVER in the client-writable `fields` whitelist, so
that a client-supplied value for any of them is ignored and a citizen can only
ever create a row scoped to their own `subjectRef` at the server-chosen intake
state (portaliq#16 write-IDOR closed). The client whitelist MUST contain only
content and parent-reference fields. No staff-only field (`moderationReason`,
`publicatiedatum`, `depublicatiedatum`, `voteCount`, `votesFor`, `votesAgainst`)
MUST appear in either whitelist.

#### Scenario: A forged scope or status is ignored

- GIVEN a `createReaction` or `createBudgetProposal` whose body ALSO carries a `submitterId`/`submitter` naming a DIFFERENT subject and a `moderationStatus`/`status` naming an approved/awarded state
- WHEN the create is processed
- THEN the persisted row's scope is stamped from the verified `subjectRef` and its lifecycle field is the server intake state; the forged scope and status are ignored
- AND no staff-only field is writable through either action's whitelist
- @e2e exclude a manifest property with no decidiq page: PortalContributionProviderTest::testScopeAndStaffFieldsAreNeverClientWhitelisted pins that no scope, lifecycle or staff-only field is client-writable. A forged create sent through portaliq is tracked in ConductionNL/decidiq#1277.

### Requirement: Creates are account-less (low trust) and the provider declares exactly these two actions (REQ-DKPCA-004)

Both create actions MUST be declared at `minTrust: low` (account-less
participation is the design intent; DigiD/eHerkenning stays deferred, matching
the read collections). `getContribution($subject)` for `audience: 'citizen'`
MUST return a manifest whose `actions` contains exactly `createReaction` and
`createBudgetProposal` and no others; no other audience exists and no read
collection gains a write action. The provider MUST stay a plain,
dependency-free class (no portaliq import, no `implements`, no constructor). A
per-subject create rate-limit is required at apply (noted in tasks) to blunt
spam given the low trust gate.

#### Scenario: The citizen manifest declares exactly the two low-trust creates

- GIVEN a constructed `PortalContributionProvider` and a subject with `audience: 'citizen'`
- WHEN `getContribution($subject)` is called
- THEN the manifest's `actions` contains exactly `createReaction` and `createBudgetProposal`, both `type: create`, both `minTrust: low`
- AND the four read/inbox collections keep no write action, and a non-`citizen` audience still returns null
- @e2e exclude a provider contract with no browser surface: PortalContributionProviderTest::testCitizenManifestDeclaresExactlyTheTwoCreateActions and ::testUnknownAudienceYieldsNull pin it. Tracked in ConductionNL/decidiq#1277 with the other portal create actions.

