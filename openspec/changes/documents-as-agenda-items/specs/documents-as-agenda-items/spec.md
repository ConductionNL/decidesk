# documents-as-agenda-items Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [documents-as-agenda-items](../../changes/documents-as-agenda-items/)

## Purpose

A document put before a governance body is an agenda item, and what kind it is, is configuration.

## ADDED Requirements

### Requirement: A routed document is an agenda item

The system SHALL record incoming correspondence and letters from an executive as `AgendaItem` rows whose `type` references an `AgendaItemType`.

Fields distinctive to a kind SHALL live in `AgendaItem.typeFields`.

The app SHALL NOT ship a schema, page or menu entry named after one organisation's word for its post.

#### Scenario: A works council records a letter from the board

- **WHEN** a works council configures a kind called "Brief van de bestuurder" and files one
- **THEN** it is an agenda item, and no council-specific schema is involved

### Requirement: A question about a document is a sub-item of it

A technical question SHALL be an `AgendaItem` whose `parentItem` is the item its letter became.

It SHALL NOT be a schema of its own: its parent reference was required, so it never had an identity without one.

#### Scenario: A question hangs off its letter

- **WHEN** a technical question about a letter is migrated
- **THEN** its `parentItem` names the agenda item the letter became, not the retired letter

### Requirement: Existing documents are carried across

A repair step SHALL copy every `ingekomen-stuk`, `raadsinformatiebrief` and `technische-vraag` row onto `agenda-item`, letters BEFORE questions.

It SHALL be idempotent, keyed on the SOURCE object's identifier held in `typeFields.migratedFromObject`, and SHALL NOT edit or delete any source row.

A title SHALL be truncated to fit while the full text is kept in `typeFields`.

#### Scenario: Rows survive the collapse

- **WHEN** the repair step runs on an instance holding incoming documents
- **THEN** each is an agenda item carrying its sender, category and lifecycle

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional agenda item is created

#### Scenario: A long question keeps its text

- **WHEN** a question longer than the title allows is migrated
- **THEN** the title is truncated and `typeFields.question` holds the whole thing

### Requirement: The superseded schemas are kept, not deleted

`IngekomenStuk`, `Raadsinformatiebrief` and `TechnischeVraag` SHALL remain declared with `active: false` and `hardDelete: false`.

#### Scenario: The old schemas still hold their rows

- **WHEN** the change is applied
- **THEN** every source row is still readable under its original schema
