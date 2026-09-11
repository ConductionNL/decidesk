# governing-documents-register Specification

## Purpose
Governing documents (`governing-document`/`governing-document-versie`) share the same version-and-consolidation shape as the regulations register: an amendment traces to a Decision, and the reader needs the version history in date order to know which text currently governs. This spec adds the same version-timeline treatment to `GoverningDocumentDetail` that `register-detail-optimisation` adds to `RegelingDetail`, plus the current-in-force-date index column the `governing-documents-register` manifest fragment's own `_note` already promised but never declared.

## Requirements

### Requirement: REQ-GDR-009 Version timeline widget on GoverningDocumentDetail

The `GoverningDocumentDetail` page MUST render a `version-timeline` widget listing every `governing-document-versie` object referencing the current document, ordered by effective date ascending, each entry showing version number, effective date, status, notarial-deed metadata when present (`aktedatum`/`notaris`), and a resolved link to the enacting Decision where one is set (amendments without a notarial deed or an enacting Decision — e.g. an initial adoption predating decidiq — render the entry without that link rather than omitting the version).

@e2e exclude the shared ascending-order algorithm is covered by tests/vitest/registerDetailWidgets.spec.js::"sortVersionsByEffectiveDate (REQ-VOR-009 / REQ-GDR-009)"; no e2e test opens a GoverningDocumentDetail page (this widget is not exercised by tests/e2e/spec-coverage/register-detail-widgets.spec.ts, which covers only RegelingDetail/BevoegdheidstoedelingDetail/GeheimhoudingDetail) and asserts the notarial-deed metadata or the enacting-Decision link render — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Governing document with a notarised amendment shows deed metadata
- GIVEN a `governing-document-versie` with `aktedatum` and `notaris` set
- WHEN the user opens `GoverningDocumentDetail` for the owning document
- THEN the version-timeline entry for that version shows the deed date and notary

#### Scenario: Version with no enacting Decision renders without a broken link
- GIVEN a `governing-document-versie` with no enacting-Decision reference set
- WHEN the user views the version-timeline
- THEN that entry renders its other fields normally and shows no Decision link (not a link to nothing)

### Requirement: REQ-GDR-010 Current-in-force-date column on the GoverningDocuments index

The `GoverningDocuments` index MUST include a column showing each document's current in-force version's effective date, resolved as a list-level query (no per-row N+1 lookup), with an explicit date-format hint so the value renders through the locale-aware date formatter.

@e2e exclude no current e2e test opens the `GoverningDocuments` index and asserts the current-in-force-date column renders formatted per row; same gap as `verordeningenregister`'s equivalent REQ-VOR-011 scenario — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Index shows the current-in-force date per document
- GIVEN two `governing-document` objects with different current in-force versions
- WHEN the user views the `GoverningDocuments` index
- THEN each row shows its own current-in-force date, formatted, not raw
