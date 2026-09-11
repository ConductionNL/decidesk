---
status: in-progress
status-note: In progress 2026-06-14 via popolo-decision-makers (ContactDetail Popolo schema added; Person + Membership become the governance-body decision-maker model per ADR-001/ADR-006). In progress 2026-06-14 via retire-board-portal (parallel Board schema + Board views retired per ADR-006; corporate boards become governance-body objects with bodyType=corporate-board and mode=corp labels). In progress 2026-08-19 via organisation-facet-composition (bodyType gains a `faction` value + new `parentBody` self-reference per ADR-006, so a faction is an ordinary GovernanceBody rather than the parallel schema the stale fractievoorzitter-fractie-koppeling draft proposed).
openspec-changes:
  - popolo-decision-makers
  - retire-board-portal
  - organisation-facet-composition
---

# governance-bodies Specification

## Purpose
Defines the GovernanceBody as the universal model for any decision-making body — municipal councils, associations, and corporate boards alike — carrying a workflowTemplate preset that governs its meeting lifecycle and a bodyType enum that absorbs corporate subtypes (supervisory-board, executive-board) so no separate Board schema is needed. People relate to a body through the Popolo Membership entity with typed multi-value ContactDetails, replacing the deprecated flat Participant model. The body detail page lists its scheduled and recent meetings and allows creating a meeting from the body regardless of mode.

## Requirements

### Requirement: REQ-GBD-001 — Governance body workflowTemplate property

The GovernanceBody entity SHALL include a `workflowTemplate` property (string, optional) that stores the governance domain preset key. Valid values: `legislative`, `association`, `corporate`, `operations`, `citizen`. This property determines which meeting lifecycle transitions are allowed for meetings of this body (see meeting-workflow spec).

**Entity property (ADR-000):**
| Property | Type | Required | Description |
|----------|------|----------|-------------|
| workflowTemplate | string | No | Domain preset key: legislative, association, corporate, operations, citizen |

@e2e exclude no current e2e or PHPUnit test asserts the domain→workflowTemplate default-on-create behaviour or an admin override of it; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: REQ-GBD-001-S1 — Set workflowTemplate on body creation
- **GIVEN** the user creates a GovernanceBody with domain "legislative"
- **WHEN** the body is saved
- **THEN** workflowTemplate defaults to "legislative" based on the domain value

#### Scenario: REQ-GBD-001-S2 — Custom workflowTemplate override
- **GIVEN** a GovernanceBody exists with domain "legislative" and workflowTemplate "legislative"
- **WHEN** the admin updates workflowTemplate to "association"
- **THEN** future meetings for this body follow the association workflow preset

### Requirement: REQ-GBD-002 — Governance body meetings section

The GovernanceBody detail page SHALL include a "Scheduled Meetings" `CnDetailCard` section displaying upcoming and recent meetings for the body. The section SHALL use a `CnDataTable` with columns: title, scheduledDate, lifecycle status. Meetings SHALL be fetched via reverse lookup (`fetchUsed`) from the Meeting wrapper's relation to GovernanceBody.

@e2e exclude no current e2e test asserts the "Scheduled Meetings" section on GovernanceBodyDetail or its row-click navigation; same gap as governance-body-crud's `related-meetings-shown-in-detail` scenario — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: REQ-GBD-002-S1 — Meetings listed on body detail page
- **GIVEN** GovernanceBody "Gemeenteraad Delft" has 3 upcoming and 2 recent meetings
- **WHEN** the user views the GovernanceBody detail page
- **THEN** the "Scheduled Meetings" section displays 5 meetings sorted by scheduledDate

#### Scenario: REQ-GBD-002-S2 — Navigate to meeting from body
- **GIVEN** the meetings section displays meeting "Vergadering april 2026"
- **WHEN** the user clicks the meeting row
- **THEN** the router navigates to `/meetings/{meetingId}`

### Requirement: REQ-GBD-003 — Meeting creation from governance body
The system MUST allow creating a `meeting` from a `governance-body` regardless of
mode. After retiring the board portal, a corporate board meeting is a universal
`meeting` linked to a `governance-body` (no `board-meeting` schema). A corporate
`meeting` seed and `minutes` seed are provided so the corporate scenario is
demonstrable on install.

@e2e exclude no current e2e test creates a meeting from a corporate GovernanceBody, nor asserts the seeded `rvc-vergadering-2025-q2`/`notulen-rvc-2025-q2` objects exist; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277 (the schema-level "no board-meeting schema exists" half is covered by tests/Unit/RegisterJsonTest.php::testAllSchemasExist).

#### Scenario: Pre-filled meeting from a corporate governance body
- GIVEN a `governance-body` with `bodyType=supervisory-board`
- WHEN a meeting is created from it
- THEN a universal `meeting` is created (no `board-meeting` schema is used)

#### Scenario: Corporate meeting + minutes seeded
- GIVEN the register is imported on a clean instance
- WHEN meetings and minutes are listed
- THEN a `meeting` seed `rvc-vergadering-2025-q2` and a `minutes` seed `notulen-rvc-2025-q2` exist

### Requirement: REQ-GBD-010 — ContactDetail schema (popolo:ContactDetail)
The system MUST define a `ContactDetail` schema (`schemaType: popolo:ContactDetail`)
providing typed, multi-value contacts for a Person or GovernanceBody, mirroring the
ADR-000 ContactDetail field set: `type` (required enum: email, phone, fax, cell, address,
url), `value` (required), `label`, `note`, `validFrom`/`validUntil` (date-time).
ContactDetail MUST declare `x-openregister-relations` to `Person` and `GovernanceBody`
(each `many-to-one`).

@e2e exclude schema/register-shape assertion (relation resolution at the API layer) — no UI surface distinct from existing e2e coverage; no dedicated PHPUnit test exists for the ContactDetail relations either, but this is pure OpenRegister relation-resolution behaviour, not app UI.

#### Scenario: Typed multi-value contact for a person
- GIVEN a Person exists
- WHEN two ContactDetails of type "email" and "phone" are linked to the Person
- THEN both resolve their `Person` relation and carry distinct `type`/`value`

#### Scenario: Contact linked to a governance body
- GIVEN a GovernanceBody exists
- WHEN a ContactDetail of type "address" is linked to the GovernanceBody
- THEN it resolves its `GovernanceBody` relation

### Requirement: REQ-GBD-011 — Person + Membership as the governance-body decision-maker model
The system MUST relate people to a GovernanceBody through the Popolo `Membership` entity
(Person ↔ GovernanceBody), and MUST provide seeded ContactDetail demo data linked to
seeded Persons and GovernanceBodies. The flat `Participant` schema MUST NOT be used as
the canonical decision-maker model for new data (it is retained as a deprecated shim —
see `participant-crud`).

@e2e exclude seed-data/schema-shape assertion (decision-makers represented as Person+Membership, ContactDetails present after import) — no UI surface distinct from existing e2e coverage; verified structurally by the register's seed data, not a browser interaction. The related resolver logic is separately covered by tests/Unit/Service/ParticipantToPersonMembershipResolverTest.php.

#### Scenario: Body membership via Membership, not Participant
- GIVEN a GovernanceBody "Gemeenteraad Amsterdam"
- WHEN its decision makers are seeded
- THEN they are represented as `Person` + `Membership` records (not new `Participant` records)

#### Scenario: Seeded contact details
- GIVEN the register is imported on a clean instance
- WHEN ContactDetails are listed
- THEN at least one email, one phone, and one address ContactDetail exist, linked to a Person or GovernanceBody

### Requirement: REQ-GBD-012 — Corporate board is the universal governance-body (mode=corp)
A corporate board MUST be expressed as a `governance-body` with
`bodyType=supervisory-board` / `executive-board` (ADR-006) and mode=corp labels —
never a separate schema. The parallel `Board` / `BoardMember` schemas, the
`BoardList` / `BoardDetail` views, the `BoardCreateModal`, and the board-CRUD
controller/service are removed; board members are `Person` + `Membership`
(ADR-001, `popolo-decision-makers`). The corporate scenario is re-seeded on the
universal `governance-body`.

@e2e exclude schema/seed-data assertion — the "no Board/BoardMember schema exists" half is covered by tests/Unit/RegisterJsonTest.php::testAllSchemasExist; the "`raad-van-commissarissen-acme-bv` seed exists" and the bodyType-enum halves have no dedicated assertion (the enum content is covered separately by testGovernanceBodySchema, see the scenario below) — no UI surface for the schema-listing assertion itself.

#### Scenario: Corporate board uses the universal governance-body
- GIVEN the register is imported on a clean instance
- WHEN the schemas are listed
- THEN no `Board` or `BoardMember` schema exists
- AND a `governance-body` seed `raad-van-commissarissen-acme-bv` with `bodyType=supervisory-board` exists

#### Scenario: bodyType enum carries the corporate subtypes
- GIVEN the `GovernanceBody` schema
- WHEN its `bodyType` enum is inspected
- THEN it includes `supervisory-board` and `executive-board` (alongside the existing values)

@e2e exclude schema/register-shape assertion, covered by tests/Unit/RegisterJsonTest.php::testGovernanceBodySchema (asserts `supervisory-board` and `executive-board` are both members of the bodyType enum) — no UI surface.

### Requirement: REQ-GBD-013 — Faction is a GovernanceBody discriminator, not a parallel schema

A faction (fractie) MUST be represented as an ordinary `GovernanceBody` object with
`bodyType=faction` and a `parentBody` reference to the council/body it belongs to —
never as a separate `Fractie` schema. This is the ADR-006 mode/type-adaptation
mechanism ("Type discriminators — where a real subtype distinction exists, use an
enum field on the universal entity... not a new schema") applied to factions the
same way it was already applied to corporate boards (`bodyType=supervisory-board`,
REQ-GBD-012). The `GovernanceBody` schema MUST add:
- `bodyType` enum value `faction`, alongside the existing ten values.
- `parentBody` property (`type: string`, `format: uuid`, `$ref: GovernanceBody`,
  nullable) — the parent body a faction (or, generically, any sub-body) belongs to.
  Not restricted to `bodyType=faction`; any `GovernanceBody` MAY set it.

Members of a faction relate to it exactly as they relate to any other
`GovernanceBody` — via `Membership.governanceBody` pointing at the faction's own
object id, per REQ-GBD-011. No new relation schema is introduced for
faction membership.

#### Scenario: Faction is a bodyType value, not a new schema
- **GIVEN** the `GovernanceBody` schema
- **WHEN** its `bodyType` enum is inspected
- **THEN** it includes `faction` alongside the existing ten values
- **AND** no `Fractie` schema exists in the register

@e2e exclude schema/register-shape assertion, covered by tests/Unit/RegisterJsonTest.php::testGovernanceBodySchema (asserts `faction` is a member of the bodyType enum) — no dedicated assertion that a `Fractie` schema is absent, but no such schema was ever declared; no UI surface for this schema-listing assertion.

#### Scenario: A faction references its parent council via parentBody
- **GIVEN** a `GovernanceBody` "Gemeenteraad Amsterdam" (`bodyType=legislative`)
- **WHEN** a `GovernanceBody` "GroenLinks-fractie" is created with `bodyType=faction`
  and `parentBody` set to the Gemeenteraad Amsterdam object id
- **THEN** `parentBody` resolves to the Gemeenteraad Amsterdam `GovernanceBody`

@e2e exclude exercised by tests/e2e/spec-coverage/facets-organisation-detail.spec.ts ("GovernanceBodyDetail: factions facet lists the seeded factions under their parent council" — the seeded factions "GroenLinks-fractie"/"D66-fractie" both carry `parentBody: gemeenteraad-amsterdam`, and the test asserts both render under Gemeenteraad Amsterdam's Factions widget, proving `parentBody` resolution); that test's own @e2e anchor still targets the pre-archival openspec/changes/organisation-facet-composition/... path so this gate does not match it — recorded here rather than reported as a gap.

#### Scenario: Faction members use the same Membership relation as any other body
- **GIVEN** the "GroenLinks-fractie" `GovernanceBody` from the scenario above
- **WHEN** a raadslid's `Membership.governanceBody` is set to the faction's object id
- **THEN** that Membership resolves the faction as its `GovernanceBody`, identically
  to a Membership in a non-faction body

@e2e exclude schema/register-shape assertion (relation resolution at the API layer, no new relation mechanism introduced) — no dedicated e2e assertion opens a faction's own detail page and lists its Memberships distinctly from a non-faction body's; no UI surface beyond the existing Membership-listing pattern already exercised for non-faction bodies.
