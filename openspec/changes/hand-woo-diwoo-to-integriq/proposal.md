# hand-woo-diwoo-to-integriq

**Status**: planned
**Scope**: decidiq, and a handover to integriq

## Why

These are the last Dutch-specific schemas in the app, and the only ones this programme retires without putting a generic replacement in their place.

Woo informatiecategorieën, TOOI bestuursorgaan identifiers and DROP/STOP-TPOD delivery packages are not a governance concept an organisation has. They are the wire format of one country's publication regime. Every other step of this programme found a generic concept underneath a Dutch name; here there is none to find, and inventing one would be worse than the Dutch name.

Mapping an object onto a publication standard is integriq's responsibility. That is where the fleet's other mappings live, and it is where a second country's regime would be added without touching this app at all.

## What changes

`WooCategorieMapping`, `WooBestuursorgaan` and `RegelingExportPackage` are retired, non-destructively. Their pages, their menu entry and their seeds go with them.

Nothing generic replaces them here.

## Handover to integriq

What integriq needs in order to take this over, written down because the knowledge is otherwise only in these schemas:

**`WooCategorieMapping`** binds one publishable object type, named by schema slug or by a payload discriminator, to a Woo informatiecategorie and its label. Per-type configuration, not per-object. An administrator adds rows; nothing derives them.

**`WooBestuursorgaan`** binds one governance body to its TOOI organisation identifier, so a publication can name the body in the national thesaurus. Per-body configuration, one row per body.

**`RegelingExportPackage`** bundles sealed versions of a governing document into a STOP/TPOD delivery, carrying a declarative build and deliver lifecycle plus validation errors and a remote reference. `fold-regulations-into-governing-documents` repointed its `regulation` reference at `governing-document` rather than moving it, specifically so it would not dangle before this step.

The rows are still readable under their retired slugs, so integriq can read them across during its own migration rather than needing them exported now.

## Decision: retire here first, move there second

The alternative was to build integriq's side in the same change. That is a second repository with its own CI, gates and reviewers, and a cross-app data move would be the riskiest thing this programme has done.

Retiring first is reversible and costs integriq nothing: decidiq stops shipping another country's publication plumbing today, and integriq's owners pick up a written brief rather than a half-finished migration.

## Impact

After this, decidiq ships no Dutch-named schema at all.

An instance that publishes to Woo today keeps its rows and loses the configuration pages until integriq offers them. That is a real gap, and it is the cost of the split; the alternative was leaving the plumbing here indefinitely.
