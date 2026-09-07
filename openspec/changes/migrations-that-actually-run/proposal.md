# migrations-that-actually-run

**Status**: planned
**Scope**: decidiq

## Why

Thirteen supersession migrations merged into decidiq green on `composer check:strict`, PHPUnit, 83 hydra gates and a 49-check CI suite. **None of them had ever been executed.**

A repair step runs at `occ upgrade` and essentially nowhere else. There is no `occ` command for one, unit tests exercise the class rather than the wiring, and a failure is reported through `$output->warning()`, which does not fail the upgrade. So a migration can be registered, shipped, and silently do nothing for as long as nobody upgrades.

Forcing an `occ upgrade` produced **18 repair warnings across five migrations**. This change takes that to zero.

## What was actually wrong

| count | failure | cause |
|---|---|---|
| 7 | `deadline '2026-02-16' is not date-time`, `agendaItem 'rondvraag' is not a uuid` | a reference or date carried across verbatim |
| 6 | `schema slug "member-onboarding" is not carried by register` | the schema was never created, see below |
| 2 | `required property (governanceBody) is missing` | a patch sent as a whole object |
| 2 | `required property (name) is missing` | the same |
| 1 | `goal 'goal-amsterdam-...' is not a uuid` | a reference the `REFERENCES` map omitted |

## The fix, and why it is not a longer list

Every migration carries a hand-written `REFERENCES` map. It was missing an entry in **seven** source/target pairs. Adding fifteen more entries by hand would have been the fifth time that list was corrected.

The target schema already declares which properties are references and what they point at: `format: uuid` plus `$ref`. `ReadsLegacyRows::coerceToTarget()` reads that instead, so there is one source of truth rather than a copy that drifts. It also widens a bare `YYYY-MM-DD` into a `date-time` where the target asks for one.

Two migrations sent a partial patch to `saveObject()` with a `uuid:`. OpenRegister validates the whole object, so the required property the patch omitted made it refuse the update. Both now carry the value they already had in hand.

`advice-request` tracked its own lifecycle vocabulary (`sent`, `in-progress`, `advice-issued`, `accounted-for`, `completed`) and the generic schema accepts none of the first five. A carried enum is not a carried string; the values are translated.

## 🔴 The correction: `list` in an authorization block silently unmakes the schema

`member-onboarding-in-plain-words` added an `authorization` block to two schemas and declared **both** `read` and `list`, arguing explicitly that `list` is a canonical action in OpenRegister's `PermissionHandler`.

It is — for **evaluating** a permission. The **importer** is a different thing, and it rejected the whole schema.

Measured on a live instance: `member-onboarding` and `member-offboarding` returned **404 for as long as `list` was present**, and **200 the moment it was removed**, with nothing logged either way. That is why no other schema in this tree declares it: 33 declare `read`, one declares `create`, none declares `list`.

So `list` is dropped. It falls back to the register baseline, which is the posture every other sensitive schema here already has, and the schema exists — which is strictly better than a perfectly-worded block on a schema that was never created.

## Impact

Measured across successive `occ upgrade` runs on a live instance: **18 → 12 → 8 → 5 → 0** repair warnings.
