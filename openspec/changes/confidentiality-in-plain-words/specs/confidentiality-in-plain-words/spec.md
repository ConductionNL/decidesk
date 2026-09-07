# confidentiality-in-plain-words Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [confidentiality-in-plain-words](../../changes/confidentiality-in-plain-words/)

## Purpose

Restricting what an organisation circulates is named for what it is, and the grounds are configuration.

## ADDED Requirements

### Requirement: Confidentiality is named in plain words

`ConfidentialityRestriction` SHALL record a restriction a body imposes on a document, an agenda item or a decision. `ConfidentialityGround` SHALL record a ground it may be imposed on.

The app SHALL NOT declare a schema named in one country's word for something every organisation does.

#### Scenario: A company restricts a board paper

- **WHEN** a board restricts circulation of a paper on a stated ground
- **THEN** it is a confidentiality restriction, and no Dutch-named schema is involved

### Requirement: The ground category is configuration

`ConfidentialityGround.category` SHALL be a free string.

The schema already declares grounds to be data rather than code, and an enum of one country's statutes contradicts that.

#### Scenario: An organisation groups grounds its own way

- **WHEN** an organisation classifies a ground in its own terms
- **THEN** it records that term, without the app declaring it first

#### Scenario: Existing values stay valid

- **WHEN** a ground recorded under one of the four retired categories is read
- **THEN** it validates, and its value is unchanged

### Requirement: Existing restrictions are carried across

A repair step SHALL copy every `geheimhouding-grond` row onto `confidentiality-ground` and every `geheimhouding` row onto `confidentiality-restriction`, IN THAT ORDER.

A restriction's `ground` SHALL name the COPIED ground, not the retired one.

It SHALL be idempotent, keyed on the SOURCE object's identifier, and SHALL NOT edit or delete any source row.

#### Scenario: A restriction points at the copied ground

- **WHEN** a restriction imposed on a seeded ground is migrated
- **THEN** its `ground` names the copied ground, not the retired one

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional record is created

### Requirement: The superseded schemas are kept, not deleted

`Geheimhouding` and `GeheimhoudingGrond` SHALL remain declared with `active: false` and `hardDelete: false`.

#### Scenario: The old schemas still hold their rows

- **WHEN** the change is applied
- **THEN** every source row is still readable under its original schema
