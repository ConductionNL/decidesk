---
kind: code
---

# Proposal: generic-audit-statement

## Summary

Rename `KascommissieVerklaring` to `AuditStatement`, and move the Dutch word to where per-organisation vocabulary already lives: the `organisatie_modus` label map.

## Motivation

The record was **already generic in shape**: a financial year, a verdict (`approving | qualified | adverse`), a body, and two optional annotations. Nothing about it is specific to a VvE except its name.

A kascommissie is one kind of audit committee. A provincial auditcommissie and a company's audit committee produce exactly this record, and the app already seeds an **Auditcommissie Provincie Noord-Holland** that had no way to file one, because the schema was called something only a VvE would recognise.

## Affected Projects

- [x] Project: `decidiq` — this change.

## Scope

**In scope.** The generic schema and its migration, the two Vue files and the visibility helper that carried the name, the settings surface, the agenda-rule label, the example seeds, and the assoc-mode label.

**Out of scope.** The `assoc`-mode visibility gate itself, which stays. `organisatie_modus` is the app's own mechanism for adapting vocabulary and surfaces per organisation type; the facet being shown to association tenants is that mechanism working, not a VvE-specific branch. What was wrong is that the Dutch word was baked into a schema name instead of declared as a mode label.

## Where the Dutch word went

`src/config/modeLabels.js`, `assoc` mode:

```js
'Audit statements': 'Kascommissie verklaringen',
'Audit statement': 'Kascommissie verklaring',
```

So a VvE administrator still reads *Kascommissie verklaringen*, and a provincial auditcommissie reads *Audit statements*, from one schema.

The agenda rule keeps `kascommissie` and `kascontrole` as **synonyms** while its label becomes *Audit committee report*: synonyms match the words a Dutch agenda actually uses, and narrowing them would break matching for no gain.

## How it supersedes

Non-destructively, as the three changes before it did: `KascommissieVerklaring` keeps its definition and rows (`active: false`, `hardDelete: false`), and `MigrateKascommissieToAuditStatement` copies each row.

Identity is **(governance body, financial year)**, not the source uuid: a body files one statement per year, so that pair is what makes two rows the same record. The body reference is resolved to a UUID *before* the idempotency check, because the legacy rows hold a slug while the rows this writes hold a UUID — the defect measured live in `generic-body-configuration`.

## Two broken seeds removed

Both shipped kascommissie seeds referenced `governanceBody: 00000000-…`, a body that exists in no example set. They described nothing and are dropped, and two real statements for VvE Parkstaete (2024 approving, 2025 qualified) take their place so the facet has something to show.
