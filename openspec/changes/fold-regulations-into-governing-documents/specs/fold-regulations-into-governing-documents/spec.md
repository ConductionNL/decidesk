# fold-regulations-into-governing-documents Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [fold-regulations-into-governing-documents](../../changes/fold-regulations-into-governing-documents/)

## Purpose

One schema for a document a body adopts and versions, whatever the organisation calls it.

## ADDED Requirements

### Requirement: One schema models an adopted, versioned document

`GoverningDocument` SHALL model every document a governance body adopts and supersedes in numbered versions, including those a council calls a verordening or a beleidsregel.

It SHALL carry `officialTitle`, `statutoryBasis`, `currentVersionNumber` and `externalRegisterIdentifier`.

The app SHALL NOT declare a second schema for the same concept under another organisation's vocabulary.

#### Scenario: A company records a by-law

- **WHEN** a company adopts a by-law deriving from its articles of association
- **THEN** it is a governing document, and no council-specific schema is involved

#### Scenario: The external identifier is generic

- **WHEN** a document is published to an external register
- **THEN** the identifier that register assigns is stored in `externalRegisterIdentifier`, named after what it is rather than after one country's register

### Requirement: Both stored status spellings remain valid

The `status` enum SHALL accept both `in-force` and `in-effect`.

Rows written under each of the two retired schemas already carry one of them, and this change SHALL NOT rewrite stored values.

#### Scenario: An existing row stays valid

- **WHEN** a row carrying `in-effect` is read after the fold
- **THEN** it validates, and its value is unchanged

### Requirement: Existing regulations are carried across

A repair step SHALL copy every `regeling` row onto `governing-document` and every `regeling-versie` row onto `governing-document-versie`.

It SHALL copy documents BEFORE versions, because a version references its document.

It SHALL be idempotent, keyed on the SOURCE object's identifier held in the declared `migratedFromObject` property, and SHALL NOT edit or delete any source row.

A version whose parent could not be copied SHALL be skipped rather than written without one.

#### Scenario: Rows survive the fold

- **WHEN** the repair step runs on an instance holding regulations
- **THEN** each is a governing document carrying its title, body, status and legal basis

#### Scenario: Versions point at their document

- **WHEN** a regulation with three versions is migrated
- **THEN** all three versions reference the copied document

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional document or version is created

#### Scenario: An orphan version is not written

- **WHEN** a version's parent regulation could not be copied
- **THEN** no version is written for it, and the source row is untouched

### Requirement: Nothing references a retired schema

`RegelingExportPackage.regulation` SHALL reference `governing-document`.

#### Scenario: The export package still resolves

- **WHEN** an export package is read after the fold
- **THEN** its document reference resolves to a live schema

### Requirement: The superseded schemas are kept, not deleted

`Regeling` and `RegelingVersie` SHALL remain declared with `active: false` and `hardDelete: false`.

#### Scenario: The old schemas still hold their rows

- **WHEN** the change is applied to an instance holding regulations
- **THEN** every source row is still readable under its original schema
