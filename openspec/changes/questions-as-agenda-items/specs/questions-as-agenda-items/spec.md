# questions-as-agenda-items Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [questions-as-agenda-items](../../changes/questions-as-agenda-items/)

## Purpose

A question put to a governance body is an agenda item. Which kind of question it is, is configuration the organisation writes, not a schema the app ships.

## ADDED Requirements

### Requirement: A question is an agenda item carrying a configurable kind

The system SHALL record an oral question, a technical question and an interpellation request as `AgendaItem` rows whose `type` references an `AgendaItemType`.

Fields distinctive to a kind SHALL live in `AgendaItem.typeFields`, and the kind SHALL declare them in `AgendaItemType.fields`.

The app SHALL NOT ship a schema, page, menu entry or component named after one organisation's vocabulary for a question.

#### Scenario: A works council records a question

- **WHEN** a works council configures a kind called "Vraag aan de bestuurder" and files one
- **THEN** it is an agenda item, and no council-specific schema is involved

#### Scenario: The kind is named in the agenda

- **WHEN** an agenda item carries a configured type
- **THEN** the agenda's Type column shows that type's name

#### Scenario: An instance with no configured kinds still reads

- **WHEN** an agenda item carries no type
- **THEN** the Type column falls back to the coarse `itemType` and nothing errors

### Requirement: Existing questions are carried across

A repair step SHALL copy every `mondelinge-vraag` and `interpellatieverzoek` row onto `agenda-item`, resolving the row's governance body and its kind first.

It SHALL be idempotent, keyed on the SOURCE object's identifier recorded as `typeFields.migratedFromObject`. A body has many questions, so the body cannot be the identity.

It SHALL create one `AgendaItemType` per (owning body, kind), not one per row, and SHALL NOT edit or delete any source row.

A row with no identifier SHALL be skipped, because without one there is no idempotency key.

#### Scenario: Rows survive the collapse

- **WHEN** the repair step runs on an instance holding oral questions
- **THEN** each is an agenda item carrying its number, submitter, fraction and lifecycle

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional agenda item is created

#### Scenario: One council gets one kind

- **WHEN** a body holds a hundred oral questions
- **THEN** one `AgendaItemType` is created, not a hundred

#### Scenario: The source is untouched

- **WHEN** the repair step runs
- **THEN** every source row still exists, unedited

### Requirement: The question-hour configuration moves onto the type

`AgendaItemType.submissionWindowHours`, `supportThresholdType` and `supportThresholdValue` SHALL carry what `VragenuurConfiguratie` held.

The submission window SHALL apply to every kind of question. The support threshold SHALL apply only to the kind that needs support before it is admitted.

#### Scenario: The window applies to both kinds

- **WHEN** a body's configuration sets a 24 hour window
- **THEN** both its question kinds take that window

#### Scenario: The threshold applies to one kind

- **WHEN** a body's configuration sets a support threshold
- **THEN** only the kind requiring support carries it

### Requirement: No example set seeds a retired schema

An example set SHALL NOT seed a schema the register declares `active: false`.

A fresh install seeding a retired schema demonstrates the superseded model, and nothing reports it: OpenRegister does not refuse the write, the gates read manifests rather than seeds, and the object count still rises.

Each set's advertised `objectCount` SHALL equal the number of objects it carries, because the setup wizard shows that number to an operator before they choose.

#### Scenario: A retired schema is refused

- **WHEN** a schema is retired and an example set still seeds it
- **THEN** the test suite fails, naming the schema

#### Scenario: A drifted count is refused

- **WHEN** a set's seeds change and its `objectCount` does not
- **THEN** the test suite fails, naming the set

### Requirement: The superseded schemas are kept, not deleted

`MondelingeVraag`, `Interpellatieverzoek` and `VragenuurConfiguratie` SHALL remain declared with `active: false` and `hardDelete: false`, so their rows persist and a rollback finds its data.

#### Scenario: The old schemas still hold their rows

- **WHEN** the change is applied to an instance holding questions
- **THEN** every source row is still readable under its original schema
