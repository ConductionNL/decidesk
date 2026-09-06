# migrations-that-actually-run Specification

**Status**: planned
**Scope**: decidiq

**OpenSpec changes**:
- [migrations-that-actually-run](../../changes/migrations-that-actually-run/)

## Purpose

A migration that ships is a migration that runs.

## ADDED Requirements

### Requirement: A reference is resolved from what the target declares

Before a migrated record is saved, every value bound for a property the target schema declares as `format: uuid` with a `$ref` SHALL be resolved against the schema that `$ref` names.

A hand-maintained list of which properties are references SHALL NOT be the only thing that decides this, because the target schema already says it.

#### Scenario: A property nobody remembered to list

- **WHEN** a source row carries a slug in a property the migration's own map omits
- **THEN** it is still resolved, because the target declares it as a uuid reference

#### Scenario: A value the caller already resolved

- **WHEN** the migration resolved a property itself before saving
- **THEN** that value is left exactly as it is, and never resolved a second time

### Requirement: A value is widened to the format the target asks for

A bare `YYYY-MM-DD` bound for a property the target declares as `format: date-time` SHALL be widened rather than refused.

#### Scenario: A seeded deadline

- **WHEN** a source row carries `deadline: "2026-02-16"` and the target declares date-time
- **THEN** the record is stored, not refused

### Requirement: An update carries the properties the target requires

A migration that updates an existing record SHALL send every property the target schema declares required.

OpenRegister validates the whole object on save, so a partial patch is refused for the properties it omits rather than merged over them.

#### Scenario: Folding a policy onto an existing configuration

- **WHEN** a policy is folded onto a configuration that already exists
- **THEN** the update carries `governanceBody`, and the record is stored

### Requirement: A carried enum is translated, not copied

Where a source and its target spell the same lifecycle differently, the value SHALL be translated into the target's vocabulary.

#### Scenario: An advice request mid-flight

- **WHEN** an advice request in `advice-issued` is migrated
- **THEN** it lands in a state the generic schema accepts
