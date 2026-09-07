# commitment-in-plain-words Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [commitment-in-plain-words](../../changes/commitment-in-plain-words/)

## Purpose

A promise made to a governance body is named for what it is.

## ADDED Requirements

### Requirement: A commitment is named in plain words

`Commitment` SHALL record a promise made to a governance body in a meeting, tracked to settlement.

It SHALL use a qualified slug, because schema slugs are global on a shared OpenRegister and `commitment` belongs to another app.

The app SHALL NOT declare a schema named in one country's word for something every organisation does.

#### Scenario: A works council records a promise from the board

- **WHEN** a director promises the works council a figure at a meeting
- **THEN** it is a commitment, and no Dutch-named schema is involved

### Requirement: Nothing live references the retired schema

`Raadsinformatiebrief.settledCommitment` and `TermijnagendaItem.originCommitment` SHALL reference the renamed schema.

A reference on an ALREADY RETIRED schema need not be repointed.

#### Scenario: A letter still resolves the commitment it settles

- **WHEN** a letter naming a settled commitment is read
- **THEN** its reference resolves to a live schema

### Requirement: Existing commitments are carried across

A repair step SHALL copy every `toezegging` row onto the renamed schema, resolving every reference to a uuid before writing it.

It SHALL be idempotent, keyed on the SOURCE object's identifier, and SHALL NOT edit or delete any source row.

#### Scenario: Rows survive the rename

- **WHEN** the repair step runs on an instance holding commitments
- **THEN** each is readable under the new schema with the same values

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional commitment is created

### Requirement: The superseded schema is kept, not deleted

`Toezegging` SHALL remain declared with `active: false` and `hardDelete: false`.

#### Scenario: The old schema still holds its rows

- **WHEN** the change is applied
- **THEN** every source row is still readable under its original schema
