# integrity-disclosures-in-plain-words Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [integrity-disclosures-in-plain-words](../../changes/integrity-disclosures-in-plain-words/)

## Purpose

The declarations a board member makes are named for what they are.

## ADDED Requirements

### Requirement: Disclosures are named in plain words

`AncillaryPosition` SHALL record a role a member holds outside the organisation. `DeclaredGift` SHALL record a gift or invitation a member received and declared.

Both SHALL use qualified slugs, because schema slugs are global on a shared OpenRegister.

The app SHALL NOT declare a schema named in one country's word for a concept every organisation has.

#### Scenario: A company records a director's other board seat

- **WHEN** a director declares a seat on another board
- **THEN** it is an ancillary position, and no Dutch-named schema is involved

### Requirement: The integrity policy folds into the body configuration

`BodyGovernanceConfiguration` SHALL carry the disclosure default, gift threshold, gift publicity and notification group the retired `Integriteitsbeleid` held.

A repair step SHALL fold each policy onto the configuration for the SAME body, UPDATING an existing configuration rather than replacing it.

#### Scenario: An existing configuration keeps its other fields

- **WHEN** a body already has a configuration carrying a constitutive document
- **THEN** folding its integrity policy adds the integrity fields and leaves the document in place

#### Scenario: A body with no configuration gets one

- **WHEN** a body has an integrity policy and no configuration
- **THEN** a configuration is created carrying both the body and the policy's fields

### Requirement: Existing disclosures are carried across

A repair step SHALL copy every `nevenfunctie` and `geschenk` row onto the renamed schema, resolving every reference to a uuid before writing it.

Every property SHALL keep its name: the properties were already in plain words.

It SHALL be idempotent, keyed on the SOURCE object's identifier, and SHALL NOT edit or delete any source row.

#### Scenario: Rows survive the rename

- **WHEN** the repair step runs on an instance holding disclosures
- **THEN** each is readable under the new schema with the same values

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional disclosure is created

### Requirement: The superseded schemas are kept, not deleted

`Nevenfunctie`, `Geschenk` and `Integriteitsbeleid` SHALL remain declared with `active: false` and `hardDelete: false`.

#### Scenario: The old schemas still hold their rows

- **WHEN** the change is applied
- **THEN** every source row is still readable under its original schema
