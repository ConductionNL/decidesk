# retire-the-unbuilt-rooster Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [retire-the-unbuilt-rooster](../../changes/retire-the-unbuilt-rooster/)

## Purpose

Terms and rotation are read from source data, not from a snapshot nothing refreshes.

## ADDED Requirements

### Requirement: A term rule is a property of a position

`PositionType` SHALL carry the term rules the retired `TermijnRegeling` held: term length, maximum consecutive terms, and any notes.

The app SHALL NOT declare a second schema keyed on a fixed role enum for the same rules.

#### Scenario: A body records how long its chair serves

- **WHEN** an organisation sets a term length for its chair
- **THEN** it is recorded on the chair position, not on a separate rule

### Requirement: Term rules are carried across

A repair step SHALL copy every `termijn-regeling` row onto `position-type`, resolving the body reference and turning the role into the position's name.

It SHALL be idempotent, keyed on the SOURCE object's identifier, and SHALL NOT edit or delete any source row.

A rule naming no body SHALL be skipped, because a position belonging to no body is not a position.

#### Scenario: Rules survive the fold

- **WHEN** the repair step runs on an instance holding term rules
- **THEN** each is a position type carrying the same term length and maximum

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional position type is created

### Requirement: The projection is not promoted to source data

`rooster-van-aftreden` and `rooster-regel` rows SHALL NOT be copied onto `position-hold`.

Both were a projection whose generator was never written, and `position-hold` requires a start date that a rooster regel never recorded, so any value written would be invented.

#### Scenario: No position hold is invented

- **WHEN** the repair step runs on an instance holding seeded rooster rows
- **THEN** no position hold is created from them

#### Scenario: The old rows remain readable

- **WHEN** the change is applied
- **THEN** every rooster row is still readable under its original schema

### Requirement: Position holders have a surface

The system SHALL offer an index and detail page for `position-hold`, ordered by end date.

#### Scenario: A clerk sees whose term runs out next

- **WHEN** a clerk opens the position holders page
- **THEN** holders are listed with the soonest ending term first
