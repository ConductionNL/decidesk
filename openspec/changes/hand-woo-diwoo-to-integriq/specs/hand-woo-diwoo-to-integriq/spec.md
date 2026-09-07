# hand-woo-diwoo-to-integriq Specification

**Status**: planned
**Scope**: decidiq

## Purpose

Decidiq holds governance records. Mapping them onto a national publication standard belongs elsewhere.

## ADDED Requirements

### Requirement: The app declares no national publication mapping

Decidiq SHALL NOT declare schemas binding its objects to a national publication regime's categories, thesaurus identifiers or delivery packages.

No generic replacement SHALL be declared for them: there is no organisation-neutral concept underneath, and inventing one would be less honest than the Dutch name it replaced.

#### Scenario: A second country's regime is added

- **WHEN** another publication regime has to be supported
- **THEN** it is added where the mappings live, without changing decidiq

### Requirement: The retired rows remain readable

`WooCategorieMapping`, `WooBestuursorgaan` and `RegelingExportPackage` SHALL remain declared with `active: false` and `hardDelete: false`.

An instance's existing rows SHALL be readable under their original slugs, so the app that takes this over can read them across rather than needing an export now.

#### Scenario: An instance keeps its configuration

- **WHEN** an instance that published to Woo is upgraded
- **THEN** every mapping row is still readable under its original schema

### Requirement: No example set plants a publication regime

No example set SHALL seed these schemas.

An example set demonstrates how an organisation governs itself, which is not the same thing as how one country requires it to publish.

#### Scenario: A fresh install plants none

- **WHEN** any example set is loaded
- **THEN** no publication-mapping row is created
