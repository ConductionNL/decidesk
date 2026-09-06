# planning-cycle-in-plain-words Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [planning-cycle-in-plain-words](../../changes/planning-cycle-in-plain-words/)

## Purpose

The recurring annual cycle every organisation runs is named for what it is.

## ADDED Requirements

### Requirement: The planning cycle is named in plain words

`PlanningCycle`, `PlanningCycleTemplate` and `PlanningCycleStep` SHALL model a body's recurring annual cycle, its reusable step list, and one step of it.

The app SHALL NOT declare a schema named in one country's word for a cycle every organisation runs.

#### Scenario: A company runs its annual cycle

- **WHEN** a company plans its budget year and reports against it
- **THEN** it is a planning cycle, and no Dutch-named schema is involved

### Requirement: Existing cycles are carried across

A repair step SHALL copy every `cyclus-template`, `pc-cyclus` and `cyclus-stap` row onto the renamed schema, IN THAT ORDER, because a cycle references its template and a step references its cycle.

A reference to a record copied in the same run SHALL follow it to its new identifier.

It SHALL be idempotent, keyed on the SOURCE object's identifier, and SHALL NOT edit or delete any source row.

#### Scenario: A step points at the copied cycle

- **WHEN** a cycle with five steps is migrated
- **THEN** every copied step references the copied cycle, not the retired one

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional record is created

### Requirement: The one Dutch property is renamed with its schema

`PlanningCycleStep.cycle` SHALL hold what `CyclusStap.cyclus` held.

#### Scenario: The renamed property carries the value

- **WHEN** a step is migrated
- **THEN** its `cycle` names the cycle its `cyclus` named

### Requirement: The superseded schemas are kept, not deleted

`PCCyclus`, `CyclusTemplate` and `CyclusStap` SHALL remain declared with `active: false` and `hardDelete: false`.

#### Scenario: The old schemas still hold their rows

- **WHEN** the change is applied
- **THEN** every source row is still readable under its original schema
