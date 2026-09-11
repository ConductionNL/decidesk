# index-page-rendering-quality Specification

## Purpose
Defines the rendering-correctness contract for decidiq's manifest-driven index pages (`type:"index"` pages built on `CnIndexPage`/`CnDataTable`/`CnCellRenderer` from `@conduction/nextcloud-vue`): every reference field SHALL resolve to a readable label, every numeric and date/datetime column SHALL render through its correct formatter, every index page SHALL reach a terminal loading state (table or empty state, never a perpetual spinner), quick-filter labels SHALL render intact, the first-run walkthrough SHALL target real, resolvable elements, and seed/fixture data SHALL NOT accumulate unbounded on the shared instance. This capability governs the manifest column-declaration layer only — it does not own the underlying library rendering primitives (`CnCellRenderer`, `CnFkResolveCell`, `liveUpdatesPlugin`), which are `@conduction/nextcloud-vue`'s.

## Requirements

### Requirement: REQ-001: Reference columns resolve to a readable label

Every index-page column whose schema property is a reference to another OpenRegister object (`format: "uuid"` with an `x-openregister-ref`/`$ref` target, or a name-hinted reference field such as a governance body, person, or decision) SHALL declare `widget: "fkResolve"` with `widgetProps: {register, schema, labelField}` naming the referenced register/schema/display field. The column SHALL render the referenced object's resolved label for both a UUID-keyed reference and a slug-keyed reference (the seed importer stores some references as raw slugs, not UUIDs — both SHALL resolve). A reference value that does not resolve to any object (including the literal nil UUID `00000000-0000-0000-0000-000000000000` used as an unset placeholder in some seed examples) MAY still render as its raw id — this is a fallback, not a passing case, and SHALL be tracked as residual seed-data debt rather than silently accepted as correct.

@e2e exclude this requirement governs `CnFkResolveCell`/`CnCellRenderer`, rendering primitives owned by `@conduction/nextcloud-vue` (see this capability's own Purpose: "does not own the underlying library rendering primitives"), not decidiq's own UI code; no decidiq e2e test isolates the UUID-vs-slug reference-resolution distinction this scenario names — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277 (many index pages incidentally exercise fkResolve columns, e.g. tests/e2e/spec-coverage/facets-organisation-detail.spec.ts, but none asserts this specific UUID/slug equivalence).

#### Scenario: A UUID reference column resolves to a name
- **GIVEN** an index page column bound to a schema property that is a reference to a `governance-body` object, with a valid UUID value
- **WHEN** the row renders
- **THEN** the cell SHALL show the governance body's name, not the raw UUID

#### Scenario: A slug-stored reference column resolves to a name
- **GIVEN** an index page column bound to a reference property whose stored value is a slug (e.g. `"gemeenteraad-amsterdam"`) rather than a UUID
- **WHEN** the row renders
- **THEN** the cell SHALL show the referenced object's name, exactly as it would for a UUID-keyed value

### Requirement: REQ-002: Integer year/financial-year columns render without a thousands separator

Any index-page column bound to a schema property that represents a calendar or financial year (e.g. `year`, `boekjaar`) SHALL render as plain digits (`"2026"`), never grouped (`"2,026"`). The column SHALL declare an app-registered `formatter` that renders the raw integer without `Intl.NumberFormat` grouping.

@e2e exclude no current e2e test opens the P&C cycles index and asserts the Year column renders ungrouped digits; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: A year column renders without grouping
- **GIVEN** a P&C cycle object with `year: 2026`
- **WHEN** the P&C cycles index table renders the Year column
- **THEN** the cell SHALL show "2026", not "2,026"

### Requirement: REQ-003: Date and datetime columns render through the shared date formatter

Every index-page column bound to a schema property of type `date`/`date-time` (by schema `format`, or a computed/convenience field with no matching schema property) SHALL render through `CnCellRenderer`'s date path (`NcDateTime`), never as a raw ISO or SQL-style timestamp string. Columns bound to a schema property lacking an explicit `format` SHALL declare a column-level `format` hint so the renderer can still apply date formatting.

@e2e exclude this requirement governs `CnCellRenderer`'s date path (`NcDateTime`), a rendering primitive owned by `@conduction/nextcloud-vue`, not decidiq's own UI code (see this capability's own Purpose); the same untested gap is recorded per-register in `verordeningenregister`'s REQ-VOR-011 and `governing-documents-register`'s REQ-GDR-010 scenarios — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: A raw-looking timestamp field renders formatted
- **GIVEN** an index column bound to a datetime-typed field with no schema-level `format` declared
- **WHEN** the row renders
- **THEN** the cell SHALL show a formatted date/time (relative or localized), never a literal string like `"2025-03-01 00:00:00"`

### Requirement: REQ-004: An index page always reaches a terminal loading state

An index page SHALL, within a bounded time after mount, show either its data table (populated or not) or its empty-state — it SHALL NOT remain on the loading spinner indefinitely regardless of live-update subscription activity. A page whose live-update subscription cannot safely coexist with its initial fetch (per the known `liveUpdatesPlugin` race — see Notes) SHALL opt out via `config.subscribe: false` until the underlying library defect is fixed upstream.

@e2e exclude this requirement governs `liveUpdatesPlugin`'s interaction with `CnIndexPage`'s initial fetch, a rendering-primitive concern owned by `@conduction/nextcloud-vue` (see this capability's own Purpose); many decidiq index-page e2e tests incidentally load past the spinner without asserting the terminal-state guarantee itself — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: A zero-row index page shows the empty state, not a stuck spinner
- **GIVEN** an index page bound to a schema with zero objects
- **WHEN** the page loads
- **THEN** the page SHALL show its empty-state content within a bounded time, not remain on the loading spinner

#### Scenario: A populated index page with live-updates disabled still loads
- **GIVEN** an index page configured with `subscribe: false`
- **WHEN** the page loads
- **THEN** the page SHALL show its data table populated from the initial fetch, and SHALL NOT issue any bare (unbounded) collection refetch

### Requirement: REQ-005: Quick-filter chip/dropdown labels render intact

A quick-filter tab's `label` text SHALL render as one uninterrupted string in both chips and dropdown presentation modes — never split mid-word.

@e2e exclude a CSS/layout wrapping assertion (word-break behaviour at arbitrary viewport widths) — tests/e2e/spec-coverage/urgent-decision-procedure.spec.ts drives a quick-filter but does not assert text-wrapping behaviour at multiple viewport widths; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: A quick-filter label with a short word renders intact
- **GIVEN** a quick-filter tab labelled `"All urgent"` in dropdown mode
- **WHEN** the filter control renders at any supported viewport width
- **THEN** the visible text SHALL read `"All urgent"`, not split across a line/word boundary

### Requirement: REQ-006: The first-run walkthrough targets resolvable, current elements

Every step in `manifest.json`'s `walkthrough.tours[]` SHALL target an element/page/nav-item that resolves against the current navigation structure. Copy referencing app structure (cluster names, feature descriptions) SHALL be reviewed after any navigation-restructuring change.

@e2e exclude tests/e2e/global-setup.ts deliberately SUPPRESSES the first-visit walkthrough for the whole e2e suite (sets `cn-walkthrough-seen:decidesk` in localStorage before every test, to avoid the full-viewport dim overlay blocking other assertions), so no e2e test ever renders the walkthrough or exercises `CnWalkthrough.resolveTarget()` — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277; a dedicated test would need to bypass that suite-wide suppression.

#### Scenario: Every walkthrough step target resolves
- **GIVEN** the `decidesk:getting-started` tour's four steps
- **WHEN** `CnWalkthrough.resolveTarget()` is evaluated for each step against the current app shell
- **THEN** every step SHALL resolve to a real, visible DOM element (or, for `kind:"page"` steps, a valid route)

### Requirement: REQ-007: Seed/example objects carry their required display fields

Every object provisioned by `tests/e2e/ci-seed.sh` from a schema's `example` block SHALL populate that schema's title/display field (e.g. `citeertitel`, `title`). An index column bound to that field SHALL NOT render the empty-value dash ("—") for a seed-provisioned object.

@e2e exclude a CI-fixture-quality assertion about `tests/e2e/ci-seed.sh`'s own seed data, not app runtime behaviour — no dedicated test opens the Governing documents index and asserts no seed-provisioned row shows the empty-value dash; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: A seeded governing document has a non-empty title
- **GIVEN** the governing-documents-register schema's seed `example` objects
- **WHEN** the Governing documents index renders its title column
- **THEN** no seed-provisioned row SHALL show "—" for its title

### Requirement: REQ-008: E2E specs that create objects on the shared instance are namespaced and cleaned up

Any Playwright spec under `tests/e2e/spec-coverage/` that creates an OpenRegister object directly (not via `ci-seed.sh`'s schema-example provisioning) SHALL give the created object a name/title carrying a stable, greppable marker, and SHALL remove the object in an `afterEach`/`afterAll` hook when the spec run owns it.

@e2e exclude a meta-contract about how Playwright specs themselves are authored (naming + `finally`-block cleanup discipline, as this repo's own `facets-meeting-detail.spec.ts`/`facets-decision-detail.spec.ts` already follow) — this is enforced by code review of spec files, not itself a runtime UI behaviour a browser test can assert.

#### Scenario: A spec-created object is cleaned up after the test
- **GIVEN** a Playwright spec that creates a `meeting` object via the UI or API
- **WHEN** the spec (or its suite) completes
- **THEN** the created object SHALL be deleted, or SHALL carry the documented marker so it can be identified for a deliberate, scoped cleanup later
