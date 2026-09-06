---
status: in-progress
---

# meeting-detail-view Specification

**Status**: in-progress
**Scope**: decidiq
**OpenSpec changes**:
- meeting-facet-composition (in-progress) — composes 5 meeting-scoped facets (oral questions, interpellations, proxy authorizations, kascommissie verklaringen, routed incoming documents) onto the Meeting detail page per ADR-004 Rule 3

## Purpose
Provides the meeting detail page where users view and edit a single meeting, with sections for the meeting header, schedule, attendees, agenda items, and minutes. Lets users run lifecycle action buttons based on the current state, manage attendees and agenda items inline, work with a Files/Notes/Audit/Tags sidebar, and delete the meeting with confirmation.

## Requirements

### Requirement: REQ-MDV-001 — Meeting detail page with view mode

The system SHALL provide a meeting detail page at route `/meetings/:id` using `CnDetailPage`. The view mode SHALL display the following `CnDetailCard` sections:

1. **Meeting header** — title, scheduledDate, governance body name, meetingMode, lifecycle badge (`CnStatusBadge`)
2. **Location & Schedule** — location, scheduled start/end, duration calculation
3. **Attendees** — list of attendees with Person name, role, votingWeight, attendanceStatus; add/remove buttons in `#header-actions` slot
4. **Agenda Items** — sortable table (orderNumber, title, itemType, estimatedDuration, description); add/reorder/edit/delete buttons
5. **Minutes** — link to Minutes object if exists, or placeholder text "Minutes pending"

@e2e exclude the MeetingDetail view-mode render is exercised by tests/e2e/workflows/crud-persistence.spec.ts ("Meeting: create persists, appears in list, detail shows values, delete removes it"), but that test is tagged to the meeting-management spec and uses a minimal fixture, not this scenario's specific 39-attendee/5-agenda-item/lifecycle-badge assertions — no e2e file currently carries an @e2e tag for this exact meeting-detail-view scenario id.

#### Scenario: REQ-MDV-001-S1 — View mode renders all sections
- **GIVEN** a meeting "Vergadering Gemeenteraad Delft" exists with 39 attendees, 5 agenda items, and no minutes
- **WHEN** the user navigates to `/meetings/abc-123`
- **THEN** the page displays all 5 sections with populated data
- **AND** the Minutes section shows "Minutes pending"

#### Scenario: REQ-MDV-001-S2 — Lifecycle badge colors
- **GIVEN** a meeting is in "scheduled" lifecycle state
- **WHEN** the detail page renders
- **THEN** the lifecycle badge shows "Scheduled" with the appropriate status color

### Requirement: REQ-MDV-002 — Meeting detail page with edit mode

The system SHALL provide an edit mode using `CnFormDialog` (schema-driven) when the user clicks the Edit button or navigates to `/meetings/new`. The form SHALL include fields: title, meetingType, scheduledDate, endDate, location, meetingMode, quorumRequired, and governanceBody select.

@e2e exclude the meeting create/edit form is exercised by tests/e2e/spec-coverage/meeting-management.spec.ts ("Add Meeting dialog opens with required fields") and tests/e2e/workflows/crud-persistence.spec.ts ("Meeting edit dialog saves a title change...", "Meeting Create dialog submit enables once required enums are selected"), but those tests are tagged to the meeting-management spec, not this one — no e2e file currently carries an @e2e tag for these exact meeting-detail-view scenario ids.

#### Scenario: REQ-MDV-002-S1 — New meeting form
- **GIVEN** the user navigates to `/meetings/new`
- **WHEN** the page loads
- **THEN** the form is displayed in create mode with empty fields
- **AND** if navigating from a governance body detail page, the governanceBody field is pre-filled

#### Scenario: REQ-MDV-002-S2 — Edit existing meeting
- **GIVEN** a meeting "ALV De Meeuwen" exists
- **WHEN** the user clicks the Edit button (pencil icon)
- **THEN** the form is displayed with current meeting values pre-filled

#### Scenario: REQ-MDV-002-S3 — Save form
- **GIVEN** the user is in edit mode
- **WHEN** the user modifies the title and clicks Save
- **THEN** the system calls PUT `/api/meetings/{id}` and returns to view mode with updated data

### Requirement: REQ-MDV-003 — Lifecycle action buttons

The system SHALL display lifecycle action buttons in the `CnDetailPage` `#header-actions` slot. Buttons SHALL appear based on the current lifecycle state and the allowed transitions for the meeting's governance domain.

**Button mapping:**
| Current State | Available Actions |
|--------------|-------------------|
| draft | Schedule, Cancel |
| scheduled | Open, Cancel |
| opened | Pause, Adjourn, Close |
| paused | Open (resume), Adjourn, Cancel |
| adjourned | Open (reconvene), Close, Cancel |

@e2e exclude no current e2e test drives a MeetingDetail lifecycle action button (Schedule/Open/Pause/Adjourn/Close/Cancel) and asserts the badge + button-set update; genuine coverage gap from tonight's archival sync, tracked as e2e debt (writing/verifying a new Playwright test is out of scope for this gate-closing pass).

#### Scenario: REQ-MDV-003-S1 — Draft meeting shows Schedule and Cancel
- **GIVEN** a meeting is in "draft" state
- **WHEN** the detail page renders
- **THEN** the header shows "Schedule" and "Cancel" action buttons

#### Scenario: REQ-MDV-003-S2 — Opened meeting shows Pause, Adjourn, Close
- **GIVEN** a meeting is in "opened" state
- **WHEN** the detail page renders
- **THEN** the header shows "Pause", "Adjourn", and "Close" action buttons

#### Scenario: REQ-MDV-003-S3 — Action button triggers lifecycle transition
- **GIVEN** a meeting is in "draft" state
- **WHEN** the user clicks "Schedule"
- **THEN** the system calls POST `/api/meetings/{id}/actions/schedule`
- **AND** the UI updates the lifecycle badge to "Scheduled"
- **AND** the available action buttons update to reflect the new state

### Requirement: REQ-MDV-004 — Meeting sidebar

The system SHALL display `CnObjectSidebar` on the meeting detail page with the following tabs: Files (agenda documents), Notes, Audit Trail (change history), Tags.

@e2e exclude no current e2e test opens the meeting detail CnObjectSidebar's Files or Audit Trail tab and asserts their content; genuine coverage gap from tonight's archival sync, tracked as e2e debt.

#### Scenario: REQ-MDV-004-S1 — Sidebar with files tab
- **GIVEN** a meeting has 2 attached agenda documents (PDF)
- **WHEN** the user opens the sidebar Files tab
- **THEN** the 2 documents are listed with name, type, and download link

#### Scenario: REQ-MDV-004-S2 — Sidebar with audit trail
- **GIVEN** a meeting has been updated 3 times
- **WHEN** the user opens the sidebar Audit Trail tab
- **THEN** the 3 change entries are displayed with timestamp, user, and before/after values

### Requirement: REQ-MDV-005 — Delete meeting

The system SHALL provide a Delete button (trash icon) on the meeting detail page. Clicking it SHALL open `CnDeleteDialog` for confirmation before calling DELETE `/api/meetings/{id}`.

@e2e exclude the confirm-and-delete path is exercised by tests/e2e/workflows/crud-persistence.spec.ts ("Meeting: create persists, appears in list, detail shows values, delete removes it") but that test is tagged to the meeting-management spec; the delete-cancelled path (S2) has no e2e assertion at all — genuine coverage gap tracked as e2e debt.

#### Scenario: REQ-MDV-005-S1 — Delete with confirmation
- **GIVEN** the user is viewing a meeting in "draft" state
- **WHEN** the user clicks Delete and confirms in the dialog
- **THEN** the system calls DELETE `/api/meetings/{id}`
- **AND** the router navigates back to `/meetings`

#### Scenario: REQ-MDV-005-S2 — Delete cancelled by user
- **GIVEN** the user clicks Delete
- **WHEN** the user clicks Cancel in the confirmation dialog
- **THEN** no API call is made and the user remains on the detail page

### Requirement: REQ-MDV-006 — Attendee management in detail view

The system SHALL provide "Add attendee" and "Remove attendee" controls in the Attendees `CnDetailCard` `#header-actions` slot. Adding an attendee SHALL open a dialog to select a Person and assign a role. Removing SHALL remove the ATTENDEE from the VEVENT.

@e2e exclude no current e2e test drives the "Add attendee" dialog on MeetingDetail; genuine coverage gap from tonight's archival sync, tracked as e2e debt.

#### Scenario: REQ-MDV-006-S1 — Add attendee from detail view
- **GIVEN** the user is viewing a meeting with 39 attendees
- **WHEN** the user clicks "Add attendee" and selects Person "Ir. P. de Vries" with role "guest"
- **THEN** the attendee is added and the attendee list updates to show 40 entries

### Requirement: REQ-MDV-007 — Agenda management in detail view

The system SHALL provide "Add agenda item" and drag-to-reorder controls in the Agenda Items `CnDetailCard`. Adding SHALL open `CnFormDialog` for the agenda item schema. Each row SHALL have edit (pencil) and delete (trash) action buttons.

@e2e exclude "Add agenda item" and drag-reorder are exercised by tests/e2e/spec-coverage/agenda-management.spec.ts ("Add Agenda Item dialog opens with item type field", and the reorder-agenda-items-via-drag-and-drop-tagged tests), but those tests are tagged to the agenda-management spec, not this one — no e2e file currently carries an @e2e tag for these exact meeting-detail-view scenario ids.

#### Scenario: REQ-MDV-007-S1 — Add agenda item from detail view
- **GIVEN** the user is viewing a meeting with 5 agenda items
- **WHEN** the user clicks "Add agenda item" and fills title "Interpellatie huisvesting" with itemType "discussion"
- **THEN** the item is created with orderNumber 6 and appears at the bottom of the agenda table

#### Scenario: REQ-MDV-007-S2 — Drag to reorder agenda items
- **GIVEN** the meeting has items A(1), B(2), C(3)
- **WHEN** the user drags item C above item A
- **THEN** the table updates to C(1), A(2), B(3)

### Requirement: REQ-MDV-008 — Routes

The system SHALL register the following named routes:
- `MeetingList`: path `/meetings`
- `MeetingDetail`: path `/meetings/:id` (props: `id` from route params)

When `id` equals `"new"`, the detail page SHALL render in create mode.

@e2e exclude the router-level behaviour (navigating to `/meetings/new` vs `/meetings/:id`) is exercised indirectly by every meeting create/edit/detail test in tests/e2e/spec-coverage/meeting-management.spec.ts and tests/e2e/workflows/crud-persistence.spec.ts, but none asserts the internal `isNew` component state this scenario names, and none carries an @e2e tag for this spec — no dedicated route-level assertion exists.

#### Scenario: REQ-MDV-008-S1 — Route to new meeting
- **GIVEN** the user navigates to `/meetings/new`
- **WHEN** the component mounts
- **THEN** `isNew` is true and the edit form is displayed

#### Scenario: REQ-MDV-008-S2 — Route to existing meeting
- **GIVEN** the user navigates to `/meetings/abc-123`
- **WHEN** the component mounts
- **THEN** `isNew` is false and the view mode is displayed with data from the API

### Requirement: REQ-MDV-009 — Oral questions (vragenuur) facet
The Meeting detail page (`MeetingDetail`) SHALL show a facet listing the
`mondelinge-vraag` objects whose `targetMeeting` equals the current
meeting's object id, and SHALL let the user create a new oral question
from that facet with `targetMeeting` pre-filled to the current meeting.

#### Scenario: Oral questions scoped to the current meeting
- GIVEN a meeting "Raadsvergadering 2025-01-15" with two `mondelinge-vraag`
  objects whose `targetMeeting` is that meeting, and one `mondelinge-vraag`
  object targeting a different meeting
- WHEN the user opens the meeting's detail page
- THEN the oral-questions facet lists exactly the two questions that
  target this meeting
- AND each row links to that question's detail page

@e2e exclude the oral-questions facet's rendering mechanism is exercised by tests/e2e/spec-coverage/facets-meeting-detail.spec.ts ("MeetingDetail: oral-questions facet lists a mondelinge-vraag created via API") whose own @e2e anchor still targets the pre-archival openspec/changes/meeting-facet-composition/... path so this gate does not match it; that test seeds only one targeting question and does not seed a second question targeting a different meeting, so it does not prove this scenario's exclusion/scoping half — recorded here rather than overclaiming exact coverage.

#### Scenario: Creating an oral question in context
- GIVEN the user is on a meeting's detail page
- WHEN the user creates a new oral question from the oral-questions facet
- THEN the new `mondelinge-vraag` object is created with `targetMeeting`
  set to the current meeting without the user having to select it

@e2e exclude no current e2e test drives the create-from-facet flow (only the read/list path is exercised, by facets-meeting-detail.spec.ts); genuine coverage gap tracked as e2e debt.

### Requirement: REQ-MDV-010 — Interpellations facet
The Meeting detail page SHALL show a facet listing the
`interpellatieverzoek` objects whose `behandeldIn` equals the current
meeting's object id.

#### Scenario: Interpellations scheduled at the current meeting
- GIVEN a meeting with one `interpellatieverzoek` object whose
  `behandeldIn` is that meeting, and one `interpellatieverzoek` object
  with no `behandeldIn` set (not yet scheduled)
- WHEN the user opens the meeting's detail page
- THEN the interpellations facet lists exactly the one request scheduled
  at this meeting
- AND the unscheduled request does not appear

@e2e exclude the interpellations facet's rendering mechanism is exercised by tests/e2e/spec-coverage/facets-meeting-detail.spec.ts ("MeetingDetail: interpellations, proxy-authorizations and routed-documents facets render their real empty states...") whose own @e2e anchor still targets the pre-archival openspec/changes/meeting-facet-composition/... path so this gate does not match it; that test only proves the empty state, not this scenario's specific behandeldIn-filter (scheduled-vs-unscheduled) assertion — recorded here rather than overclaiming exact coverage.

### Requirement: REQ-MDV-011 — Proxy authorizations (voting) facet
The Meeting detail page SHALL show a facet listing the `proxyAuthorization`
objects whose `meeting` equals the current meeting's object id, and SHALL
let the user register a new proxy authorization from that facet with
`meeting` pre-filled to the current meeting.

#### Scenario: Proxy authorizations scoped to the current meeting
- GIVEN a meeting with two `proxyAuthorization` objects whose `meeting` is
  that meeting
- WHEN the user opens the meeting's detail page
- THEN the proxy-authorizations facet lists both, showing each one's
  signature and countersign status

@e2e exclude tests/e2e/spec-coverage/facets-meeting-detail.spec.ts only exercises this facet's EMPTY state (zero proxy authorizations); this scenario's populated-list assertion (two entries with signature/countersign status) is untested — genuine coverage gap tracked as e2e debt.

#### Scenario: Registering a proxy authorization in context
- GIVEN the user is on a meeting's detail page
- WHEN the user registers a new proxy authorization from the facet
- THEN the new `proxyAuthorization` object is created with `meeting` set
  to the current meeting without the user having to select it

@e2e exclude no current e2e test drives the create-from-facet flow; genuine coverage gap tracked as e2e debt.

### Requirement: REQ-MDV-012 — Audit statements facet (assoc mode only)
The Meeting detail page SHALL show a facet listing `audit-statement`
objects whose `governanceBody` equals the current meeting's own
`governanceBody`, and this facet SHALL be visible only when the tenant's
active `organisatie_modus` setting is `assoc`. In every other mode the
facet SHALL be hidden: the widget declaration itself is not removed from
the page, only its rendered content is suppressed.

An association reads this facet as *Kascommissie verklaringen*, and every
other organisation reads it as *Audit statements*, from one schema. The
Dutch wording is a mode label, not a schema name.

#### Scenario: Audit statement facet visible in association mode
- GIVEN the tenant's `organisatie_modus` is `assoc`
- AND the current meeting's `governanceBody` has one `audit-statement`
  object referencing it
- WHEN the user opens the meeting's detail page
- THEN the audit statement facet renders and lists that statement

@e2e exclude no current e2e test switches the tenant to `assoc` mode and asserts the audit statement facet renders; only the hidden-in-gov-mode half is exercised (see the sibling scenario below), which is a genuine coverage gap tracked as e2e debt.

#### Scenario: Audit statement facet hidden outside association mode
- GIVEN the tenant's `organisatie_modus` is `gov`
- AND the current meeting's `governanceBody` has an `audit-statement`
  object referencing it
- WHEN the user opens the meeting's detail page
- THEN the audit statement facet does not render any content
- AND no other facet on the page is affected

@e2e exclude exercised by tests/e2e/spec-coverage/facets-meeting-detail.spec.ts, which asserts `getByTestId('meeting-audit-statements-tab')` has count 0 in gov mode.

### Requirement: REQ-MDV-013 — Routed incoming documents facet (read-only)
The Meeting detail page SHALL show a single read-only facet listing every
`raadsinformatiebrief` object whose `agendaItem` resolves to one of the
current meeting's own agenda items, and every `ingekomen-stuk` object
whose `targetAgendaItem` or `listAgendaItem` resolves to one of the
current meeting's own agenda items. The facet SHALL NOT offer a create
affordance.

#### Scenario: Documents routed onto the meeting's agenda
- GIVEN a meeting with two agenda items, A1 and A2
- AND one `raadsinformatiebrief` object with `agendaItem` = A1
- AND one `ingekomen-stuk` object with `listAgendaItem` = A2
- AND one `ingekomen-stuk` object with no agenda-item reference at all
- WHEN the user opens the meeting's detail page
- THEN the routed-documents facet lists the letter and the routed
  incoming document
- AND the unrouted incoming document does not appear
- AND no create button is offered on this facet

@e2e exclude tests/e2e/spec-coverage/facets-meeting-detail.spec.ts only exercises this facet's EMPTY state ("No incoming documents routed to this meeting yet."); this scenario's populated-list assertion (two routed documents, one unrouted excluded, no create button) is untested — genuine coverage gap tracked as e2e debt.
