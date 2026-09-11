---
status: done
status-note: >-
  2026-06-13 — ALL dashboard requirements built. The in-app CnDashboardPage dashboard was delivered by decidesk-dashboard-v2-widgets (11 widget components + registry + vitest + i18n) and decidesk-dashboard-v2-layout (manifest Dashboard-page rewire to the 11-widget v2 grid, English titles; host browser-verified — all widgets render with live data). The final requirement, "Nextcloud Dashboard Widget Integration", is now built by dashboard-iwidget-v1: an OCP\Dashboard\IWidget (IIconWidget + IButtonWidget + IAPIWidgetV2) that surfaces the current user's pending votes count + next meeting on the Nextcloud main dashboard and deep-links into the app, OR-scoped (per-user, no IDOR) and fail-soft, covered by PHPUnit. Browser coverage for that requirement is excluded on purpose: the Hub is Nextcloud chrome, and the widget is server-rendered PHP with no Decidiq Vue surface. DecidiqDashboardWidgetTest covers it instead.
---

# Dashboard Specification

**OpenSpec changes:**
- [decidesk-dashboard-v2-widgets](../../changes/archive/2026-06-12-decidesk-dashboard-v2-widgets/) _(archived 2026-06-12)_ — 11 dashboard widget components + registry + tests + i18n (kind: code)
- [decidesk-dashboard-v2-layout](../../changes/archive/2026-06-13-decidesk-dashboard-v2-layout/) _(archived 2026-06-13)_ — manifest Dashboard-page rewire to the v2 grid, English titles (kind: config, depends_on widgets)
- [dashboard-iwidget-v1](../../changes/archive/2026-06-13-dashboard-iwidget-v1/) _(archived 2026-06-13)_ — Nextcloud main-dashboard widget via OCP\Dashboard\IWidget (pending votes + next meeting, deep-link, fail-soft) (kind: code)

## Purpose

The Decidiq dashboard provides an at-a-glance overview of active decisions, upcoming meetings, pending votes, action items, and governance KPIs. It uses the `CnDashboardPage` component from `@conduction/nextcloud-vue` for a configurable grid layout and integrates with the Nextcloud Dashboard Widget API (`OCP\Dashboard\IWidget`) for platform-level widget exposure. The dashboard serves as the primary entry point for all Decidiq users.

**Standards**: Schema.org (`Dashboard` pattern), Nextcloud Dashboard Widget API
**Feature tier**: MVP
## Requirements

---

### Requirement: Dashboard Layout

The dashboard MUST use the `CnDashboardPage` component to render a configurable widget grid. The default layout MUST provide an immediate overview of governance activity using the five-row v2 grid.

**Feature tier**: MVP

#### Scenario: Default grid layout on first load

@e2e annotate REQ-dashboard-layout-default-grid

- GIVEN the user has not customized their dashboard layout
- WHEN the user navigates to `/apps/decidiq/`
- THEN the layout MUST render with the default v2 configuration:
  - Row 1 (gridY=0, gridHeight=2): four KPI cards each 3 columns wide — `active-decisions` (custom, slot: `ActiveDecisionsKpiWidget`), `upcoming-meetings-kpi` (custom, slot: `UpcomingMeetingsKpiWidget`), `pending-votes-kpi` (custom, slot: `PendingVotesKpiWidget`), `overdue-actions-kpi` (custom, slot: `OverdueActionsKpiWidget`)
  - Row 2 (gridY=2, gridHeight=4): `upcoming-meetings-list` (6 cols) and `pending-votes-list` (6 cols)
  - Row 3 (gridY=6, gridHeight=4): `running-processes` (6 cols) and `my-action-items` (6 cols)
  - Row 4 (gridY=10, gridHeight=4): `recent-decisions` spanning full 12 columns
  - Row 5 (gridY=14, gridHeight=4): `minutes-in-review` (6 cols, stats-block, the only stats-block in the layout) and `governance-health` (6 cols, custom, slot: `GovernanceHealthWidget`)
- AND each custom widget MUST be rendered via the `slots` mapping in the manifest

#### Scenario: Empty state for new installation

@e2e annotate REQ-dashboard-layout-empty-state

- GIVEN a fresh Decidiq installation with no widgets in the dashboard layout (empty `layout` array)
- WHEN the user views the dashboard
- THEN `CnDashboardPage` SHALL render the `#empty` slot content
- AND `DashboardEmptyState` SHALL be displayed via the manifest's `emptyComponent` configuration
- AND the message "Welcome to Decidiq! Get started by setting up your first governing body in Settings." MUST be visible
- AND quick action buttons MUST be shown: "Set Up Body", "Create Meeting", "Create Decision"

---

### Requirement: KPI Cards

The dashboard MUST display four KPI summary cards in Row 1 (gridY=0, each gridWidth=3, gridHeight=2). Three are custom slot components; one (`active-decisions`) is also a custom slot component counting `outcome == null` client-side.

**Feature tier**: MVP

#### Scenario: Display active decisions count

@e2e annotate REQ-kpi-active-decisions

- WHEN the user views the dashboard
- THEN the "Active Decisions" KPI card (id: `active-decisions`, type: `custom`, slot: `ActiveDecisionsKpiWidget`) MUST be rendered in the grid at gridX=0, gridY=0, gridWidth=3, gridHeight=2
- AND the widget SHALL display the count of decisions whose `outcome` is null (not yet adopted or rejected — see REQ-013 in the widgets change for the component specification)

#### Scenario: Display upcoming meetings KPI

@e2e annotate REQ-kpi-upcoming-meetings

- WHEN the user views the dashboard
- THEN the "Upcoming meetings" KPI card (id: `upcoming-meetings-kpi`, type: `custom`, slot: `UpcomingMeetingsKpiWidget`) MUST be rendered in the grid at gridX=3, gridY=0, gridWidth=3, gridHeight=2

#### Scenario: Display pending votes KPI

@e2e annotate REQ-kpi-pending-votes

- WHEN the user views the dashboard
- THEN the "Pending votes" KPI card (id: `pending-votes-kpi`, type: `custom`, slot: `PendingVotesKpiWidget`) MUST be rendered in the grid at gridX=6, gridY=0, gridWidth=3, gridHeight=2

#### Scenario: Display overdue actions KPI

@e2e annotate REQ-kpi-overdue-actions

- WHEN the user views the dashboard
- THEN the "Overdue actions" KPI card (id: `overdue-actions-kpi`, type: `custom`, slot: `OverdueActionsKpiWidget`) MUST be rendered in the grid at gridX=9, gridY=0, gridWidth=3, gridHeight=2

---

### Requirement: My Pending Votes Widget

The dashboard MUST include a widget showing decisions awaiting the current user's vote, ordered by urgency (voting deadline).

**Feature tier**: MVP

#### Scenario: Show pending votes with urgency indicators

- GIVEN the user has 3 decisions pending their vote
- WHEN the dashboard loads
- THEN the "My Pending Votes" widget MUST list each decision with title, body, and time remaining
- AND decisions with less than 24 hours remaining MUST show a red urgency indicator
- AND clicking a decision MUST navigate to the voting interface

#### Scenario: No pending votes

- GIVEN the user has no decisions pending their vote
- WHEN the dashboard loads
- THEN the widget MUST show "No pending votes" with a check mark icon

---

### Requirement: Upcoming Meetings Widget

The dashboard MUST include a widget showing the user's upcoming meetings across all bodies, ordered by date.

**Feature tier**: MVP

#### Scenario: Show upcoming meetings with context

- GIVEN the user is a member of 2 bodies with upcoming meetings
- WHEN the dashboard loads
- THEN the widget MUST list each meeting with title, date/time, body name, and agenda item count
- AND meetings within the next 24 hours MUST be highlighted
- AND clicking a meeting MUST navigate to the meeting detail view

---

### Requirement: Nextcloud Dashboard Widget Integration

The system MUST register a Nextcloud Dashboard widget via `OCP\Dashboard\IWidget`
(implementing `IIconWidget`, `IButtonWidget`, and the NC32 pure-backend
`IAPIWidgetV2` data path) so that Decidiq summary data appears on the Nextcloud
main dashboard. The widget MUST resolve the **current user's** data
(session-scoped, per-user — never an arbitrary object id) via the OpenRegister
`ObjectService`, and MUST fail soft: a broken or absent register MUST NOT crash
the Nextcloud dashboard.

**Feature tier**: MVP

#### Scenario: View Decidiq widget on Nextcloud dashboard

@e2e exclude nc-chrome — the Nextcloud main dashboard is platform chrome owned by the `dashboard` app and the Decidiq widget is server-rendered PHP (`OCP\Dashboard\IWidget`, no Decidiq-owned Vue surface); the widget logic (identity, per-user pending-votes + next-meeting resolution, fail-soft) is covered by PHPUnit in tests/Unit/Dashboard and tests/Unit/Service.

- GIVEN a user with Decidiq access
- WHEN they view the Nextcloud main dashboard
- THEN a "Decidiq" widget MUST be available showing the user's pending votes count and their next upcoming meeting
- AND the pending votes count MUST be the number of open voting-rounds the current user has not yet voted in (a user with no participant record sees 0)
- AND the next meeting MUST be the soonest future `lifecycle=scheduled` meeting the current user participates in (or an empty state when none)
- AND clicking the widget (its url or its "Open Decidiq" button) MUST navigate to the Decidiq app at `/apps/decidiq/`

#### Scenario: Widget fails soft when the register is unavailable

@e2e exclude nc-chrome — backend fail-soft path; covered by tests/Unit/Service/DashboardWidgetServiceTest and tests/Unit/Dashboard/DecidiqDashboardWidgetTest.

- GIVEN the OpenRegister `decidesk` register is absent or a schema read throws
- WHEN the Nextcloud dashboard requests the widget items for the current user
- THEN the widget MUST return an empty item set with an empty-content message
- AND it MUST NOT raise an exception to the Nextcloud dashboard

### Requirement: Running Processes Widget

The dashboard MUST include a `RunningProcessesWidget` component that shows motions currently in flight, grouped by their lifecycle stage.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display in-flight motions grouped by lifecycle

- GIVEN the user navigates to the dashboard
- WHEN the `RunningProcessesWidget` loads
- THEN it SHALL fetch motions with lifecycle in `[proposed, deliberating, voting]`
- AND display them grouped under labelled stage sections
- AND each motion entry SHALL show its title
- AND clicking a motion entry SHALL navigate to the motion detail view

#### Scenario: Empty state for no in-flight motions

- GIVEN no motions have lifecycle in `[proposed, deliberating, voting]`
- WHEN the widget loads
- THEN the widget SHALL display a message indicating no active motions

---

### Requirement: My Action Items Widget

The dashboard MUST include a `MyActionItemsWidget` component showing action items assigned to the current user that are open or in-progress, sorted by dueDate ascending.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display current user's open action items

- GIVEN the user has 3 action items assigned to them with taskStatus open or in-progress
- WHEN the `MyActionItemsWidget` loads
- THEN it SHALL display those action items sorted by dueDate ascending
- AND each item SHALL show its title, dueDate, and status badge
- AND items with dueDate in the past SHALL be visually distinguished as overdue

#### Scenario: No action items assigned to user

- GIVEN the user has no action items assigned to them with taskStatus open or in-progress
- WHEN the widget loads
- THEN the widget SHALL display an empty state message

---

### Requirement: Recent Decisions Widget

The dashboard MUST include a `RecentDecisionsWidget` component showing the latest N decisions (default 10) with outcome badge and publication status badge. Badge values MUST map the real schema enums (outcome: adopted/rejected/null; isPublished: internal/public/confidential).

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display recent decisions with badges

- GIVEN at least one decision exists in the register
- WHEN the `RecentDecisionsWidget` loads
- THEN it SHALL display decisions sorted by decisionDate descending, up to 10 entries
- AND each decision SHALL show its title, an outcome badge (adopted/rejected, or "undecided" when outcome is null — the Decision schema's outcome enum is [adopted, rejected]), and a publication status badge (internal/public/confidential)
- AND clicking a decision SHALL navigate to the decision detail view

---

### Requirement: Governance Health Widget

The dashboard MUST include a `GovernanceHealthWidget` component that renders a live two-series chart of `quorumPercentage` and `actionItemCompletionRate` from recent meetings' materialized fields. (A declarative manifest `type: "chart"` widget was ruled out: the lib's chart dataSource cannot assemble two live series — see the archived change's design.md Decision 5.)

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Render governance health chart from meeting data

- GIVEN at least two meetings with materialized `quorumPercentage` and `actionItemCompletionRate` fields exist
- WHEN the `GovernanceHealthWidget` loads
- THEN it SHALL render a chart with up to 12 recent meetings on the x-axis
- AND plot `quorumPercentage` as one live series and `actionItemCompletionRate` as another
- AND the data SHALL come from fetched meeting objects, never from hardcoded values

#### Scenario: Insufficient data state

- GIVEN fewer than two meetings with materialized governance health fields exist
- WHEN the widget loads
- THEN it SHALL display a "Not enough data" placeholder rather than an empty chart

---

### Requirement: Dashboard Empty State Component

The dashboard MUST include a `DashboardEmptyState` component shown when no governance body exists, guiding the user to initial setup.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display empty state for fresh installation

- GIVEN a fresh Decidiq installation with no governance bodies in the register
- WHEN the user navigates to the dashboard
- THEN the `DashboardEmptyState` component SHALL be rendered
- AND it SHALL display the message: "Welcome to Decidiq! Get started by setting up your first governing body in Settings."
- AND it SHALL show quick action buttons: "Set Up Body", "Create Meeting", "Create Decision"
- AND clicking "Set Up Body" SHALL navigate to the Settings page

---

### Requirement: Dashboard Refresh Behaviour

All dashboard widget components MUST respond to a dashboard-wide refresh signal without requiring a full page remount.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Widget refreshes when dashboard refresh is triggered

- GIVEN a dashboard widget is rendered and displaying data
- WHEN the user triggers a dashboard refresh (e.g., via a header refresh action)
- THEN each widget MUST re-execute its `load()` method and update its displayed data
- AND the refresh MUST NOT cause a full page remount or lose widget layout state

---

### Requirement: Widget i18n — English Source Keys

All user-visible strings in dashboard widget components MUST use `t('decidiq', '...')` with English source strings as keys.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: All widget labels use i18n

- GIVEN any dashboard widget component
- WHEN inspecting the component template and script
- THEN every user-visible string SHALL be wrapped in `t('decidiq', 'English source string')`
- AND no Dutch strings SHALL appear as raw text or as i18n keys

---

### Requirement: Upcoming Meetings KPI Widget

The dashboard MUST include an `UpcomingMeetingsKpiWidget` component showing the count of meetings with `lifecycle=scheduled` and `scheduledDate >= now`.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Display count of upcoming scheduled meetings

- GIVEN 3 meetings exist with lifecycle=scheduled and scheduledDate in the future
- WHEN the `UpcomingMeetingsKpiWidget` loads
- THEN it SHALL display a `CnStatsBlock` with count 3

---

### Requirement: Pending Votes KPI Widget

The dashboard MUST include a `PendingVotesKpiWidget` component showing the count of open voting-rounds where the current user has not yet cast a vote. The widget SHALL use `variant="warning"` when count > 0.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Count open voting rounds pending user's vote

- GIVEN 2 voting-rounds with lifecycle=open exist and the current user has voted in 1 of them
- WHEN the `PendingVotesKpiWidget` loads
- THEN it SHALL display count 1
- AND the `CnStatsBlock` SHALL use variant="warning"

#### Scenario: No pending votes

- GIVEN the current user has voted in all open voting-rounds (or none exist)
- WHEN the widget loads
- THEN count SHALL be 0 and variant SHALL be "default"

#### Scenario: User without participant record sees zero

- GIVEN open voting-rounds exist
- AND no participant record has `nextcloudUserId` matching the current user
- WHEN the `PendingVotesKpiWidget` loads
- THEN count SHALL be 0 and variant SHALL be "default" (the user is not a voting member)

---

### Requirement: Overdue Actions KPI Widget

The dashboard MUST include an `OverdueActionsKpiWidget` component showing the count of action items with `dueDate` in the past and `taskStatus` not in `[completed, cancelled]`. The widget SHALL use `variant="error"` when count > 0.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Count overdue action items

- GIVEN 2 action items have dueDate < now and taskStatus=open
- WHEN the `OverdueActionsKpiWidget` loads
- THEN it SHALL display count 2
- AND the `CnStatsBlock` SHALL use variant="error"

#### Scenario: No overdue action items

- GIVEN no action items are overdue
- WHEN the widget loads
- THEN count SHALL be 0 and variant SHALL be "default"

---

### Requirement: Upcoming Meetings List Widget

The dashboard MUST include an `UpcomingMeetingsListWidget` component listing upcoming meetings (lifecycle=scheduled, scheduledDate >= now) sorted by scheduledDate ascending. Meetings within the next 24 hours MUST be highlighted. Clicking a meeting entry MUST navigate to the meeting detail view.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: List upcoming meetings with 24h highlight

- GIVEN 3 meetings are scheduled in the future, one within the next 24 hours
- WHEN the `UpcomingMeetingsListWidget` loads
- THEN all 3 SHALL be listed sorted by scheduledDate ascending
- AND the meeting within 24 hours SHALL have a visual highlight indicating urgency
- AND each entry SHALL show: title, scheduledDate, governance body name, agenda item count

#### Scenario: Navigate to meeting detail on click

- GIVEN at least one upcoming meeting is listed
- WHEN the user clicks the meeting entry
- THEN the app SHALL navigate to the meeting detail view for that meeting

---

### Requirement: Pending Votes List Widget

The dashboard MUST include a `PendingVotesListWidget` component listing decisions/voting-rounds awaiting the current user's vote, with a deadline countdown. Entries with less than 24 hours remaining MUST show a red urgency indicator. An empty state MUST be shown when no pending votes exist.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: List decisions pending the user's vote with urgency

- GIVEN 2 voting-rounds are open and the user has not voted in either
- AND one has a deadline within 24 hours
- WHEN the `PendingVotesListWidget` loads
- THEN both SHALL be listed
- AND the entry with deadline < 24h SHALL show a red urgency indicator
- AND each entry SHALL show the associated decision/motion title and time remaining

#### Scenario: Empty state when no votes pending

- GIVEN the user has voted in all open voting-rounds or none exist, OR no participant record matches the current user
- WHEN the widget loads
- THEN the widget SHALL display "No pending votes" with a checkmark icon

#### Scenario: Navigate to voting interface on click

- GIVEN at least one pending vote is listed
- WHEN the user clicks the entry
- THEN the app SHALL navigate to the voting interface for that decision

---

### Requirement: Active Decisions KPI Widget

The dashboard MUST include an `ActiveDecisionsKpiWidget` component showing the count of decisions whose `outcome` is null (not yet adopted or rejected — the Decision schema has no lifecycle field). Clicking the card MUST navigate to the Decisions view.

**Feature tier**: MVP

@e2e exclude full-dashboard-only — widgets are component-only in this change; they are not wired into a renderable manifest surface (manifest wiring is owned by decidesk-dashboard-v2-layout), so browser rendering is covered there. Component/computed logic is covered by tests/vitest/dashboard/.

#### Scenario: Count undecided decisions

- GIVEN 3 decisions exist of which 1 has outcome "adopted" and 2 have no outcome
- WHEN the `ActiveDecisionsKpiWidget` loads
- THEN it SHALL display a `CnStatsBlock` with count 2

#### Scenario: Navigate to decisions view on click

- GIVEN the widget is rendered
- WHEN the user clicks the card
- THEN the app SHALL navigate to the Decisions view

## User Stories

1. **New board member accessing knowledge base**: As a new board member, I want to access all historical decisions, current action items, financial status, and governance documents so that I can quickly become effective in my role. (Source: intelligence DB #84)

2. **Institutional investor managing proxy voting across AGMs**: As an institutional investor, I want to manage proxy voting across all portfolio company AGMs from a single dashboard, so that I can efficiently exercise my voting rights at scale. (Source: intelligence DB #5)

3. **Administrator publishing meeting decisions**: As administrator, I want to publish key decisions from ALV and board meetings on the member portal so that all members stay informed about association governance. (Source: intelligence DB #76)

## Acceptance Criteria

- Dashboard uses CnDashboardPage with 12-column grid layout
- Four KPI cards show active decisions, upcoming meetings, pending votes, and overdue actions
- Pending votes widget shows urgency indicators with countdown
- Upcoming meetings widget shows meetings across all user's bodies
- Empty state shows setup guidance for new installations
- Nextcloud Dashboard widget registered via OCP\Dashboard\IWidget
- Quick action buttons in header for creating meetings and decisions
