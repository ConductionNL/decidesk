---
kind: config
---

# Proposal: retire-vve-template-surfaces

## Summary

Give the unified `DecisionTemplate` schema the settings page it never got, and remove the two VvE pages that outlived the model they described.

## Motivation

The model refactor landed. The UI did not follow.

`unified-decision-templates` (archived 2026-08-19) folded `ProcessTemplate` and `VveDecisionTemplate` into a single `DecisionTemplate`, folded the 2017 modelreglement's `categoryRules` into each template's own `votingRule` / `quorumRule`, and marked all three superseded schemas `x-openregister.active: false` with their rows kept.

It shipped no page. So measured on a live instance 2026-08-31:

| schema | objects | settings page |
|---|---|---|
| `decision-template` (live) | **28** | **none** |
| `vve-decision-template` (superseded) | 6 | yes, on the gear |
| `modelreglement-preset` (superseded) | 3 | yes, on the gear |

An operator opening the gear finds two entries backed by retired schemas, and no way at all to reach the 28 templates the app actually uses. A municipality and a company board have templates too: `context` on the unified schema is `association | corporate | legislative | operations | citizen`, and the VvE-only page could show one fifth of them.

## Affected Projects

- [x] Project: `decidiq` — this change.

## Scope

**In scope.** One index and one detail page over `decision-template`; removal of the `VveDecisionTemplates` and `ModelreglementPresets` pages and menu entries; the gear's `settingsSection` order.

**Out of scope.** The remaining VvE-bound schemas. `VveConfiguration` is genuinely per-body configuration and `KascommissieVerklaring` is its own record; both are generalised in the next links of this chain, each with its own migration. This change removes only surfaces whose schemas are ALREADY superseded, so it carries no data migration at all.

## Why this is safe

Nothing is deleted and no row moves. The two removed pages address schemas that OpenRegister already reports inactive; their rows stay exactly where they are, reachable through OpenRegister's own admin. The new pages read a schema that is live and populated.

`removals` in `menu-layout.json` stays empty, per gate-53/ADR-044: these entries are not being hidden while their routes survive, the pages themselves are gone.

## What the new page immediately revealed

Giving the schema a surface made a second defect visible on the first look: **28 templates, of which 13 were duplicates** — each built-in present once with its seeded slug and once with none.

`68-unified-decision-templates.json` seeds the thirteen built-ins, describing them in its own words as ports of the legacy rows, and supersession deliberately KEPT those legacy rows. `MigrateLegacyTemplatesToDecisionTemplate` then reads them and creates a `decision-template` for each. Its idempotency index keys on `migratedFrom.sourceUuid`, and a seeded row carries no `migratedFrom`, so it never matched: the migration duplicated every built-in on its first run.

Nobody had seen it, because until this change the schema had no surface to see it through.

The migration now also matches on name, which is the honest key given the seeds are ports of those very rows and a seeded `slug` is an import-time identifier OpenRegister does not expose as a queryable property. Verified on a live instance: 28 → 15 after removing the duplicates, and **15 again after re-running the migration**.

⚠️ **Existing instances keep their duplicates.** This stops new ones; it does not delete. The migration is documented and tested as purely additive, and a row an operator has since edited must not be destroyed by a repair step. Cleaning up an already-duplicated instance belongs in an explicit, opt-in `occ` command, tracked separately.
