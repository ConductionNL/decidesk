---
kind: code
---

# Proposal: seed-profiles

## Summary

Stop planting example data on install. Move the 334 seed objects out of the register fragments into **example sets**, one per kind of organisation, and let the operator pick which one to load in the first-time setup wizard.

## Motivation

Installing this app plants 334 objects nobody asked for.

`SettingsService::loadConfiguration()` merges `decidesk_register.json` with all 26 `register.d` fragments, and every one of them carried its own `x-openregister.seedData.objects`. The `InitializeSettings` repair step runs that merge, so a fresh install seeds a Gemeenteraad Amsterdam, a VvE Zeewaarts, a pub quiz, five ACME B.V. bodies and eight placeholder TOOI mappings into the operator's register. A municipality gets the VvE data. A VvE gets the council data. Nobody chose any of it.

Measured on the tree at 2026-08-30:

| | |
|---|---|
| seed objects imported on install | **334** (118 base + 216 across 26 fragments) |
| of those the operator chose | **0** |
| datasets the setup wizard offered | 1, and a **different** one |

The wizard already offered a demo dataset (`decidiq_mock_register.json`) whose own step text says "Skip this on a production install". By the time an operator reads that sentence, 334 objects are already in their register. The advice was true and useless.

## Affected Projects

- [x] Project: `decidiq` — this change.

## Scope

**In scope.** Splitting the shipped seeds into four example sets, a service that lists and imports one, the two wizard steps that ask and load, and authoring a works-council set that did not previously exist.

**Out of scope.** Generalising the domain-bound schemas themselves (`VveConfiguration`, `Raadsinformatiebrief`, `WooCategorieMapping` and ~32 others). That is the next change in this chain; this one moves data without renaming anything, so the diff stays reviewable.

## What the split had to solve

The seeds are cross-referenced by slug, and a naive partition would leave dangling references. Building the reference graph showed one connected component of 170 objects tangling municipal, corporate and association bodies together, so a partition was impossible.

The resolution: **a set is a closure, not a partition.** Each set is anchored on the governance bodies that belong to it, then closed over outbound references, so every reference resolves inside the set that carries it. Sets may overlap, and 15 objects do. Verified mechanically: 334 of 334 objects classified, zero dangling references, zero orphans.

## Defects this surfaced and fixes

Validating every seed against its schema turned up ten pre-existing defects, all fixed here:

- **`regulation` matched no decidiq schema.** Three seeds keyed on `regulation`; the app's schema slug is `regeling`. Because `importSeedData()` resolves a schema slug cross-app with multitenancy off, `regulation` resolves to **learniq's** schema. Re-keyed to `regeling`.
- **`Regeling.status` was required, lifecycle-driven and never declared.** `x-openregister-lifecycle` names `status` as its field and `required` lists it, but `properties` did not, so OpenRegister created no magic-table column. Verified on a live instance: `oc_openregister_table_21_262` carried every other property and no `status` column, which means the declared `in-preparation → adopted → in-effect → lapsed` map could never advance. Property added.
- **Six invalid enum values** on the pub-quiz `decision-stage` seeds (`stageType: vote`, `status: in-progress`).
- **Two governance bodies missing the required `domain`.**
- **The works-council seeds described a city council.** All three WOR consultation requests carried `governanceBody: gemeenteraad-amsterdam`, a null-UUID `director`, and a raadsvergadering as their overlegvergadering. Rewritten as a real 45-object set: an ondernemingsraad at ACME B.V. with members, an overlegvergadering, two adviesaanvragen, an instemmingsverzoek under the WOR, and an achterbanraadpleging.

## Why the descriptor declares no register

An example set carries `@self.configuration/register/schema` on every object and declares **no** `components.registers`.

That is load-bearing, not stylistic. `ImportHandler::importRegister()` calls `setApplication($appId)` unconditionally when it updates an existing register, so a descriptor that declared `decidiq` would re-point the register at the profile's config id and hydrate over its `authorization` block — the baseline that stops any authenticated user rewriting another body's decisions. Verified against a live instance: importing this shape left `application=decidiq`, the version, and the authorization hash byte-identical, and imported 45 objects.

The files also live in a **subdirectory**, because `RegisterDescriptorService` scans `lib/Settings/*.json` non-recursively and indexes by declared register slug. Four profiles in `lib/Settings` would collide with each other and with the app's own register.

## Why two wizard steps

`CnSetupWizard::runAction()` posts to `/api/setup/action/{action}` with no body, so an action cannot carry the answer. A `choice` step records which set (via `POST /api/setup/config`), and the `run-action` step that follows reads it back and imports it.

ADR-111 requires the schema-generated mock to stay on offer. Rather than ask twice about the same thing, it is one option in the same choice.

## Next step

Chain link 2 generalises the VvE schemas into per-body governance configuration, and adds the `Decision templates` settings page that the unified 84-row `decision-template` schema still has no surface for.
