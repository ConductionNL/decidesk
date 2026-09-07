---
status: done
---

# app-navigation Specification

**Status**: done
**Scope**: decidiq
**OpenSpec changes**:
- ia-six-item-nav (archived) — restructures the menu to ADR-004's six-item, mode-aware IA
- ia-six-clusters (archived) — collapses the nav from 44 fragment-accreted top-level entries back to ADR-004's six-item ceiling (Dashboard, Meetings, Decisions, Tasks & Commitments, Organisation, Registers) via `src/menu-layout.json`'s relocation/removal/settings-lift mechanism
- nav-ceiling-gate (archived) — adds a CI gate enforcing the six-item ceiling and requiring every manifest.d fragment's top-level menu entry to be explicitly placed in menu-layout.json

## Purpose
Defines the app shell layout, navigation menu, and routing. App.vue renders loading, no-OpenRegister, and ready states; the main menu presents ADR-004's six-item information architecture with mode-aware label resolution; the history-mode router exposes flat named routes (keeping demoted surfaces reachable by deep link); and store initialisation registers all entity types. It also surfaces Motions as a filtered view of Decisions rather than a standalone top-level item.

## Requirements

### Requirement: REQ-NAV-001 App.vue provides root layout with three states
`App.vue` SHALL use `NcContent` as the root layout element and SHALL render one of three states: (1) loading — `NcLoadingIcon` while settings are fetched; (2) no-OpenRegister — `NcEmptyContent` if `openRegisters` is false; (3) ready — `MainMenu` + `NcAppContent` + `router-view` with optional `CnIndexSidebar`.

#### Scenario: Settings load successfully with OpenRegister
- **WHEN** `initializeStores()` completes and `openRegisters` is `true`
- **THEN** the full app layout with MainMenu and router-view is rendered

#### Scenario: Settings load without OpenRegister
- **WHEN** `initializeStores()` completes and `openRegisters` is `false`
- **THEN** `NcEmptyContent` is shown with a message to install OpenRegister

#### Scenario: Settings are loading
- **WHEN** the app is mounted and the settings fetch has not completed
- **THEN** only `NcLoadingIcon` is displayed

---

### Requirement: REQ-NAV-002 MainMenu lists six canonical top-level groups, populated via menu-layout.json

`MainMenu` SHALL render the navigation via `CnAppRoot`/`CnAppNav` from the
merged manifest `menu` (`src/manifest.json` + every `src/manifest.d/*.json`
fragment + `src/menu-layout.json`, per `buildManifest()`). The primary
(non-footer, non-settings) top-level entries SHALL be exactly six, matching
ADR-004's v2 table: **Dashboard** (landing, route `Dashboard`), **Meetings**
(route `Meetings`), **Decisions** (route `Decisions`), **Tasks &
Commitments** (id `ActionItems`, route `ActionItems` — label renamed from
"Action items"), **Organisation** (id `GovernanceBodies`, route
`GovernanceBodies` — label renamed from "Bodies"), and **Registers** (id
`Registers`, a route-less group anchor — a pure expandable parent with no
page of its own). Every fragment-declared top-level entry that is not one of
these six base ids SHALL be relocated under one of them (or the settings
gear, or removed) via `src/menu-layout.json`, never left as a 7th+ primary
entry. Motions SHALL NOT appear as a standalone top-level item (unchanged
from REQ-RMN-001). "Beheer" (operator-only configuration) is NOT a menu
item in v2 — it is served by the Nextcloud settings framework
(`/settings/admin/decidiq`, ADR-079 Decision 1) plus the `NcAppNavigationSettings`
gear foldout (ADR-079 Decision 2), not a nav row.

#### Scenario: Exactly six primary top-level items are rendered

- WHEN the app is in the ready state
- THEN the main menu shows exactly six primary (non-footer, non-settings)
  items: Dashboard, Meetings, Decisions, Tasks & Commitments, Organisation,
  Registers
- AND no 7th primary top-level item is present

#### Scenario: Organisation item is present and routes to GovernanceBodies

- WHEN the app is in the ready state
- THEN an Organisation navigation item is visible
- AND clicking it navigates to the `GovernanceBodies` route
  (`/governance-bodies`)
- AND its underlying menu id remains `GovernanceBodies` (label-only rename)

#### Scenario: Registers is a route-less expandable group

- WHEN the app is in the ready state
- THEN a Registers navigation item is visible with no `route`/`href`/`action`
  of its own
- AND clicking its title toggles its children open/closed rather than
  navigating

#### Scenario: Active route is highlighted

- WHEN the user is on the `/meetings` route
- THEN the Meetings navigation item is marked as active

### Requirement: REQ-NAV-003 Router uses history mode with flat named routes
The router SHALL operate in history mode with base `/index.php/apps/decidiq/`.
All routes SHALL be named and flat (no nesting). A catch-all `*` route SHALL
redirect to `/`. Routes for demoted surfaces (Minutes, Workspaces, Engagement)
SHALL remain registered even though they no longer appear as top-level menu items.

Required named routes (non-exhaustive; demotion does not remove routes):
- `Dashboard` → `/`
- `MeetingList` / `Meetings` → `/meetings`
- `MeetingDetail` → `/meetings/:id`
- `MotionList` / `Motions` → `/motions`
- `MotionDetail` → `/motions/:id`
- `DecisionList` / `Decisions` → `/decisions`
- `DecisionDetail` → `/decisions/:id`
- `ActionItems` → `/action-items`
- `GovernanceBodies` → `/governance-bodies`
- `GovernanceBodyDetail` → `/governance-bodies/:id`
- `Minutes` → `/minutes` (retained; demoted from menu)
- `Workspaces` → `/workspaces` (retained; demoted from menu)
- `Engagement` → `/engagement` (retained; demoted from menu)
- `Settings` → `/settings`

#### Scenario: Unknown route redirects to dashboard
- WHEN a user navigates to an undefined path (e.g. `/unknown`)
- THEN the router redirects to `/`

#### Scenario: Demoted surface remains reachable by deep link
- WHEN a user navigates directly to `/minutes`, `/workspaces`, or `/engagement`
- THEN the corresponding page renders even though the item is not in the top menu

#### Scenario: Detail route receives entity ID as prop
- WHEN the router matches `/meetings/abc-123`
- THEN the MeetingDetail component receives `entityId = 'abc-123'` as a prop

### Requirement: REQ-NAV-004 Store initialisation registers all 17 entity types
`store/store.js` SHALL export `initializeStores()` which fetches settings and calls `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each of the 17 entities defined in ADR-000: ActionItem, AgendaItem, Amendment, Decision, DigitalDocument, GovernanceBody, Meeting, Minutes, MonetaryAmount, Motion, Offer, Order, Participant, Product, Report, Vote, VotingRound.

#### Scenario: All stores registered on init
- **WHEN** `initializeStores()` is called
- **THEN** all 17 entity types are available in the object store without additional setup

#### Scenario: Settings fetch failure is handled
- **WHEN** the settings endpoint returns an error
- **THEN** `initializeStores()` surfaces the error to the UI via the settings store state

---

### Requirement: REQ-NAV-005 Settings page structure
The Settings page SHALL render in order: `CnVersionInfoCard` (first, always), `CnRegisterMapping`, then one `CnSettingsSection` per configurable feature area. A "Re-import register" button SHALL call `POST /api/settings/load`.

#### Scenario: Version card is always first
- **WHEN** the settings page is rendered
- **THEN** `CnVersionInfoCard` is the first visible element on the page

#### Scenario: Re-import button triggers register reload
- **WHEN** the user clicks the "Re-import register" button
- **THEN** a POST request is sent to `/apps/decidiq/api/settings/load` and a success notification is shown

### Requirement: REQ-NAV-006 Mode-aware label resolution at the translate chokepoint
The app shell SHALL resolve every navigation label through a declarative
mode-keyed label map BEFORE applying the i18n translation. The label map
(`src/config/modeLabels.js`) SHALL be a static object keyed by
`organisatie-modus` (`gov` / `corp` / `assoc` / `ops` / `citizen`), each mapping a
canonical English label to a mode-specific label key. The `translate` function
passed to `CnAppRoot` (`translateForApp` in `App.vue`) SHALL look up the canonical
label in the map for the active mode, fall back to the canonical label when no
mapping exists, and pass the result to `t('decidiq', …)`. The navigation
structure SHALL NOT branch per mode — only the displayed label SHALL change.

#### Scenario: Bodies item relabels by mode
- GIVEN `organisatie_modus` is `gov`
- WHEN the navigation renders the Bodies item
- THEN its label resolves to "Fracties & Organen"
- AND WHEN `organisatie_modus` is `corp` THEN the same item's label resolves to "Board"
- AND WHEN `organisatie_modus` is `ops` THEN the same item's label resolves to "Teams"

#### Scenario: Unmapped label falls back to canonical
- GIVEN a menu item whose canonical label has no entry in the active mode's map
- WHEN the navigation renders that item
- THEN the canonical label is passed to `t('decidiq', …)` unchanged

#### Scenario: Default mode keeps governance labels
- GIVEN no `organisatie_modus` is configured
- WHEN the navigation renders
- THEN the default mode `gov` applies and existing Dutch governance labels are shown

### Requirement: REQ-RMN-001 — Retire the standalone Motions top-level menu item

The system SHALL remove the standalone **Motions** top-level navigation item from the decidiq
`src/manifest.json` `menu` so that Motions is no longer presented as a sibling of Decisions.
The removed entry is the `menu` object `{ id: "Motions", label: "Motions", route: "Motions",
order: 50 }`. The removal SHALL follow the repo's established demote-not-delete pattern (the
`ia-six-item-nav` precedent: delete the `menu` array entry only, leaving the corresponding
page and route registered, per the ADR-037 declarative-manifest conventions). No other
top-level menu item SHALL be removed or reordered by this change.

#### Scenario: Top navigation no longer shows a standalone Motions item

- GIVEN the decidiq app is in the ready state
- WHEN the top-level navigation renders from the manifest `menu`
- THEN no menu item whose route is `Motions` is shown
- AND the Dashboard, Meetings, Decisions, Action items, and Bodies items remain present

#### Scenario: The Motions menu entry is removed from the manifest, not merely hidden

- GIVEN `src/manifest.json`
- WHEN the `menu` array is inspected after this change
- THEN it contains no entry with `id: "Motions"` / `route: "Motions"`

---

### Requirement: REQ-RMN-002 — Keep the Motions page and routes reachable for deep links

The system SHALL keep the `Motions` page (`pages[]` id `Motions`, route `/motions`, type
`index`, `config.filter.decisionType = "motion"`) and the motion detail surfaces
(`MotionDetail` at `/motions/:id` and `MotionIntegrations` at `/motions/:id/integrations`)
registered and routable after the menu item is retired. Removing the top-level menu item
SHALL NOT remove or rename any page or route, so deep links, bookmarks, and the Motions-index
action navigations (the `view` action routing to `MotionDetail` and the `Discussion` action
routing to `MotionIntegrations`) continue to resolve — exactly the demote-not-delete behaviour
established by `ia-six-item-nav`.

#### Scenario: The Motions deep link still renders after the menu item is retired

- GIVEN the standalone Motions menu item has been removed
- WHEN a user navigates directly to `/motions`
- THEN the Motions index page renders, filtered to `decisionType = motion`

#### Scenario: Motion detail deep link still resolves

- GIVEN a motion with id `abc-123`
- WHEN a user navigates directly to `/motions/abc-123`
- THEN the `MotionDetail` page renders for that motion

#### Scenario: Motions-index actions still navigate

- GIVEN the user is on the retained `/motions` index page
- WHEN the user triggers the row `view` action
- THEN the app navigates to the `MotionDetail` route for that row

---

### Requirement: REQ-RMN-003 — Surface Motions as a filtered view of Decisions

The system SHALL surface Motions as a filtered view of Decisions reachable from the
`Decisions` surface, scoped to `decisionType = motion`, so that Motions is discoverable under
its parent concept rather than as a sibling top-level item. The filtered view SHALL be
declared declaratively in `src/manifest.json` (a quick-filter / sub-view link on the
`Decisions` index, per ADR-037) and SHALL resolve to the retained `Motions` page (`/motions`),
filtering over the STORED `decisionType` field (no client-side derivation). The change SHALL
NOT introduce a new page or duplicate the existing Motions index, and SHALL NOT alter the
`decision` schema or the `decisionType` enum (Motion is already a Decision subtype per
ADR-005).

#### Scenario: A Motions filtered view is reachable from Decisions

- GIVEN the user is on the `Decisions` surface
- WHEN the user selects the Motions filtered view (`decisionType = motion`)
- THEN the app shows Decisions filtered to `decisionType = motion`, resolving to the retained Motions page

#### Scenario: The filtered view reuses the existing page, not a new one

- GIVEN `src/manifest.json` after this change
- WHEN the pages and menu are inspected
- THEN no new Motions page is added — the Decisions filtered view links to the existing `Motions` page (`/motions`)

#### Scenario: No schema change accompanies the nav change

- GIVEN the change is applied
- WHEN the `decision` schema and its `decisionType` enum are inspected
- THEN they are unchanged — Motions remain Decisions with `decisionType = motion`

---

### Requirement: REQ-NAV-009 Fragment leaf entries relocate into a canonical group via menu-layout.json relocations

`src/menu-layout.json#relocations` SHALL map each fragment top-level menu
entry id that belongs under an existing base group to that group's id, so
`applyMenuRelocations()` re-homes the leaf as a child of the group and the
leaf disappears from the primary top-level list. The mapping SHALL be:

| Group (id) | Relocated leaf ids |
|---|---|
| `Meetings` | `MondelingeVragen`, `Interpellaties`, `IngekomenStukken`, `Raadsinformatiebrieven`, `KascommissieVerklaringen` |
| `Decisions` | `Raadplegingen`, `Consultations`, `WorksCouncilConsultations`, `Adviesaanvragen`, `Zienswijzerondes` |
| `ActionItems` | `Toezeggingen`, `Termijnagenda`, `PCCycli`, `Goals` |
| `GovernanceBodies` | `Roosters`, `Nevenfuncties`, `Geschenken`, `MemberOnboarding`, `MemberOffboarding`, `ProxyAuthorizations` |
| `Registers` | `Regelingen`, `GoverningDocuments`, `Bevoegdheidstoedelingen`, `Geheimhoudingen` |

Nominations carry no relocation: the `Voordrachten` menu entry was retired
outright by `appointment-decision-type-schema` (a nomination IS a decision,
`decisionType=appointment` per ADR-005 — appointments live in the Decisions
register as a typed filter, not as their own entry). A `Voordrachten`
relocation existed briefly between the two changes landing and was pruned
once the fold retired the entry. `Goals` is a forward declaration: the id is introduced by the
parallel change `organisation-goals` (fragment
`src/manifest.d/organisation-goals.json`); `applyMenuRelocations()` is a
no-op for a source id absent from the merged menu, so declaring the
placement before the fragment lands is safe and keeps the nav-ceiling gate
green in either merge order.

The relocated leaf's own page and route SHALL remain unchanged; only its
position in the rendered menu changes.

#### Scenario: A fragment leaf renders as a child of its target group

- GIVEN `src/menu-layout.json#relocations` maps `MondelingeVragen` to
  `Meetings`
- WHEN the merged menu is built
- THEN `MondelingeVragen` does not appear as a primary top-level entry
- AND `MondelingeVragen` appears as a child of the `Meetings` group
- AND navigating directly to its page route still renders the page

#### Scenario: A relocated leaf's page stays routable

- GIVEN any id listed as a relocation source
- WHEN a user navigates directly to that entry's page route
- THEN the page renders identically to before the relocation

### Requirement: REQ-NAV-010 Duplicate or filter-chip nav rows are removed but stay routable

`src/menu-layout.json#removals` SHALL list menu entry ids that are retired
as top-level (or relocated) nav rows because they duplicate a filter,
scope, or another entry's function, while their underlying page remains
registered and reachable by direct route for deep links and e2e specs. The
list SHALL be: `UrgentDecisions` (a filter chip on Decisions, not a
distinct register), `MyDeclarations` (an unscoped duplicate of
`Nevenfuncties`), `Zienswijzen` (a child facet of `Zienswijzerondes`), and
`FeaturesRoadmapMenu` (footer entry retired from the nav; its `FeaturesRoadmap`
page stays routable).

#### Scenario: A removed entry does not render anywhere in the menu

- GIVEN `FeaturesRoadmapMenu` is listed in `menu-layout.json#removals`
- WHEN the merged menu is built
- THEN no primary, footer, or settings entry with id `FeaturesRoadmapMenu`
  is present

#### Scenario: A removed entry's page remains routable

- GIVEN `FeaturesRoadmapMenu` has been removed from the menu
- WHEN a user navigates directly to the `FeaturesRoadmap` page route
- THEN the page renders as before

### Requirement: REQ-NAV-011 Operator/definition config entries lift into the settings gear foldout

`src/menu-layout.json#settingsSection` SHALL list menu entry ids for
operator-only definition/configuration surfaces that do not warrant a
primary top-level slot, so `applySettingsSection()` tags each with
`section: "settings"` and moves it into the `NcAppNavigationSettings` gear
foldout rather than the scrollable primary nav — consistent with ADR-079's
model of keeping non-operational configuration out of the primary working
surface (ADR-004 Rule 4, "Beheer is operator-only"). The list SHALL be:
`Termijnregelingen`, `VveDecisionTemplates`, `ModelreglementPresets`,
`VveConfigurations`, `WooDiwoo`, `GeheimhoudingGronden`, `ModerationQueue`,
`UserSettingsMenu`.

#### Scenario: A listed entry renders inside the settings gear, not the primary nav

- GIVEN `Termijnregelingen` is listed in
  `menu-layout.json#settingsSection`
- WHEN the merged menu is built
- THEN `Termijnregelingen` does not appear as a primary top-level entry or
  as a child of any primary group
- AND `Termijnregelingen` appears inside the `NcAppNavigationSettings` gear
  foldout

#### Scenario: A lifted entry's page stays routable

- GIVEN any id listed in `settingsSection`
- WHEN a user navigates directly to that entry's page route
- THEN the page renders identically to before the lift

### Requirement: REQ-NAV-012 The settings gear carries no ambiguous duplicate entries

`src/manifest.json` SHALL set `nav.includePersonalSettings: false` so
`CnAppNav`'s auto-prepended generic "Personal settings" entry (which opens
`NcAppSettingsDialog` via a route-less `#` click handler) does not render.
Nextcloud's own standard "Personal settings" link-out to `/settings/user`
remains (it is shell-provided and not suppressible), so decidiq's own
`UserSettingsMenu` fragment entry (the real `/user-settings` route
rendering `UserSettingsPage` — notification/display/delegation/
communication sections) SHALL be labelled **"Preferences"** so no two gear
entries share a label. The gear SHALL therefore show at most one entry per
label: "Personal settings" (Nextcloud, `/settings/user`), "Preferences"
(decidiq, `/user-settings`), plus the admin-gated admin-settings link-out.

#### Scenario: No two gear entries share a label

- WHEN the settings gear foldout is opened
- THEN no two entries carry the same label
- AND the entry labelled "Preferences" navigates to `/user-settings`

#### Scenario: The generic auto-prepended entry is suppressed

- GIVEN `nav.includePersonalSettings` is `false` in `src/manifest.json`
- WHEN the merged manifest is built
- THEN `CnAppNav` does not auto-prepend its own route-less `#` "Personal
  settings" entry

### Requirement: REQ-NAV-007 The primary top-level navigation is mechanically capped at the ADR-004 ceiling

A CI check SHALL rebuild the effective top-level menu the same way
`src/main.js`'s `buildManifest` pipeline does — merging `src/manifest.json`
with every `src/manifest.d/*.json` fragment, then applying
`src/menu-layout.json`'s relocations, removals, and settings-section lift —
and SHALL fail when the number of primary top-level entries (entries with no
`section`, i.e. neither `"footer"` nor `"settings"`) exceeds 6.

#### Scenario: A merged menu at or under the ceiling passes

- GIVEN the effective merged menu has 6 or fewer primary top-level entries
- WHEN the nav-ceiling check runs
- THEN it reports pass and prints the primary, footer, and settings entry counts

#### Scenario: A merged menu over the ceiling fails

- GIVEN the effective merged menu has 7 or more primary top-level entries
- WHEN the nav-ceiling check runs
- THEN it exits non-zero
- AND the failure output names the ceiling (6), the actual primary count, and the offending entry ids

#### Scenario: Footer and settings entries do not count against the ceiling

- GIVEN the merged menu includes entries carrying `section: "footer"` or `section: "settings"`
- WHEN the nav-ceiling check computes the primary count
- THEN those entries are excluded from the primary count and reported separately

### Requirement: REQ-NAV-008 Every fragment top-level menu entry must be explicitly placed

For every `src/manifest.d/*.json` fragment, each entry in that fragment's
top-level `menu` array SHALL be considered "placed" only if at least one of
the following holds: (a) the entry itself declares `section: "footer"` or
`section: "settings"`; (b) the entry's `id` is a key in
`src/menu-layout.json#relocations`; (c) the entry's `id` is listed in
`src/menu-layout.json#removals`; (d) the entry's `id` is listed in
`src/menu-layout.json#settingsSection`. The nav-ceiling check SHALL fail when
any fragment declares a top-level menu entry that is not placed, independent
of whether the ceiling in REQ-NAV-007 is currently exceeded — an unplaced
entry is a defect even while the count happens to stay at or under 6.

#### Scenario: An unplaced fragment entry fails even under the ceiling

- GIVEN a fragment declares a new top-level menu entry with an id that appears in none of `relocations`, `removals`, or `settingsSection`, and is not self-scoped to `section: "footer"`/`"settings"`
- AND the resulting primary count is still 6 or fewer
- WHEN the nav-ceiling check runs
- THEN it exits non-zero and names the fragment file and the unplaced entry id

#### Scenario: A relocated fragment entry is placed

- GIVEN a fragment declares a top-level menu entry whose id is a key in `menu-layout.json#relocations`
- WHEN the nav-ceiling check runs
- THEN that entry is treated as placed and does not trigger an unplaced-entry failure

#### Scenario: A removed fragment entry is placed

- GIVEN a fragment declares a top-level menu entry whose id is listed in `menu-layout.json#removals`
- WHEN the nav-ceiling check runs
- THEN that entry is treated as placed and does not trigger an unplaced-entry failure

#### Scenario: A settings-lifted fragment entry is placed

- GIVEN a fragment declares a top-level menu entry whose id is listed in `menu-layout.json#settingsSection`, OR the entry itself declares `section: "settings"`
- WHEN the nav-ceiling check runs
- THEN that entry is treated as placed and does not trigger an unplaced-entry failure

### Requirement: REQ-NAV-013 The nav-ceiling check carries a positive control

The nav-ceiling check's test suite SHALL include at least one fixture that
proves the check can fail: an in-memory fragment fixture with an unplaced
top-level menu entry, asserted to produce a non-zero result naming that
entry. A check whose test suite only ever exercises passing fixtures is
unproven — a check that never ran would look identical.

#### Scenario: The positive-control fixture fails the check

- GIVEN the positive-control fixture (a base menu, one fragment with one unplaced top-level entry, and an empty `menu-layout.json`)
- WHEN the check's evaluation function runs against that fixture
- THEN it reports at least one failure naming the fixture's unplaced entry id

#### Scenario: Placing the same entry clears the positive-control failure

- GIVEN the positive-control fixture from the previous scenario
- WHEN the fixture's unplaced entry id is added to `relocations`, `removals`, or `settingsSection`
- AND the check's evaluation function runs again
- THEN it reports no failure for that entry
