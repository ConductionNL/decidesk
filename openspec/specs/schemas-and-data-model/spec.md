---
status: done
---

# schemas-and-data-model Specification

## Purpose
Defines the decidesk OpenRegister register and its full set of schemas — governance bodies, meetings, agenda items, motions, amendments, voting rounds, votes, decisions, action items, minutes, participants, documents, and the commercial schemas (monetary amount, offer, order, product, report). It specifies each schema's required fields and enumerated values, their relations, the install-time register import and idempotent seed data, email-to-decision linking via _mail metadata, and decision publication flags for ORI integration, providing the data foundation all other decidiq capabilities build on.

## Requirements

### Requirement: REQ-SDM-001 Register definition exists

The system SHALL provide a `decidesk` OpenRegister register defined in `lib/Settings/decidesk_register.json` following OpenAPI 3.0.0 format with `x-openregister` extensions.

@e2e exclude infra plumbing (register-file existence, RepairStep import) with no UI surface — verified by tests/Unit/RegisterJsonTest.php::testRegisterIsValidOpenApi/testAllSchemasExist and the app's own install flow, not a browser interaction.

#### Scenario: Register file is present and valid

- **WHEN** the Decidiq app is installed on a Nextcloud instance with OpenRegister active
- **THEN** the file `lib/Settings/decidesk_register.json` exists and is valid OpenAPI 3.0.0 JSON

#### Scenario: RepairStep imports register on install

- **WHEN** Nextcloud runs the Decidiq repair step (`RepairStep.php`)
- **THEN** `ConfigurationService::importFromApp('decidesk')` is called and the `decidesk` register appears in OpenRegister

---

### Requirement: REQ-SDM-002 GovernanceBody schema

The system SHALL define a `GovernanceBody` schema (type `schema:Organization`) with the following required properties: `name`, `bodyType`, `domain`. The `bodyType` field SHALL accept values: `legislative`, `association`, `corporate-board`, `operational`, `citizen-panel`.

@e2e exclude schema/register-shape assertion (bodyType enum accept/reject at the OpenRegister API layer) — no UI surface; enforced structurally by OpenRegister's own JSON-schema validation, covered by tests/Unit/RegisterJsonTest.php::testGovernanceBodySchema.

#### Scenario: GovernanceBody created with valid bodyType

- **WHEN** a GovernanceBody object is submitted with `bodyType: "legislative"` and required fields
- **THEN** OpenRegister accepts the object and returns HTTP 201

#### Scenario: GovernanceBody rejected with invalid bodyType

- **WHEN** a GovernanceBody object is submitted with `bodyType: "unknown"`
- **THEN** OpenRegister returns HTTP 400 with a validation error on the `bodyType` field

---

### Requirement: REQ-SDM-003 Meeting schema

The system SHALL define a `Meeting` schema (type `schema:Event`) with required properties: `title`, `meetingType`, `scheduledDate`, `meetingMode`, `lifecycle`. The `lifecycle` field SHALL accept values: `draft`, `scheduled`, `opened`, `paused`, `adjourned`, `closed`.

@e2e exclude schema/register-shape assertion (object storage + relation resolution at the API layer) — no distinct UI surface beyond the existing meeting-creation e2e coverage in tests/e2e/spec-coverage/meeting-management.spec.ts; the lifecycle enum itself is covered by tests/Unit/RegisterJsonTest.php::testMeetingLifecycleEnum.

#### Scenario: Meeting created in draft lifecycle

- **WHEN** a Meeting object is submitted with `lifecycle: "draft"` and all required fields
- **THEN** the object is stored and retrievable via the OpenRegister REST API

#### Scenario: Meeting linked to GovernanceBody

- **WHEN** a Meeting object includes an OpenRegister relation to a GovernanceBody
- **THEN** the relation is stored and accessible via the relations endpoint

---

### Requirement: REQ-SDM-004 AgendaItem schema

The system SHALL define an `AgendaItem` schema (type `custom:AgendaItem`) with required properties: `title`, `itemType`, `orderNumber`. The `itemType` field SHALL accept values: `informational`, `discussion`, `decision`.

@e2e exclude schema/register-shape assertion (field storage + relation resolution at the API layer) — no UI surface distinct from the existing agenda-management e2e coverage in tests/e2e/spec-coverage/agenda-management.spec.ts; enforced structurally by the schema declaration.

#### Scenario: AgendaItem created with orderNumber

- **WHEN** an AgendaItem object is submitted with `orderNumber: 3`, `itemType: "decision"`, and `title`
- **THEN** the object is stored with the exact orderNumber value

#### Scenario: AgendaItem linked to Meeting

- **WHEN** an AgendaItem includes an OpenRegister relation to a Meeting
- **THEN** the relation is resolvable and the Meeting object is returned when the relation is expanded

---

### Requirement: REQ-SDM-005 Motion schema

The system SHALL define a `Motion` schema (type `custom:Motion`) with required properties: `title`, `text`, `motionType`, `proposer`, `lifecycle`, `submittedAt`. The `lifecycle` field SHALL accept values: `submitted`, `debating`, `voting`, `adopted`, `rejected`, `withdrawn`.

@e2e exclude schema/register-shape assertion (object storage + array-field round-trip at the API layer) — no UI surface with no distinct assertion beyond existing motion e2e coverage; enforced structurally by the schema declaration.

#### Scenario: Motion submitted successfully

- **WHEN** a Motion object is submitted with all required fields and `lifecycle: "submitted"`
- **THEN** OpenRegister stores the object and returns HTTP 201

#### Scenario: Motion co-signers stored as array

- **WHEN** a Motion object includes `coSigners: ["Jan Smit", "Lisa de Jong"]`
- **THEN** the `coSigners` field is stored as a JSON array and retrievable intact

---

### Requirement: REQ-SDM-006 Amendment schema

The system SHALL define an `Amendment` schema (type `custom:Amendment`) with required properties: `title`, `text`, `proposer`, `lifecycle`, `submittedAt`. The `lifecycle` field SHALL accept values: `submitted`, `debating`, `voting`, `adopted`, `rejected`. Amendments SHALL relate to a Motion.

@e2e exclude schema/register-shape assertion (relation resolution at the API layer) — no UI surface distinct from the existing amendment e2e coverage in tests/e2e/spec-coverage/motion-amendment.spec.ts; enforced structurally by the schema's relation declaration.

#### Scenario: Amendment linked to Motion

- **WHEN** an Amendment object includes an OpenRegister relation to a Motion
- **THEN** the relation is stored and the Amendment appears in the Motion's related objects

---

### Requirement: REQ-SDM-007 VotingRound schema

The system SHALL define a `VotingRound` schema (type `custom:VotingRound`) with required properties: `votingMethod`, `isSecret`. The `votingMethod` field SHALL accept values: `for-against-abstain`, `ranked-choice`, `weighted`, `show-of-hands`.

@e2e exclude schema/register-shape assertion (boolean/integer field round-trip at the API layer) — no UI surface distinct from existing voting e2e coverage; covered structurally by tests/Unit/RegisterJsonTest.php::testVotingRoundSchema.

#### Scenario: VotingRound created with secret ballot

- **WHEN** a VotingRound is created with `isSecret: true` and `votingMethod: "for-against-abstain"`
- **THEN** the object is stored and `isSecret` is preserved as boolean `true`

#### Scenario: VotingRound result recorded

- **WHEN** a VotingRound is updated with `result: "adopted"`, `votesFor: 28`, `votesAgainst: 3`, `votesAbstain: 2`
- **THEN** all count fields are stored as integers and the `result` enum is accepted

---

### Requirement: REQ-SDM-008 Vote schema

The system SHALL define a `Vote` schema (type `custom:Vote`) with required properties: `value`, `castAt`. The `value` field SHALL accept: `for`, `against`, `abstain`. Votes SHALL relate to a VotingRound and a Participant.

@e2e exclude schema/register-shape assertion (relation resolution + boolean-field round-trip at the API layer) — no UI surface distinct from existing voting e2e coverage; enforced structurally by the schema declaration.

#### Scenario: Vote cast in a VotingRound

- **WHEN** a Vote is created with `value: "for"`, `castAt` timestamp, and relations to a VotingRound and Participant
- **THEN** the Vote is stored and both relations are resolvable

#### Scenario: Proxy vote recorded

- **WHEN** a Vote is created with `isProxy: true`
- **THEN** the `isProxy` boolean is stored as `true`

---

### Requirement: REQ-SDM-009 Decision schema

The system SHALL define a `Decision` schema (type `custom:Decision`) with required properties: `title`, `text`, `decisionDate`, `outcome`. The `outcome` field SHALL accept: `adopted`, `rejected`. Decisions SHALL relate to a Motion and have one-to-many relation to ActionItems.

@e2e exclude schema/register-shape assertion (object storage + field round-trip at the API layer) — no UI surface distinct from the existing decision e2e coverage in tests/e2e/spec-coverage/decision-management.spec.ts; covered structurally by tests/Unit/RegisterJsonTest.php::testDecisionSupertypeSchema.

#### Scenario: Decision created with outcome adopted

- **WHEN** a Decision object is submitted with `outcome: "adopted"` and all required fields
- **THEN** OpenRegister stores the Decision and returns HTTP 201

#### Scenario: Decision published via ORI flag

- **WHEN** a Decision is updated with `isPublished: true` and `publishedAt` timestamp
- **THEN** both fields are stored and the Decision is queryable by `isPublished`

---

### Requirement: REQ-SDM-010 ActionItem schema

The system SHALL define an `ActionItem` schema (type `custom:ActionItem`) with required properties: `title`, `taskStatus`. The `taskStatus` field SHALL accept: `open`, `in-progress`, `completed`, `overdue`. ActionItems SHALL relate to a Decision and a Meeting.

@e2e exclude schema/register-shape assertion (field round-trip at the API layer) — no UI surface distinct from the existing action-item e2e coverage in tests/e2e/spec-coverage/action-items-page.spec.ts; enforced structurally by the schema declaration.

#### Scenario: ActionItem created as open task

- **WHEN** an ActionItem is created with `taskStatus: "open"`, `title`, and an assignee
- **THEN** the object is stored and retrievable

#### Scenario: ActionItem marked completed

- **WHEN** an ActionItem is updated with `taskStatus: "completed"` and `completedAt` timestamp
- **THEN** both fields are stored correctly

---

### Requirement: REQ-SDM-011 Minutes schema

The system SHALL define a `Minutes` schema (type `custom:Minutes`) with required properties: `title`, `lifecycle`. The `lifecycle` field SHALL accept: `draft`, `review`, `approved`, `signed`, `published`. Minutes SHALL have a one-to-one relation to a Meeting.

@e2e exclude schema/register-shape assertion (relation resolution + array-field round-trip at the API layer) — no UI surface distinct from the existing minutes e2e coverage in tests/e2e/spec-coverage/minutes-page.spec.ts and tests/e2e/spec-coverage/resolution-minutes.spec.ts; enforced structurally by the schema declaration.

#### Scenario: Minutes linked to Meeting

- **WHEN** a Minutes object is created with a relation to a Meeting
- **THEN** the relation is stored and the Minutes is retrievable via the Meeting's related objects

#### Scenario: Minutes signed with multiple signatories

- **WHEN** Minutes are updated with `signedBy: ["Roos de Vries", "Jan Bakker"]` and `lifecycle: "signed"`
- **THEN** the `signedBy` array is stored intact

---

### Requirement: REQ-SDM-012 Participant schema

The system SHALL define a `Participant` schema (type `schema:Person`) with required properties: `displayName`, `role`. The `role` field SHALL accept: `chair`, `vice-chair`, `secretary`, `member`, `observer`, `guest`. Participants SHALL relate to a GovernanceBody.

@e2e exclude schema/register-shape assertion (field round-trip at the API layer) — the `Participant` schema is a deprecated shim (see `participant-crud`); no UI surface distinct from existing participant e2e coverage; the role enum is covered by tests/Unit/RegisterJsonTest.php::testParticipantRoleEnum.

#### Scenario: Participant with voting weight created

- **WHEN** a Participant is created with `role: "member"` and `votingWeight: 1`
- **THEN** the object is stored with the correct weight value

#### Scenario: Participant departure recorded

- **WHEN** a Participant is updated with a `leftAt` timestamp
- **THEN** the field is stored and the Participant can be filtered by `leftAt` presence

---

### Requirement: REQ-SDM-013 DigitalDocument schema

The system SHALL define a `DigitalDocument` schema (type `schema:DigitalDocument`) with required properties: `name`, `documentType`.

@e2e exclude register-shape assertion: `DigitalDocument` has no page in `src/manifest.json`. RegisterJsonTest::testAllSchemasExist and ::testSchemaOrgTypeAnnotations pin the schema and its `schema:DigitalDocument` type. No test pins its required fields or the field round-trip, which is OpenRegister's generic object API.

#### Scenario: DigitalDocument created with MIME type

- **WHEN** a DigitalDocument is created with `encodingFormat: "application/pdf"`
- **THEN** the field is stored as a string

---

### Requirement: REQ-SDM-014 MonetaryAmount schema

The system SHALL define a `MonetaryAmount` schema (type `schema:MonetaryAmount`) with required properties: `value`, `currency`. The `currency` field SHALL be an ISO 4217 code.

@e2e exclude register-shape assertion: `MonetaryAmount` has no page in `src/manifest.json`. RegisterJsonTest::testAllSchemasExist and ::testSchemaOrgTypeAnnotations pin the schema and its `schema:MonetaryAmount` type. No test pins its required fields or the field round-trip, which is OpenRegister's generic object API.

#### Scenario: MonetaryAmount stored with currency

- **WHEN** a MonetaryAmount is created with `value: 50000`, `currency: "EUR"`
- **THEN** both fields are stored and retrievable

---

### Requirement: REQ-SDM-015 Offer schema

The system SHALL define an `Offer` schema (type `schema:Offer`) with required properties: `name`, `price`, `priceCurrency`.

@e2e exclude register-shape assertion: `Offer` has no page in `src/manifest.json`. RegisterJsonTest::testAllSchemasExist and ::testSchemaOrgTypeAnnotations pin the schema and its `schema:Offer` type. No test pins its required fields or the field round-trip, which is OpenRegister's generic object API.

#### Scenario: Offer with validity period

- **WHEN** an Offer is created with `validFrom` and `validThrough` datetime values
- **THEN** both fields are stored as ISO 8601 datetimes

---

### Requirement: REQ-SDM-016 Order schema

The system SHALL define an `Order` schema (type `schema:Order`) with required properties: `orderNumber`, `orderDate`, `orderStatus`, `totalPrice`, `currency`.

@e2e exclude register-shape assertion: `Order` has no page in `src/manifest.json`. RegisterJsonTest::testAllSchemasExist and ::testSchemaOrgTypeAnnotations pin the schema and its `schema:Order` type. No test pins its required fields or the field round-trip, which is OpenRegister's generic object API.

#### Scenario: Order created with payment terms

- **WHEN** an Order is created with `paymentTerms: "NET30"`
- **THEN** the field is stored as a string

---

### Requirement: REQ-SDM-017 Product schema

The system SHALL define a `Product` schema (type `schema:Product`) with required properties: `name`, `unitPrice`, `currency`.

@e2e exclude the `Product` schema is no longer in this register: it moved to pipelinq in #1076, and RegisterJsonTest::testAllSchemasExist pins the 38-schema register without it. This requirement is stale and needs a spec decision, tracked in ConductionNL/decidiq#1277.

#### Scenario: Product with tax rate

- **WHEN** a Product is created with `taxRate: 21`
- **THEN** the field is stored as a number

---

### Requirement: REQ-SDM-018 Report schema

The system SHALL define a `Report` schema (type `schema:Report`) with required properties: `name`, `reportType`.

@e2e exclude register-shape assertion: `Report` has no page in `src/manifest.json`. RegisterJsonTest::testAllSchemasExist and ::testSchemaOrgTypeAnnotations pin the schema and its `schema:Report` type. No test pins its required fields or the field round-trip, which is OpenRegister's generic object API.

#### Scenario: Report with period

- **WHEN** a Report is created with `period: "2025-Q1"`
- **THEN** the field is stored as a string

---

### Requirement: REQ-SDM-019 Seed data loaded on install

The system SHALL load 3–5 seed objects per schema into the `decidesk` register when the RepairStep runs, using the `@self` envelope with Dutch organisational values.

@e2e exclude install-time repair-step behaviour verified by tests/Unit/RegisterJsonTest.php::testSeedDataPresent; not independently UI-observable — the e2e suite consumes this seed data as fixtures rather than asserting the import step itself.

#### Scenario: Seed data present after install

- **WHEN** the RepairStep completes successfully
- **THEN** at least 3 GovernanceBody objects, 3 Meeting objects, and 3 Motion objects exist in the `decidesk` register

#### Scenario: Seed data import is idempotent

- **WHEN** the RepairStep runs a second time (e.g., after upgrade)
- **THEN** no duplicate seed objects are created (upsert by slug)

---

### Requirement: REQ-SDM-020 Email-to-decision linking via _mail metadata

The system SHALL support linking incoming emails to Decision objects using OpenRegister's `_mail` metadata column, so that related correspondence is part of the decision dossier.

@e2e exclude Nextcloud Mail cross-app integration plumbing (`_mail` metadata column) — schema enablement is covered by tests/Unit/RegisterJsonTest.php::testDecisionMailEnabled; exercising the Mail sidebar itself requires a Mail-app fixture the decidiq e2e suite does not provision. See `email-linking-via-email-leaf` for the dedicated capability spec.

#### Scenario: Email linked to decision by reference number

- **WHEN** an email arrives mentioning a decision reference number
- **THEN** the email appears linked to the corresponding Decision object in OpenRegister AND is visible in the Nextcloud Mail sidebar for that Decision

#### Scenario: Linked email visible in decision dossier

- **WHEN** a user opens a Decision object in Decidiq
- **THEN** all linked emails are shown in the related mail section via `_mail` metadata

---

### Requirement: REQ-SDM-021 Resolution register supports ORI publication

The system SHALL support marking Decisions as published (`isPublished: true`) and recording the publication timestamp (`publishedAt`), enabling integration with the ORI open data API.

@e2e exclude API-filter contract (OpenRegister REST query by `isPublished`), not a UI flow — the decision-management publication UI itself (`publish-action-visible-only-when-eligible`, `publication-events-in-the-audit-trail`) is already tagged and covered by tests/e2e/workflows/publication-workflow.spec.ts.

#### Scenario: Decision marked as published

- **WHEN** a Decision is updated with `isPublished: true`
- **THEN** the Decision is filterable by `isPublished=true` via the OpenRegister REST API

#### Scenario: Unpublished decisions excluded from public listing

- **WHEN** a public ORI API consumer queries Decisions
- **THEN** only Decisions with `isPublished: true` are returned

### Requirement: REQ-SDM-022 Decision declares its meeting and agendaItem joins

The `decision` schema SHALL declare optional `meeting` ($ref `Meeting`, `facetable: true`) and `agendaItem` ($ref `AgendaItem`, `facetable: true`) properties. Neither is required — a `decision` MAY exist without either (e.g. a citizen-panel or nomination decision created outside a meeting flow).

@e2e exclude schema/register-shape assertion (property validation + facetable filtering at the OpenRegister API layer) — the tab-creation UI flows these scenarios name (`MeetingDecisionsTab.vue`, `AgendaMotionsTab.vue`) are already exercised end-to-end and tagged as `create-a-decision-from-a-meeting-agenda-item` in tests/e2e/spec-coverage/decision-management.spec.ts; only the schema-validation/facetable assertion itself is untested in a browser.

#### Scenario: Decision created from a meeting tab carries a validated meeting reference

- **GIVEN** the `meeting` property is declared on `decision`
- **WHEN** `MeetingDecisionsTab.vue` creates a decision with `meeting: <meetingUuid>`
- **THEN** OpenRegister validates the property against the `decision` schema instead of silently accepting an undeclared field
- **AND** the decision is facetable/filterable by `meeting`

#### Scenario: Decision created from an agenda item carries a validated agendaItem reference

- **GIVEN** the `agendaItem` property is declared on `decision`
- **WHEN** `AgendaMotionsTab.vue` creates a decision with `agendaItem: <agendaItemUuid>`
- **THEN** OpenRegister validates the property against the `decision` schema
- **AND** the decision is facetable/filterable by `agendaItem`

---

### Requirement: REQ-SDM-023 ConflictOfInterest.boardMember references Membership, not the Participant shim

`ConflictOfInterest.boardMember` SHALL `$ref: Membership` (was `$ref: Participant`). The property name (`boardMember`) is unchanged; only its reference target changes, since a conflict-of-interest declaration is inherently a statement about a person's role/relationship in a specific governance body (`Membership` already carries `independenceStatus`, the exact MCCG-adjacent field a COI declaration is evaluated against) rather than a bare identity.

@e2e exclude migration/repair-step behaviour verified by PHPUnit — tests/Unit/Repair/RepointConflictOfInterestBoardMemberTest.php (testRunRepointsUnmigratedRow, testRunSkipsAlreadyMigratedRow, testRunSkipsWhenCrosswalkCannotResolve) directly exercises this retarget; not independently UI-observable.

#### Scenario: New conflict-of-interest declaration references a Membership

- **WHEN** a `conflict-of-interest` object is created after this change
- **THEN** its `boardMember` property MUST hold a `Membership` UUID
- **AND** OpenRegister rejects a `Participant` UUID as a type mismatch once the retarget is live

---

### Requirement: REQ-SDM-024 ProxyAuthorization references Person, gains proxyStatus; BoardProxy is retired

`ProxyAuthorization.grantor` and `ProxyAuthorization.holder` SHALL `$ref: Person` (was `$ref: Participant`). `ProxyAuthorization` SHALL additionally declare an optional `proxyStatus` property (enum `pending-approval`/`active`/`suspended`/`revoked`, default `pending-approval`), carrying the approval-workflow concept previously unique to `BoardProxy`. The `board-proxy` schema SHALL be marked `x-openregister.active: false` with a description pointing readers at `ProxyAuthorization` + `proxyStatus`; it SHALL NOT be deleted (existing rows and the `hardDelete: false` convention both require this).

@e2e exclude migration/repair-step behaviour verified by PHPUnit — tests/Unit/Repair/MigrateBoardProxyToProxyAuthorizationTest.php (testRunMigratesResolvableRow, testRunSkipsRowWithUnresolvableMeeting/Grantor, testRunIsIdempotentAgainstExistingTargetRow) directly exercises the retarget and the BoardProxy deactivation; not independently UI-observable.

#### Scenario: New proxy authorization references Person and carries an approval state

- **WHEN** a `proxyAuthorization` object is created after this change
- **THEN** `grantor` and `holder` MUST hold `Person` UUIDs
- **AND** `proxyStatus` defaults to `pending-approval` when omitted

#### Scenario: BoardProxy is inactive but not deleted

- **GIVEN** the decidesk register is imported after this change
- **WHEN** the `board-proxy` schema definition is inspected
- **THEN** `x-openregister.active` is `false`
- **AND** the schema definition and its slug are still present (not removed from the register)

---

### Requirement: REQ-SDM-025 GoverningDocument carries a current-in-force convenience property

`GoverningDocument` SHALL declare an optional `currentEffectiveDate` property (nullable `date`, `facetable: true`), mirroring `Regeling.currentEffectiveDate` in shape and the same maintenance caveat (a convenience field, not a live-computed aggregation — see design.md).

@e2e exclude a schema-only additive property with no page of its own. It is declared in `lib/Settings/register.d/55-governing-documents-register.json`, and no test pins it or checks that older rows read null after a re-import. The index column that shows it is `governing-documents-register` REQ-GDR-010, a gap tracked in ConductionNL/decidiq#1277.

#### Scenario: GoverningDocument gains the property with no value on existing rows

- **GIVEN** an existing `GoverningDocument` object created before this change
- **WHEN** the register is re-imported with the new fragment
- **THEN** the object's `currentEffectiveDate` reads as `null` until a future write populates it (schema-only change; no retroactive backfill in this change)

---

### Requirement: REQ-SDM-026 Slug hygiene — advice-request and proxy-authorization

The schema previously slugged `adviceRequest` SHALL be slugged `advice-request`; the schema previously slugged `proxyAuthorization` SHALL be slugged `proxy-authorization`. Both renames SHALL be reflected in `components.registers.decidesk.schemas`, in every manifest reference (`src/manifest.json`, `src/manifest.d/advisory-opinion-workflow.json`, `src/manifest.d/member-proxy-authorization.json`), and in the schema's own seed-data object collection key. The unrelated `ConsultationRequest.type` enum literal `"adviceRequest"` (register.d/47-works-council-consultation.json) is NOT part of this rename — it is a coincidental string collision with a different field on a different schema.

@e2e exclude schema/register-shape assertion (slug rename reflected in `components.registers.decidesk.schemas` and manifest references) — verified structurally by tests/Unit/RegisterJsonTest.php::testSchemasHaveSlugsAndVersions/testAllSchemasExist; no UI surface of its own beyond the pages that already reference these schemas by their new slug, which continue to render via the existing manifest.

#### Scenario: Advice-request schema resolves under its new kebab-case slug

- **WHEN** the register is imported after this change
- **THEN** `components.registers.decidesk.schemas` contains `advice-request` and no longer contains `adviceRequest`
- **AND** every manifest page/widget that referenced `"schema": "adviceRequest"` now reads `"schema": "advice-request"`

#### Scenario: Proxy-authorization schema resolves under its new kebab-case slug

- **WHEN** the register is imported after this change
- **THEN** `components.registers.decidesk.schemas` contains `proxy-authorization` and no longer contains `proxyAuthorization`
- **AND** every manifest page/widget that referenced `"schema": "proxyAuthorization"` now reads `"schema": "proxy-authorization"`

#### Scenario: The unrelated WOR consultation-request enum value is untouched

- **GIVEN** `register.d/47-works-council-consultation.json`'s `ConsultationRequest.type` enum, which includes the literal `"adviceRequest"` (art. 25 WOR advice request — an unrelated concept)
- **WHEN** this change's slug rename is applied
- **THEN** that enum literal is unchanged, and the works-council-consultation quick filter `{ "type": "adviceRequest" }` continues to match on the `type` field, not a schema slug
