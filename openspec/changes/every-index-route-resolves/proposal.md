# every-index-route-resolves

**Status**: planned
**Scope**: decidiq

## Why

This programme renamed a lot of routes. `/termijnagenda` became `/planned-agenda`, `/bevoegdheidstoedelingen` became `/authority-delegations`, and eight more moved with the schemas under them.

Auditing that work turned up something the renames did not cause but did make dangerous: **16 of the app's 30 declared index routes were not navigated to anywhere in the e2e suite.** Among them were five this programme had just renamed.

A route that does not resolve renders the app shell and nothing else. On a list page that is indistinguishable by eye from an instance that simply has no rows yet, so nothing reports it, and no check in this repo looks at it. The suite was green on every one of those sixteen without ever opening one.

## What changes

One spec navigates to every index route the merged manifest declares and asserts it renders its own heading.

The route list is **derived, not written down**. It is built by calling the same `buildManifest` that `src/main.js` calls at boot, over the same base manifest, the same `manifest.d/*.json` in the same sorted order, and the same menu-layout. A page a future fragment adds is covered the day it lands. A route renamed without its test being updated cannot go stale, because there is no second copy of the list to update.

## Decision: index pages only

The other page types render their own chrome rather than a titled list, so one heading assertion would not mean the same thing across them. `dashboard`, `reports`, `store`, `roadmap` and `custom` pages stay with `app-chrome.spec.ts`. Parameterised routes stay with the per-schema specs that have seed objects to open.

## What this does not catch

The heading comes from the manifest `title`, so a page bound to the wrong schema still renders it, and so does a page whose id collided with another fragment's.

Those have their own guards, and this spec is not a substitute for either: `tests/vitest/manifestIdsAreUnique.spec.js` catches the collision, and `configuration-surface.spec.ts` seeds a row over the API to prove one schema binding.

## Impact

No production code changes. One spec file and one openspec change.

A generated suite can go hollow by generating nothing, so the spec also asserts its own route table is populated and that no two routes share a title.

## What the guard found on its first run

The uniqueness assertion failed before a single browser opened.

`fix/consultation-page-id-collision` had just restored the citizen consultation surface after another fragment had been quietly replacing it. Both surfaces came back, and both were labelled **Consultations**: one for `public-consultation`, one for `governance-consultation`. Both are relocated under `Decisions`, so the navigation showed two sibling entries with the same word on them, and a reader had no way to tell which was which.

So the citizen surface is renamed to **Public consultations**, and its detail page to **Public consultation**. That is what it holds: the supertype covering citizen participation, market consultation, tenders, the idea box and participatory budgets. The governance surface keeps **Consultations**.

The two widgets on `DecisionDetail` were already named this way, `Public consultations` for one and `Consultations` for the other, so this brings the pages into line with the labels the app was already using next to them.
