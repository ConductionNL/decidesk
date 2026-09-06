# the-last-two-dutch-names Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [the-last-two-dutch-names](../../changes/the-last-two-dutch-names/)

## Purpose

Planning what a body will handle, and recording who may act for whom, are named for what they are.

## ADDED Requirements

### Requirement: The last two schemas are named in plain words

`PlannedAgendaItem` SHALL record something a body expects to handle in a future period. `AuthorityDelegation` SHALL record who may exercise which authority on whose behalf.

The app SHALL NOT declare a schema, or a property, named in one country's word for something every organisation does.

#### Scenario: A company records a delegation

- **WHEN** a board delegates spending authority to a director
- **THEN** it is an authority delegation, with a delegating body and a delegate, in those words

### Requirement: The Dutch properties are renamed with their schema

`delegatingBody`, `delegatingDescription`, `delegateBody` and `restrictions` SHALL hold what `delegans`, `delegansDescription`, `delegatarisBody` and `beperkingen` held.

#### Scenario: A value lands on a declared property

- **WHEN** a delegation naming a delegans is migrated
- **THEN** its `delegatingBody` carries that value, and no property the target does not declare is written

### Requirement: The forward-agenda vocabularies are configuration

`expectedType` and `ownerType` SHALL be free strings.

They fixed four council document kinds and three council roles; what an organisation plans to handle, and who owns it, is its own vocabulary.

#### Scenario: Existing values stay valid

- **WHEN** an item recorded under one of the retired enum values is read
- **THEN** it validates, and its value is unchanged

### Requirement: Existing records are carried across

A repair step SHALL copy every `termijnagenda-item` and `bevoegdheidstoedeling` row onto the renamed schema, resolving every reference to a uuid and renaming the four properties as it copies.

It SHALL be idempotent, keyed on the SOURCE object's identifier, and SHALL NOT edit or delete any source row.

#### Scenario: Rows survive the rename

- **WHEN** the repair step runs on an instance holding these records
- **THEN** each is readable under the new schema with the same values, under the new property names

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional record is created

### Requirement: The superseded schemas are kept, not deleted

`TermijnagendaItem` and `Bevoegdheidstoedeling` SHALL remain declared with `active: false` and `hardDelete: false`.

#### Scenario: The old schemas still hold their rows

- **WHEN** the change is applied
- **THEN** every source row is still readable under its original schema
