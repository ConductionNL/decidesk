# verordeningenregister Specification

## Purpose
The regulations register (`regeling`/`regeling-versie`) needs its detail pages to surface what a griffie/clerk actually needs to answer "which text is in force, and on whose authority": the version history in date order with a link to the amending Decision, and the in-force status + CVDR identifier foregrounded rather than buried in a generic field list. This spec adds that register-optimised presentation on top of the `RegelingDetail` / `RegelingVersieDetail` page skeletons shipped by the `verordeningenregister` change (ADR-036 manifest v2).

## Requirements

### Requirement: REQ-VOR-009 Version timeline widget on RegelingDetail

The `RegelingDetail` page MUST render a `version-timeline` widget listing every `regeling-versie` object whose `regeling` reference points at the current object, ordered by effective date (`inwerkingtreding`, ascending), each entry showing version number, effective date, lapse date (if any), a status badge, and a resolved link to the amending Decision (`vastgesteldDoor`) that navigates to the existing Decision detail page.

#### Scenario: Regulation with three versions renders an ordered timeline
- GIVEN a `regeling` with three `regeling-versie` objects effective on 2024-01-01, 2024-06-01, and 2025-01-01
- WHEN the user opens `RegelingDetail` for that regeling
- THEN the version-timeline widget renders the three versions in ascending date order
- AND each entry shows its status badge and a link to its amending Decision

@e2e exclude the 3-item ascending-order algorithm is covered by tests/vitest/registerDetailWidgets.spec.js::"sortVersionsByEffectiveDate (REQ-VOR-009 / REQ-GDR-009)" → "sorts ascending by the configured effective-date field"; the Vue rendering mechanism (heading, list, status badges) is exercised by tests/e2e/spec-coverage/register-detail-widgets.spec.ts ("RegelingDetail: version-timeline widget renders both seeded versions of Afvalstoffenverordening Amsterdam") against the real 2-version seed chain — that test's own @e2e anchor still targets the pre-archival openspec/changes/register-detail-optimisation/... path so this gate does not match it. Neither test drives the amending-Decision LINK specifically (see the scenario below) — recorded here rather than reported as a total gap.

#### Scenario: Version entry links to its amending Decision
- GIVEN a `regeling-versie` with `vastgesteldDoor` set to a Decision object
- WHEN the user activates that version's Decision link in the timeline
- THEN the app navigates to the existing Decision detail page for that object

@e2e exclude no current e2e test clicks a version-timeline entry's amending-Decision link and asserts navigation to DecisionDetail; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Regulation with no versions yet renders an empty timeline state
- GIVEN a `regeling` with zero `regeling-versie` objects referencing it
- WHEN the user opens `RegelingDetail`
- THEN the version-timeline widget renders an empty-state message instead of an empty list or a loading spinner stuck indefinitely

@e2e exclude the underlying empty-input case is covered by tests/vitest/registerDetailWidgets.spec.js::"returns an empty array for non-array input"; no e2e test opens a zero-version regeling and asserts the Vue empty-state message renders (as opposed to a stuck spinner) — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

### Requirement: REQ-VOR-010 In-force status and CVDR identifier are foregrounded on RegelingDetail

`RegelingDetail` MUST present the regulation's in-force status and CVDR identifier prominently — in the first visual group of the data widget, not interleaved alphabetically with lower-priority fields — so a reader can answer "is this regulation currently in force, and under which CVDR number" without scanning the full field list.

@e2e exclude a DOM-field-ordering assertion — no current e2e test inspects the visual order of fields within the RegelingDetail data widget; genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: In-force regulation shows status and CVDR identifier first
- GIVEN a `regeling` with `status: "in-effect"` and a non-empty `cvdrIdentifier`
- WHEN the user opens `RegelingDetail`
- THEN the status badge and CVDR identifier render in the leading fields of the data widget

### Requirement: REQ-VOR-011 Computed in-force-date columns render formatted, not raw

The `Regelingen` index column showing the current in-force date (a value computed by `RegelingConsolidationService` and not declared as an OpenRegister schema property) MUST declare an explicit date-format hint on the column definition so it renders through the same locale-aware date formatter as schema-declared date fields, instead of an unformatted raw datetime string.

@e2e exclude no current e2e test opens the `Regelingen` index and asserts the computed current-in-force-date column renders formatted rather than raw; the same gap is tracked for `governing-documents-register`'s equivalent REQ-GDR-010 scenario and `index-page-rendering-quality`'s generic date-formatting requirement — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Current-in-force-date column renders a formatted date
- GIVEN a `regeling` whose computed current-in-force date is `2025-03-01T00:00:00Z`
- WHEN the user views the `Regelingen` index
- THEN the current-in-force-date column renders a locale-formatted date (e.g. `01-03-2025`)
- AND does not render the raw ISO/SQL datetime string
