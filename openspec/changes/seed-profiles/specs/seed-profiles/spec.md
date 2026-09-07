# seed-profiles Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [seed-profiles](../../changes/seed-profiles/)

## Purpose

Example data an operator chooses, instead of example data an install imposes. The app ships one descriptor per kind of organisation; the first-time setup wizard asks which one, and imports only that.

**Standards**: ADR-042 (first-time setup contract), ADR-111 (demo data), ADR-037 (additive register fragments)

## ADDED Requirements

### Requirement: Installing the app plants no objects

A register fragment SHALL declare schemas only. `decidesk_register.json` and every file in `lib/Settings/register.d/` SHALL NOT carry `x-openregister.seedData`.

Installing or upgrading the app SHALL therefore create registers and schemas and zero objects. An operator who never opens the wizard SHALL find an empty app, not somebody else's organisation.

#### Scenario: A fresh install seeds nothing

- **WHEN** the app is installed and `SettingsService::loadConfiguration()` runs
- **THEN** the merged configuration carries no `x-openregister.seedData` key
- **AND** no example object is created

#### Scenario: The seeds still ship

- **WHEN** the example sets are read from `lib/Settings/profiles/`
- **THEN** every object formerly seeded by a register fragment is present in at least one set

### Requirement: List example sets

The system SHALL expose the example sets it ships, each carrying `id`, `label`, `description` and `objectCount`, ordered by the `order` its descriptor declares.

The list SHALL be read from the descriptors on disk rather than from a list in code, so a set that ships without being offered is impossible.

`GET /api/setup/status` SHALL include the list as `profiles`.

#### Scenario: Every shipped set is offered

- **WHEN** an administrator requests the setup status
- **THEN** the response lists one entry per descriptor in `lib/Settings/profiles/`
- **AND** each entry names a non-empty label and a positive object count

#### Scenario: The generated set is offered only when it ships

- **WHEN** `decidiq_mock_register.json` is absent
- **THEN** the `generated` option is not offered
- **AND** the wizard does not present an import that cannot run

### Requirement: Record the chosen example set

`POST /api/setup/config` SHALL accept `example_profile` and persist it, and SHALL be admin-only.

The endpoint SHALL read exactly one named key from the request and SHALL NOT write a caller-supplied key. The app's own settings share the appconfig namespace, including `voter_token_secret`, the HMAC key signing every voting token and mail-reply link.

A value that names neither a shipped set nor `none` SHALL be rejected with 400 and SHALL NOT be stored.

#### Scenario: A pick is persisted

- **WHEN** an administrator posts `example_profile: municipality`
- **THEN** the value is stored and echoed back

#### Scenario: An unknown set is refused

- **WHEN** an administrator posts an id no descriptor declares
- **THEN** the response is 400 and nothing is stored

### Requirement: Import one example set

The system SHALL import the chosen set and report how many objects it carried.

The import SHALL be idempotent: running it twice SHALL add nothing, because OpenRegister matches an existing object by slug before creating one.

A failure SHALL be reported to the operator and SHALL NOT record the step as decided. An operator who asked for example data and received none must be able to ask again.

Running the action with no set chosen SHALL be refused with 400 rather than defaulting to one.

#### Scenario: Loading reports what landed

- **WHEN** an administrator runs `load-example-set` after choosing a set
- **THEN** the response names a non-zero object count

#### Scenario: Loading twice adds nothing

- **WHEN** the action runs a second time
- **THEN** it succeeds and the object count in the register is unchanged

#### Scenario: Loading without a choice refuses

- **WHEN** the action runs and no set has been chosen
- **THEN** the response is 400 and no object is created

#### Scenario: A failed import leaves the step open

- **WHEN** the import throws
- **THEN** the response reports the failure
- **AND** the step is not recorded as decided

### Requirement: Declining is an answer

`none` SHALL be a selectable value. Choosing it SHALL mark both the choice step and the load step done without importing anything.

A step that can never be marked done reopens the wizard over every page, so "no thanks" has to be expressible.

#### Scenario: Choosing none closes the wizard

- **WHEN** an administrator chooses `none`
- **THEN** both setup steps report `done: true`
- **AND** no object is created

### Requirement: An example set never rewrites the register

An example-set descriptor SHALL NOT declare `components.registers`. Every seed object SHALL carry `@self` naming the configuration, register and schema it belongs to.

`ImportHandler::importRegister()` calls `setApplication($appId)` unconditionally when it updates an existing register, so a descriptor that declared the register would re-point it at the profile's configuration id and hydrate over its `authorization` baseline.

Descriptors SHALL live in `lib/Settings/profiles/`, not `lib/Settings/`, because `RegisterDescriptorService` scans the latter non-recursively and indexes descriptors by declared register slug.

#### Scenario: Importing a set leaves the register untouched

- **WHEN** an example set is imported
- **THEN** the register's `application`, version and `authorization` are unchanged

#### Scenario: Every seed resolves its own register

- **WHEN** a shipped example set is read
- **THEN** every object carries `@self` naming the `decidiq` register and its own schema slug

### Requirement: A set identifier cannot escape the profile directory

The set id arrives from an HTTP request. The system SHALL resolve a descriptor by matching the id declared INSIDE each file, and SHALL NOT build a path by concatenating the id.

#### Scenario: A traversal id resolves to nothing

- **WHEN** an id such as `../../config/config` is submitted
- **THEN** it is not recognised as a set and the import throws
