---
status: done
---

# citizen-participation Specification

## Purpose
Lets governments run public consultations and participatory budget rounds where citizens can react and vote advisorily on proposals. It manages consultation and budget-round lifecycles, accepts authenticated (and optionally rate-limited anonymous) reaction submissions through a moderation queue, validates budget proposals and tallies citizen votes, and publishes results via OpenRegister's RBAC published-predicate and OpenCatalogi without exposing voter identities or app-local public endpoints.

## Requirements

### Requirement: Consultation lifecycle

The system SHALL manage `PublicConsultation` objects through the lifecycle `draft → open → closed → results-published`. Only staff with governance-body authority (OpenRegister RBAC) SHALL transition a consultation. A consultation SHALL only accept reactions while `status: "open"` and before `submissionDeadline`. A scheduled background job SHALL auto-transition consultations from `open` to `closed` once `submissionDeadline` has passed. The `PublicConsultation` schema's `status` enum SHALL be `draft | open | closed | results-published`; the legacy value `summarised` SHALL be migrated to `results-published` via a declarative schema version bump.

#### Scenario: Staff opens a consultation

- **GIVEN** a staff member with governance-body authority and a consultation in `status: "draft"` with a future `submissionDeadline`
- **WHEN** they transition the consultation to `open`
- **THEN** the consultation accepts reaction submissions and appears in the citizen-facing participation view for that governance body

#### Scenario: Non-staff transition rejected

@e2e exclude API authorization contract — covered by Newman against the transition endpoint, not a UI flow
- **WHEN** an authenticated user without governance-body authority attempts a lifecycle transition
- **THEN** the request is rejected with HTTP 403 by OpenRegister per-object RBAC and the consultation status is unchanged

#### Scenario: Deadline auto-close

@e2e exclude scheduled background job — verified at the PHPUnit layer by invoking the job class directly
- **GIVEN** a consultation with `status: "open"` and `submissionDeadline` in the past
- **WHEN** the scheduled close job runs
- **THEN** the consultation status becomes `closed` and subsequent reaction submissions are rejected

#### Scenario: Legacy enum value migrated

@e2e exclude declarative schema migration — verified by PHPUnit on the register configuration import
- **GIVEN** an existing `PublicConsultation` object with `status: "summarised"`
- **WHEN** the schema version bump migration runs
- **THEN** the object's status reads `results-published` and no other fields change

---

### Requirement: Reaction submission auth posture

The system SHALL accept `ConsultationReaction` submissions on open consultations from authenticated Nextcloud accounts by default. Anonymous submission SHALL be possible ONLY when staff have set `anonymousReactionsAllowed: true` on the consultation, via a single `#[PublicPage]` intake endpoint protected by `#[AnonRateLimit]` and the Nextcloud brute-force throttler. Anonymous reactions SHALL store a pseudonymous token as `submitterId` and SHALL NOT store any contact details or other PII. Authenticated reactions SHALL store the NC UID as `submitterId`. Reaction payloads SHALL be size-capped server-side.

#### Scenario: Authenticated citizen submits a reaction

- **GIVEN** an authenticated user and a consultation with `status: "open"`
- **WHEN** they submit a reaction through the participation view
- **THEN** a `ConsultationReaction` object is created with their NC UID as `submitterId` and `moderationStatus: "pending"`

#### Scenario: Anonymous reaction accepted when enabled

@e2e exclude unauthenticated API intake — covered by Newman (anonymous POST, rate-limit headers), not a logged-in UI flow
- **GIVEN** a consultation with `status: "open"` and `anonymousReactionsAllowed: true`
- **WHEN** an unauthenticated POST with a reaction body reaches the public intake endpoint
- **THEN** HTTP 201 is returned and a `ConsultationReaction` is created with a pseudonymous `submitterId` and no PII fields

#### Scenario: Anonymous reaction rejected when not enabled

@e2e exclude unauthenticated API intake — covered by Newman
- **GIVEN** a consultation with `anonymousReactionsAllowed: false` (default)
- **WHEN** an unauthenticated POST reaches the public intake endpoint
- **THEN** HTTP 401 is returned and no object is created

#### Scenario: Anonymous intake rate-limited

@e2e exclude rate-limit enforcement — covered by Newman issuing repeated anonymous POSTs
- **WHEN** an unauthenticated client exceeds the configured `#[AnonRateLimit]` budget on the intake endpoint
- **THEN** HTTP 429 is returned and no further reactions are created for that client within the period

#### Scenario: Submission rejected after deadline

- **GIVEN** a consultation whose `submissionDeadline` has passed or whose status is not `open`
- **WHEN** any reaction submission is attempted
- **THEN** the submission is rejected with a static deadline-closed message and no object is created

---

### Requirement: Reaction moderation queue

Every `ConsultationReaction` SHALL enter the moderation queue with `moderationStatus: "pending"`. Staff with governance-body authority SHALL approve or reject reactions; only `approved` reactions SHALL count toward the consultation's derived `submissionCount` and be eligible for publication. Anonymous reactions SHALL ALWAYS require explicit approval (pre-moderation). Authenticated reactions MAY be auto-approved when the consultation's `moderationPolicy` is `"post-moderation"`. Rejection SHALL retain the object (OpenRegister soft-delete conventions, `hardDelete: false`) for audit. A declarative ADR-031 notification rule SHALL notify staff when reactions are pending.

#### Scenario: Moderator approves a reaction

- **GIVEN** a staff member viewing the moderation queue with a pending reaction
- **WHEN** they approve the reaction
- **THEN** `moderationStatus` becomes `approved` and the consultation's `submissionCount` increments by one

#### Scenario: Moderator rejects a reaction

- **GIVEN** a pending reaction in the moderation queue
- **WHEN** a staff member rejects it with a reason
- **THEN** `moderationStatus` becomes `rejected`, the reaction never counts toward `submissionCount`, and the object remains queryable for audit

#### Scenario: Anonymous reaction never auto-approved

@e2e exclude moderation-policy branch on the intake path — PHPUnit on the intake service
- **GIVEN** a consultation with `moderationPolicy: "post-moderation"` and `anonymousReactionsAllowed: true`
- **WHEN** an anonymous reaction is submitted
- **THEN** it is created with `moderationStatus: "pending"` (pre-moderation) while an authenticated reaction on the same consultation is auto-approved

#### Scenario: Pending-reaction notification dispatched

@e2e exclude declarative notification dialect — verified by the notification-dialect gate plus PHPUnit on the rule import
- **WHEN** a reaction enters the queue with `moderationStatus: "pending"`
- **THEN** staff recipients defined in the `ConsultationReaction` `x-openregister-notifications` rule receive an NC notification

---

### Requirement: Participatory budget round lifecycle

The system SHALL manage `ParticipatoryBudget` rounds through the existing lifecycle `draft → submission → voting → tallying → closed`, with `resultsPublished: true` settable only on a `closed` round. Only staff with governance-body authority SHALL transition a round. Phase deadlines (`submissionDeadline`, `votingDeadline`) SHALL be enforced server-side on every intake and vote operation, independent of the stored status.

#### Scenario: Staff opens the submission phase

- **GIVEN** a budget round in `status: "draft"` with deadlines configured
- **WHEN** staff transition it to `submission`
- **THEN** authenticated citizens can submit proposals and the round appears in the participation view

#### Scenario: Phase deadline enforced over stale status

@e2e exclude server-side deadline guard — PHPUnit on the phase-guard service
- **GIVEN** a round with `status: "submission"` whose `submissionDeadline` has passed but which has not yet been transitioned
- **WHEN** a proposal submission is attempted
- **THEN** it is rejected with a deadline-closed message

---

### Requirement: Budget proposal submission and validation

Authenticated citizens SHALL submit `BudgetProposal` objects to a round during its `submission` phase. The system SHALL validate server-side that `requestedAmount` is positive and does not exceed the round's `totalAmount`, and SHALL record the submitter's NC UID. Staff SHALL validate proposals (`status: "submitted"` → `"validated"` or `"rejected"`) before the voting phase; only `validated` proposals SHALL be votable.

#### Scenario: Citizen submits a valid proposal

- **GIVEN** an authenticated citizen and a round in `submission` phase
- **WHEN** they submit a proposal with a `requestedAmount` within `totalAmount`
- **THEN** a `BudgetProposal` is created with `status: "submitted"` and their NC UID as `submitter`

#### Scenario: Oversized proposal rejected

- **WHEN** a proposal is submitted with `requestedAmount` exceeding the round's `totalAmount`
- **THEN** the submission is rejected with a validation error on the `requestedAmount` field and no object is created

#### Scenario: Only validated proposals enter voting

- **GIVEN** a round transitioned to `voting` with one `validated` and one `submitted` proposal
- **WHEN** a citizen opens the voting view
- **THEN** only the `validated` proposal is offered for voting

---

### Requirement: Advisory citizen voting on budget proposals

Authenticated citizens SHALL cast one advisory vote (voor/tegen) per `validated` proposal during the round's `voting` phase, recorded as a `CitizenVote` object. Duplicate votes by the same `voterId` on the same proposal SHALL be rejected. Tallies SHALL be written to `BudgetProposal.votesFor`/`votesAgainst` through the voting-system tally machinery's atomic update path (see the voting-system delta in this change). Voting SHALL close at `votingDeadline`. Individual voter identities SHALL never appear in any published output.

#### Scenario: Citizen votes on a proposal

- **GIVEN** an authenticated citizen and a round in `voting` phase with a validated proposal
- **WHEN** they cast a `voor` vote
- **THEN** a `CitizenVote` is created with `castAt` set and the proposal's `votesFor` increments atomically

#### Scenario: Duplicate vote rejected

- **WHEN** the same citizen attempts a second vote on the same proposal
- **THEN** the vote is rejected with a conflict error and tallies are unchanged

#### Scenario: Vote rejected outside the voting window

@e2e exclude server-side window guard — covered by Newman against the vote endpoint
- **WHEN** a vote is cast after `votingDeadline` or while the round is not in `voting` phase
- **THEN** it is rejected with a static voting-closed message

---

### Requirement: Result publication via OpenCatalogi and the RBAC published-predicate

When staff transition a consultation to `results-published`, or set `resultsPublished: true` on a closed budget round, the system SHALL create a result summary object (consultation: digest of approved reactions plus the staff response; budget: ranked proposals with allocated amounts within `totalAmount` and participation count) and SHALL set `publicatiedatum` on it via OpenRegister (a normal field write). The schema SHALL declare a public-group `authorization.read` rule matching `publicatiedatum <= $now`, so the summary becomes readable through OR's anonymous RBAC published-predicate surface. When OpenCatalogi is installed and a target catalog is configured, the summary SHALL additionally be routed into that catalog as a publication. Moderators MAY publish individual approved reactions by setting `publicatiedatum` per reaction (never blanket); the ConsultationReaction read rule additionally requires `moderationStatus: approved`. The system SHALL NOT serve app-local anonymous pages or unauthenticated read endpoints for participation data. (`@self.published` is deprecated/removed from OpenRegister and SHALL NOT be used.)

#### Scenario: Consultation results published

- **GIVEN** a `closed` consultation with approved reactions and a configured target catalog
- **WHEN** staff transition it to `results-published`
- **THEN** a result summary object exists with `publicatiedatum` set in the past (making it public-group readable) and a publication referencing it exists in the configured OpenCatalogi catalog

#### Scenario: Budget results published with allocation

- **GIVEN** a `closed` budget round with tallied proposals and `resultsPublished` set to true
- **WHEN** the publication step runs
- **THEN** the summary ranks proposals by `votesFor` and marks proposals as funded greedily within `totalAmount`, and the summary carries `publicatiedatum`

#### Scenario: OpenCatalogi absent degrades gracefully

- **GIVEN** OpenCatalogi is not installed
- **WHEN** staff publish results
- **THEN** the summary object still receives `publicatiedatum` (and is anonymously readable via the public-group RBAC rule), the catalog step is skipped, and a staff-visible warning is shown

#### Scenario: No voter identity in published output

@e2e exclude data-shape assertion on the published object — covered by Newman/PHPUnit
- **WHEN** any result summary or published reaction is read through the published-predicate surface
- **THEN** the payload contains no `voterId`, `submitter` NC UID, or other personal identifiers (pseudonymous tokens excluded too)

#### Scenario: No app-local public surface

@e2e exclude negative routing assertion — covered by Newman (unauthenticated requests to app routes)
- **WHEN** an unauthenticated client requests any decidiq route other than the reaction intake endpoint
- **THEN** no participation data is returned (the only anonymous read path is OR/OpenCatalogi's publication surface)

---

### Requirement: Admin configuration of participation rounds

Staff with governance-body authority SHALL create and configure participation rounds through in-app staff views: consultations (title, description, `submissionDeadline`, `anonymousReactionsAllowed`, `moderationPolicy`, related decision/motion) and budget rounds (name, `totalAmount`, `currency`, phase deadlines). The admin settings surface SHALL hold instance defaults (default `moderationPolicy`, default target OpenCatalogi catalog per governance body, anonymous-intake rate-limit budget). All configuration SHALL be stored as OpenRegister objects or app config — no bespoke tables.

#### Scenario: Staff creates a consultation round

- **GIVEN** a staff member with governance-body authority
- **WHEN** they create a consultation via the staff view with a deadline and `anonymousReactionsAllowed` enabled
- **THEN** a `PublicConsultation` object is created in `draft` with the configured fields

#### Scenario: Admin sets instance defaults

- **GIVEN** an admin on the decidiq admin settings page
- **WHEN** they set the default moderation policy and target catalog
- **THEN** newly created rounds pre-fill those defaults while staff can still override per round

---

### Requirement: OpenRegister conventions for participation data

All citizen-participation objects SHALL be stored in the decidesk OpenRegister register using the existing p3 schemas plus `ConsultationReaction`. Authorization SHALL be OpenRegister per-object RBAC (staff via governance-body roles; citizens receive read on open rounds and create on intake types only). Notifications SHALL be declared exclusively via the ADR-031 `x-openregister-notifications` dialect in `decidesk_register.json`. The system SHALL NOT introduce an app-local contact schema; submitter identity is the NC UID (or pseudonymous token), and any contact need is served by the Nextcloud addressbook abstraction.

#### Scenario: Citizen cannot mutate round configuration

@e2e exclude RBAC contract — covered by Newman IDOR suite
- **WHEN** an authenticated non-staff user attempts to update a `PublicConsultation` or `ParticipatoryBudget` object directly via the OR object API
- **THEN** OpenRegister RBAC rejects the write with HTTP 403

#### Scenario: No imperative notification dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- **WHEN** the notification-dialect gate scans the citizen-participation code paths
- **THEN** no imperative object-notification dispatch exists; all participation notifications are declarative rules in `decidesk_register.json`

### Requirement: Consultation family discriminator boundary (ADR-006)

`PublicConsultation` SHALL remain the sole ADR-006-discriminated concept for the public/market-consultation family (`consultationType`: `citizen-participation | market-consultation | tender | idea-box | participatory-budget`). The system SHALL NOT introduce a new `consultationType` value, or any other mechanism, that folds `MemberConsultation` (`member-consultation` / `member-consultation-response`, the internal non-binding achterbanraadpleging) or `ConsultationRequest` (`consultation-request`, the formal WOR art. 25/27 traject) into the `PublicConsultation` schema, UNLESS a future evaluation re-measures field overlap and the authorization/schema.org/structural signals documented in `openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md` (addendum) and finds the earlier evidence no longer holds. `MemberConsultation` and `ConsultationRequest` are each independently exempted from ADR-006's "one schema per concept" rule as genuinely distinct concepts, evidenced by: field-name overlap with `PublicConsultation` of 13% and 10% of their own properties respectively (no pairing exceeding that in any direction), the absence of any public-group `authorization.read` block on either schema (vs. `PublicConsultation`'s WOO/DIWOO public-publication rule), distinct `x-schema-org` types (`AskAction` and `Action` vs. `PublicConsultation`'s `Event`), and — for `ConsultationRequest` specifically — a live structural reference (`constituencyConsultation`) to `MemberConsultation` as a composed step, which presupposes the two remain separate objects.

@e2e exclude ADR-006 addendum review rule: it tells a spec reviewer what evidence a future fold needs, so no runtime test can run it. The data side holds today. Since one-consultation-schema (#1163), advice requests and member polls copy onto `governance-consultation`, never onto `PublicConsultation`: MigrateConsultationsToOneSchemaTest::testAnAdviceRequestBecomesABindingConsultation and ::testAMemberPollKeepsItsAudienceAndIsNotBinding show it. All three scenarios under this requirement are of this kind.

#### Scenario: Spec review cites the addendum instead of re-deriving the boundary

- **GIVEN** a future change proposal suggests folding `MemberConsultation` or `ConsultationRequest` into `PublicConsultation` on the basis of shared "consultation" naming
- **WHEN** the proposal is reviewed against this requirement
- **THEN** the reviewer points to the ADR-006 addendum's evidence table and requires the proposal to either accept the existing exemption or present new measured evidence that overturns it — a naming-similarity argument alone is insufficient

#### Scenario: PublicConsultation absorbs a genuinely public consultation variant

- **GIVEN** a future consultation variant that DOES share `PublicConsultation`'s public-group authorization model and core "ask citizens, collect reactions, publish results" shape
- **WHEN** it is proposed as a new capability
- **THEN** it is added as a new `consultationType` enum value with progressive-disclosure fields on the existing `PublicConsultation` schema, per ADR-006 — never as a new parallel schema

#### Scenario: MemberConsultation and ConsultationRequest keep independent authorization postures

- **GIVEN** `MemberConsultation` and `ConsultationRequest` remain separate schemas per this requirement
- **WHEN** their OpenRegister `authorization` blocks are inspected
- **THEN** neither declares a `public` group read rule — both remain internal/staff-only by the standard OR RBAC default, unlike `PublicConsultation`
