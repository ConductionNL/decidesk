---
status: in-progress
status-note: In progress 2026-08-19 via organisation-facet-composition (GovernanceBodyDetail becomes the ADR-004 v2 Organisation hub — retirement schedule, term rules, integrity declarations, shared-body participation, and factions composed onto the detail page as declarative object-list widgets).
openspec-changes:
  - organisation-facet-composition
---

# governance-body-crud Specification

## Purpose
Provides full create, read, update, and delete management of GovernanceBody objects. Bodies are shown in a paginated, searchable, filterable list, created and edited through schema-driven form dialogs with required-field validation, and deleted with confirmation. The detail page renders a body's properties alongside its linked Meetings and Participants and an object sidebar with Files, Notes, Tags, Tasks, and Audit Trail tabs.

## Requirements

### Requirement: Governance body list view
The app SHALL display all GovernanceBody objects in a paginated, searchable list using `CnIndexPage` with `useListView`.

@e2e exclude basic list rendering is exercised by tests/e2e/spec-coverage/governance-body.spec.ts ("governance bodies list renders with Add GovernanceBody button" — asserts the "Showing N of N" indicator), but that test is tagged to the admin-settings spec and does not assert the specific columns (bodyType/domain/termEnd) or drive the bodyType filter/pagination controls this spec's scenarios name — no e2e file carries an @e2e tag for these exact scenario ids.

#### Scenario: User views governance body list
- **WHEN** the user navigates to `/governance-bodies`
- **THEN** a list of GovernanceBody objects is shown with columns for name, bodyType, domain, and termEnd

#### Scenario: User filters by body type
- **WHEN** the user applies a filter on the `bodyType` field via `CnFilterBar`
- **THEN** only GovernanceBody objects matching the selected bodyType are shown

#### Scenario: Governance body list is paginated
- **WHEN** there are more governance bodies than the page size
- **THEN** `CnPagination` is shown and the user can navigate to other pages

### Requirement: Create a governance body
The app SHALL allow users to create a new GovernanceBody object via a schema-driven form dialog.

@e2e exclude the create dialog opening + required-field validation (Create button disabled until name/domain/bodyType are filled) is exercised by tests/e2e/spec-coverage/governance-body.spec.ts ("Create GovernanceBody dialog opens with name, bodyType and domain required fields"), but that test cancels rather than submits and is tagged to the admin-settings spec — the actual save-and-persist path (`governance-body-is-saved-successfully`) has no e2e assertion at all; no e2e file carries an @e2e tag for these exact scenario ids.

#### Scenario: User opens create dialog
- **WHEN** the user clicks the add button on the governance bodies list
- **THEN** a `CnFormDialog` opens with fields for name, bodyType, domain, votingDefault, quorumRule, termStart, and termEnd

#### Scenario: Governance body is saved successfully
- **WHEN** the user fills in all required fields (name, bodyType, domain) and clicks save
- **THEN** the GovernanceBody is persisted via `ObjectService.saveObject()` and appears in the list

#### Scenario: Validation prevents save without required fields
- **WHEN** the user submits the form with `name` or `bodyType` empty
- **THEN** the form shows a validation error and the object is not saved

### Requirement: View governance body detail
The app SHALL display a detail view for a GovernanceBody including its properties,
linked Meetings, linked Participants, and — per ADR-004 v2's Organisation cluster —
the body's retirement schedule, term rule, integrity declarations (other positions
and gifts), shared-body participation, and factions, each rendered as a filtered
`object-list` widget scoped to the body's own object id against that register's
existing scoping field. This detail page is the "Organisation" hub: a griffier no
longer has to leave it to find a body's retirement schedule, term rule, or
integrity register.

#### Scenario: User opens a governance body detail page
- **WHEN** the user clicks a row in the governance bodies list
- **THEN** the router navigates to `/governance-bodies/:id` and `CnDetailPage` renders the body's properties

@e2e exclude the detail page rendering real content (not just an empty shell) is exercised by tests/e2e/spec-coverage/facets-organisation-detail.spec.ts ("GovernanceBodyDetail: factions facet lists the seeded factions under their parent council" — asserts the body's own name "Gemeenteraad Amsterdam" renders), but that test navigates directly by id rather than clicking a list row, and is tagged to the organisation-facet-composition change path, not this spec — no e2e file carries an @e2e tag for this exact scenario id.

#### Scenario: Related Meetings shown in detail
- **WHEN** the governance body detail page loads
- **THEN** a `CnDetailCard` section lists the Meetings linked to this GovernanceBody via OpenRegister relations

@e2e exclude no current e2e test asserts the "Meetings" section on GovernanceBodyDetail lists linked meetings; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Related Participants shown in detail
- **WHEN** the governance body detail page loads
- **THEN** a `CnDetailCard` section lists the Participants linked to this GovernanceBody, showing displayName and role

@e2e exclude no current e2e test asserts the "Participants" section on GovernanceBodyDetail; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: CnObjectSidebar is available
- **WHEN** the user is on the governance body detail page
- **THEN** `CnObjectSidebar` is rendered with Files, Notes, Tags, Tasks, and Audit Trail tabs

@e2e exclude no current e2e test opens the GovernanceBodyDetail sidebar and asserts its tab set; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Retirement schedule shown on the body detail page
- **GIVEN** a GovernanceBody with a generated `rooster-van-aftreden` (`body` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Retirement schedule" widget lists the body's `rooster-van-aftreden` object(s)
- **AND** clicking a row navigates to `RoosterDetail`, which shows the ordered term entries

@e2e exclude no current e2e test asserts the "Retirement schedule" widget's populated state on GovernanceBodyDetail; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: No retirement schedule yet
- **GIVEN** a GovernanceBody with no `rooster-van-aftreden` object
- **WHEN** the user views the GovernanceBody detail page
- **THEN** the "Retirement schedule" widget shows its empty state, not an error

@e2e exclude no current e2e test asserts the "Retirement schedule" widget's empty state on GovernanceBodyDetail; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Term rule shown read-only on the body detail page
- **GIVEN** a GovernanceBody with one or more `termijn-regeling` objects (`body` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Term rules" widget lists the body's `termijn-regeling` object(s) with no inline create or edit action
- **AND** clicking a row navigates to `TermijnRegelingDetail`, where the rule is editable

@e2e exclude no current e2e test asserts the "Term rules" widget on GovernanceBodyDetail; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Integrity declarations shown on the body detail page
- **GIVEN** a GovernanceBody with `nevenfunctie` and `geschenk` objects scoped to it (`governanceBody` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** an "Other positions" widget lists the body's `nevenfunctie` objects and a "Gifts" widget lists the body's `geschenk` objects
- **AND** clicking a row in either navigates to that object's own detail page (`NevenfunctieDetail` / `GeschenkDetail`)

@e2e exclude no current e2e test asserts the "Other positions"/"Gifts" widgets on GovernanceBodyDetail; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Shared-body participation shown on the body detail page
- **GIVEN** a GovernanceBody with `bodyType=shared-body` and `body-participation` objects referencing it (`sharedBody` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Participating organisations" widget lists the participating `body-participation` objects
- **AND** a "Zienswijze rounds" widget lists the body's `zienswijzeronde` objects (`sharedBody` = the body's object id)

@e2e exclude no current e2e test asserts the "Participating organisations"/"Zienswijze rounds" widgets on GovernanceBodyDetail; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: A body's own participations in shared bodies are shown
- **GIVEN** a GovernanceBody that itself participates in one or more shared bodies (`body-participation.participant` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Shared-body participations" widget lists those `body-participation` objects

@e2e exclude no current e2e test asserts the "Shared-body participations" widget on GovernanceBodyDetail; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Factions shown on a body's detail page
- **GIVEN** one or more `GovernanceBody` objects with `bodyType=faction` and `parentBody` set to this body's object id
- **WHEN** the user views this GovernanceBody's detail page
- **THEN** a "Factions" widget lists those child `GovernanceBody` objects
- **AND** clicking a row navigates to that faction's own `GovernanceBodyDetail` page

@e2e exclude exercised by tests/e2e/spec-coverage/facets-organisation-detail.spec.ts ("GovernanceBodyDetail: factions facet lists the seeded factions under their parent council" — asserts both seeded factions "GroenLinks-fractie" and "D66-fractie" render under the Factions heading); that test's own @e2e anchor still targets the pre-archival openspec/changes/organisation-facet-composition/... path so this gate does not match it. The row-click-navigates-to-faction-detail half is not separately asserted — recorded here rather than reported as a total gap.

#### Scenario: No facet widget errors when its register is empty for this body
- **GIVEN** a GovernanceBody with no `nevenfunctie`, `geschenk`, `body-participation`, `zienswijzeronde`, or faction-`bodyType` child objects referencing it
- **WHEN** the user views the GovernanceBody detail page
- **THEN** every added facet widget renders its own empty state and the page does not error

@e2e exclude no current e2e test opens a GovernanceBodyDetail page with zero facet data and asserts every widget renders its empty state without erroring; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

### Requirement: Edit a governance body
The app SHALL allow users to edit an existing GovernanceBody object.

@e2e exclude no current e2e test opens the Edit dialog on a GovernanceBody or asserts a persisted change; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: User edits a governance body
- **WHEN** the user clicks the Edit button on the governance body detail page
- **THEN** a `CnFormDialog` opens pre-populated with the current data

#### Scenario: Changes are persisted
- **WHEN** the user modifies fields and clicks save
- **THEN** the GovernanceBody is updated via `ObjectService.saveObject()` and the detail view reflects the changes

### Requirement: Delete a governance body
The app SHALL allow users to delete a GovernanceBody object with confirmation.

@e2e exclude no current e2e test drives the delete (or delete-cancel) flow for a GovernanceBody; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: User deletes a governance body
- **WHEN** the user clicks Delete and confirms in `CnDeleteDialog`
- **THEN** the GovernanceBody is removed via `ObjectService.deleteObject()` and the user is redirected to the list

#### Scenario: Delete is cancelled
- **WHEN** the user cancels the delete dialog
- **THEN** the GovernanceBody is not deleted
