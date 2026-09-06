# fold-regulations-into-governing-documents

**Status**: planned
**Scope**: decidiq

## Why

Two schemas modelled the same thing, and the second one says so.

`GoverningDocumentVersie`'s description reads "mirroring the verordeningenregister RegelingVersie conventions". Someone wrote it by copying `RegelingVersie`, and the app has carried both ever since. The parents are the same story: a `Regeling` and a `GoverningDocument` are both a document a body adopts, that supersedes itself in numbered versions, each version enacted by a decision.

Line them up and only the words differ. `determiningBody` against `governingBody`. `type` as five council document kinds against six association ones. `cvdrIdentifier`, named after one national register in one country.

None of those vocabularies belongs to anyone exclusively. A company has by-laws. An association has articles of association. A council has both, plus policy rules. Two schemas for one concept means every feature is built twice, and the versions half was already copied once.

## What changes

- `GoverningDocument` absorbs what `Regeling` had: `officialTitle`, `statutoryBasis`, `currentVersionNumber`, and `cvdrIdentifier` under the generic name `externalRegisterIdentifier`.
- The two `type` enums become one list. The two `status` enums become one list too, and both spellings survive, because both are already stored.
- Retire `Regeling` and `RegelingVersie` non-destructively.
- Copy every row across, documents before versions.
- Repoint `RegelingExportPackage.regulation` at `governing-document`, so it does not reference a retired schema.

## Decision: both status spellings survive

`GoverningDocument` says `in-force`. `Regeling` says `in-effect`. They mean the same thing and it is tempting to keep one.

Rows already carry both. Collapsing them would rewrite stored data, which is the one thing every step of this programme has promised not to do. So the enum carries both and new rows use `in-force`. It reads as untidy because it is a record of two schemas having existed, which is exactly what it is.

## Decision: the type list stays a closed enum, for now

A closed enum of document kinds is still a vocabulary the app ships, and the honest end state is a configurable type like `AgendaItemType`. That waits for the Configuration surface, which is a separate piece of work. Unioning the two lists now deletes the duplication without inventing a schema no one can yet edit.

## Impact

One menu entry and three pages go. The regulations register keeps working, under the name the rest of the app already used for the same thing.

`RegelingExportPackage` stays for now. It is a DROP/STOP-TPOD delivery, a Dutch publication standard, and belongs in integriq with the Woo and DiWoo mapping. Repointing it here keeps it from dangling until that move.
