# delegatie-mandaatregister Specification

## Purpose
A `bevoegdheidstoedeling` (delegation/mandate) can sit inside an ondermandaat chain — a mandate re-delegated one or more levels down via `parentAllocation` — and the reader needs to see that chain, not just the single record, to answer "who ultimately authorised this, and who else was authorised under the same original grant." This spec adds a chain widget to `BevoegdheidstoedelingDetail` on top of the page skeleton the `delegatie-mandaatregister` change already shipped.

## Requirements

### Requirement: REQ-DMR-008 Ondermandaat chain widget on BevoegdheidstoedelingDetail

The `BevoegdheidstoedelingDetail` page MUST render a `delegation-chain` widget showing: the ancestor chain walked up via `parentAllocation` (the current toedeling's parent, grandparent, and so on to the root grant) as a breadcrumb, and the direct-child ondermandaten walked down (toedelingen whose `parentAllocation` points at the current object) as a list — plus the source `decision` link and resolved delegans/delegataris display. The chain walk MUST terminate safely on a cycle (defensive — the underlying data model forbids cycles, but the widget MUST NOT infinite-loop if one is ever present) and MUST NOT re-fetch the same object twice within one render.

#### Scenario: Third-level ondermandaat shows its full ancestor breadcrumb
- GIVEN a root `bevoegdheidstoedeling` A, a level-2 toedeling B with `parentAllocation` = A, and a level-3 toedeling C with `parentAllocation` = B
- WHEN the user opens `BevoegdheidstoedelingDetail` for C
- THEN the chain widget's breadcrumb shows A → B → C in that order

@e2e exclude the underlying `walkAncestors` algorithm is covered by tests/vitest/registerDetailWidgets.spec.js ("walkAncestors (REQ-DMR-008 ondermandaat ancestor breadcrumb)" → "walks a 3-level chain root-first, excluding the start object" — asserts A→B ordering for a 3-level chain); tests/e2e/spec-coverage/register-detail-widgets.spec.ts only exercises a 2-level parent/child pair, not a 3-level breadcrumb — the Vue rendering of the breadcrumb itself (as opposed to the pure-function ordering) has no e2e assertion.

#### Scenario: A toedeling with sub-mandates lists them
- GIVEN a `bevoegdheidstoedeling` with two other toedelingen whose `parentAllocation` points at it
- WHEN the user opens its detail page
- THEN the chain widget lists both child ondermandaten with links to their own detail pages

@e2e exclude exercised by tests/e2e/spec-coverage/register-detail-widgets.spec.ts ("BevoegdheidstoedelingDetail: delegation-chain widget shows the seeded ondermandaat under mandaat-subsidies-secretaris" — opens the parent's detail page and asserts the child's subject text renders under "Ondermandaat chain") and the `findChildren` algorithm by tests/vitest/registerDetailWidgets.spec.js; that e2e test's own @e2e anchor still targets the pre-archival openspec/changes/register-detail-optimisation/... path so this gate does not match it — recorded here rather than reported as a gap. The seed only provides one child, not two, and the row-links-to-its-own-detail-page half is not separately asserted.

#### Scenario: A root toedeling with no parent and no children renders a minimal chain
- GIVEN a `bevoegdheidstoedeling` with no `parentAllocation` and no toedelingen referencing it as parent
- WHEN the user opens its detail page
- THEN the chain widget renders just that toedeling with no ancestor breadcrumb and no child list, not an error or an empty grid gap

@e2e exclude the underlying empty-ancestor/empty-children algorithm cases are covered by tests/vitest/registerDetailWidgets.spec.js ("returns an empty array for a root object with no parent", "returns an empty array when nothing matches"); no e2e test opens a root-with-no-relations toedeling and asserts the Vue widget renders the minimal-chain UI without an error — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: The chain widget never infinite-loops on malformed data
- GIVEN a defensive-only scenario where `parentAllocation` references form a cycle (never producible via normal application flows)
- WHEN the chain widget resolves the ancestor walk
- THEN it MUST terminate after at most as many steps as there are ondermandaat objects in the register, rendering the partial chain rather than hanging

@e2e exclude unit-level algorithm safety (defensive-only, "never producible via normal application flows" per the scenario's own GIVEN), covered by tests/vitest/registerDetailWidgets.spec.js::"terminates on a defensive cycle instead of hanging" — not meaningfully UI-observable since normal application flows cannot produce the malformed data this scenario requires.
