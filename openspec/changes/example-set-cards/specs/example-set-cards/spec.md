# example-set-cards Delta: example-set-cards

**Status**: in-progress
**Scope**: decidiq
**OpenSpec changes**:

- [example-set-cards](../../)

## Purpose

The example sets, shown as what they are: a card each, carrying the description
and the object count the descriptors already declare, with more than one
loadable at a time. Extends [seed-profiles](../../../seed-profiles/). Standards:
ADR-042 (first-time setup contract), ADR-111 (demo data).

## ADDED Requirements

### Requirement: The wizard offers the sets the app ships

`SeedProfileService::listChoices()` SHALL return every answer the choice step
may offer: `none` first, then every shipped set in its declared order, then the
generated dataset when it ships.

Every entry SHALL carry a non-empty `label`, `description` and `icon`, and an
`objectCount`. A card renders all four, so an entry missing one renders a blank
card.

`none` SHALL NOT appear in `listProfiles()` and `isKnown('none')` SHALL be
false. Declining is an answer, not a descriptor, and the importer must never be
handed it.

`GET /api/setup/status` SHALL return the list as `profiles`, and
`src/manifest.json` SHALL declare `optionsSource: "profiles"` and no `options`
of its own.

#### Scenario: Declining leads the list

- **WHEN** the choices are listed
- **THEN** the first entry SHALL be `none`
- **AND** the shipped sets SHALL follow in their declared order

#### Scenario: Declining is not importable

- **WHEN** `isKnown('none')` is asked
- **THEN** it SHALL answer false
- **AND** `none` SHALL NOT appear in `listProfiles()`

#### Scenario: The manifest keeps no second copy of the list

- **WHEN** the `example-set` step is read from the manifest
- **THEN** it SHALL declare `optionsSource: "profiles"`, `display: "cards"` and
  `multiple: true`
- **AND** it SHALL NOT declare `options`

#### Scenario: Every set can say what it is

- **WHEN** the choices are listed
- **THEN** each entry SHALL carry a non-empty label, description and icon

### Requirement: Several example sets can be loaded at once

The choice step SHALL accept more than one set. `POST /api/setup/config` SHALL
accept `example_profile` as a single value or as a list, SHALL reject the whole
pick when any entry names neither a shipped set nor `none`, and SHALL store the
accepted ids as a comma-separated list.

A repeated id SHALL be stored once. When `none` is picked alongside a set, the
set SHALL win and `none` SHALL be dropped: the cards are checkboxes, so both can
be ticked, and storing both would leave the load step reading two contradictory
instructions from one value.

A value that is not a scalar SHALL be refused with 400.

#### Scenario: Two sets are stored as a list

- **WHEN** an administrator posts `["municipality", "works-council"]`
- **THEN** `municipality,works-council` SHALL be stored

#### Scenario: One bad id refuses the whole pick

- **WHEN** an administrator posts `["municipality", "atlantis"]`
- **THEN** the response SHALL be 400 naming `atlantis`
- **AND** nothing SHALL be stored

#### Scenario: None alongside a set keeps the set

- **WHEN** an administrator posts `["none", "municipality"]`
- **THEN** `municipality` SHALL be stored

### Requirement: Loading imports every set that was picked

`load-example-set` SHALL import each picked set in order and SHALL report the
total objects and the number of sets.

A set that throws SHALL fail the whole action with 500, SHALL name the sets that
already landed, and SHALL NOT record the step as decided. The sets that landed
SHALL be left in place: the import matches an existing object by slug before
creating one, so running the step again finishes the job rather than doubling
what arrived.

Running with nothing picked SHALL be refused with 400 rather than defaulting to
a set.

#### Scenario: Both picked sets are imported

- **GIVEN** `municipality,works-council` is stored
- **WHEN** the load action runs
- **THEN** both sets SHALL be imported
- **AND** the message SHALL name the combined object count and 2 sets

#### Scenario: A failure halfway through says what already landed

- **GIVEN** two sets are picked and the second import throws
- **WHEN** the load action runs
- **THEN** the response SHALL be 500 naming the set that already landed
- **AND** the step SHALL NOT be recorded as decided

## MODIFIED Requirements

### Requirement: Record the chosen example set

`POST /api/setup/config` SHALL accept `example_profile` and persist it, and SHALL
be admin-only.

The endpoint SHALL read exactly one named key from the request and SHALL NOT
write a caller-supplied key. The app's own settings share the appconfig
namespace, including `voter_token_secret`, the HMAC key signing every voting
token and mail-reply link.

The value MAY name several sets. A value that names neither a shipped set nor
`none` SHALL be rejected with 400 and SHALL NOT be stored, and one bad entry
SHALL reject the whole pick.

#### Scenario: A pick is persisted

- **WHEN** an administrator posts `example_profile: municipality`
- **THEN** the value is stored and echoed back

#### Scenario: An unknown set is refused

- **WHEN** an administrator posts an id no descriptor declares
- **THEN** the response is 400 and nothing is stored

### Requirement: List example sets

The system SHALL expose the example sets it ships, each carrying `id`, `label`,
`description`, `objectCount` and `icon`, ordered by the `order` its descriptor
declares.

The list SHALL be read from the descriptors on disk rather than from a list in
code, so a set that ships without being offered is impossible.

`GET /api/setup/status` SHALL include the offerable list as `profiles`, which is
`listChoices()`: the shipped sets plus `none`.

#### Scenario: Every shipped set is offered

- **WHEN** an administrator requests the setup status
- **THEN** the response lists one entry per descriptor in `lib/Settings/profiles/`
- **AND** each entry names a non-empty label and a positive object count

#### Scenario: The generated set is offered only when it ships

- **WHEN** `decidiq_mock_register.json` is absent
- **THEN** the `generated` option is not offered
- **AND** the wizard does not present an import that cannot run

### Requirement: Declining is an answer

`none` SHALL be a selectable value. Choosing it SHALL mark both the choice step
and the load step done without importing anything.

A step that can never be marked done reopens the wizard over every page, so "no
thanks" has to be expressible.

Choosing it alongside a set SHALL NOT be an error: the set wins.

#### Scenario: Choosing none closes the wizard

- **WHEN** an administrator chooses `none`
- **THEN** both setup steps report `done: true`
- **AND** no object is created
