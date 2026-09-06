# generic-body-configuration Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [generic-body-configuration](../../changes/generic-body-configuration/)

## Purpose

Per-body governance configuration that any kind of organisation can declare, replacing a schema that only a VvE could fill in.

## ADDED Requirements

### Requirement: A body configuration fits any organisation

The system SHALL declare `BodyGovernanceConfiguration` (slug `body-governance-configuration`) binding a governance body to its constitutive document, the version of the model regulation it follows, the denominator its weighted votes are expressed over, and any majority overrides.

Only `governanceBody` SHALL be required. A body whose members each hold one equal vote declares no denominator, and a body with no model regulation declares no version.

Majority overrides SHALL key on `templateCategory`, a free string matching `DecisionTemplate.templateCategory`, NOT on a fixed VvE category enum.

#### Scenario: A company declares its configuration

- **WHEN** a supervisory board records its articles of association and share count
- **THEN** the configuration is valid without any VvE-specific field

#### Scenario: One configuration per body

- **WHEN** a body already has a configuration
- **THEN** no second configuration is created for it

### Requirement: Existing configurations are carried across

A repair step SHALL copy every `vve-configuration` row onto the generic schema, mapping the deed of division to the constitutive document, the modelreglement version to the regulation version, and the fraction denominator to the vote weight denominator.

It SHALL be idempotent, keyed on the governance body, and SHALL NOT edit or delete any source row.

`modelRegulation` SHALL NOT be carried: it references `modelreglement-preset`, which is retired.

#### Scenario: Rows survive the rename

- **WHEN** the repair step runs on an instance holding VvE configurations
- **THEN** each body has one generic configuration carrying the same facts

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional configuration is created

#### Scenario: The source is untouched

- **WHEN** the repair step runs
- **THEN** every `vve-configuration` row still exists, unedited

### Requirement: The superseded schema is kept, not deleted

`VveConfiguration` SHALL remain declared with `active: false` and `hardDelete: false`, so its rows persist and a rollback finds its data.

#### Scenario: The old schema still holds its rows

- **WHEN** the generic schema is in use
- **THEN** the `vve-configuration` rows are still present
