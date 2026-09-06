# member-onboarding-in-plain-words Specification

**Status**: planned
**Scope**: decidiq

**OpenSpec changes**:
- [member-onboarding-in-plain-words](../../changes/member-onboarding-in-plain-words/)

## Purpose

Joining a governance body, and leaving one, are named for what they are.

## ADDED Requirements

### Requirement: The joining and leaving pathways are named in plain words

`MemberOnboarding` SHALL record one person taking up a place on a governance body. `MemberOffboarding` SHALL record one person leaving one.

The app SHALL NOT declare a schema, a property, or a route named in one country's word for something every organisation does.

#### Scenario: A company admits a director

- **WHEN** a board admits a director and records the steps
- **THEN** it is a member onboarding, with an installation date and an installation type, in those words

### Requirement: The Dutch and ceremony-specific properties are renamed with their schema

`installationType`, `installedOn` and `installationMeeting` SHALL hold what `beëdigingsType`, `swearingInDate` and `swearingInMeeting` held.

#### Scenario: A value lands on a declared property

- **WHEN** a record naming a beëdigingsType is migrated
- **THEN** its `installationType` carries that value, and no property the target does not declare is written

### Requirement: The intake and departure vocabularies are configuration

`trigger`, `endReason` and `installationType` SHALL be free strings.

They fixed one country's turnover, one country's membership, and one country's oath. What an organisation calls its intake, and why a membership ends, is its own vocabulary.

#### Scenario: Existing values stay valid

- **WHEN** a migrated record carries `council-turnover-batch`
- **THEN** it is stored unchanged, because the field no longer constrains it

### Requirement: Existing records are carried across

Every `onboarding-traject` and `offboarding-traject` row SHALL be copied onto its renamed schema, recording which row it came from, and SHALL be copied at most once however often the step runs.

Source rows SHALL NOT be edited or deleted, and both source schemas SHALL keep their definition with `active:false` and `hardDelete:false`.

#### Scenario: The step runs twice

- **WHEN** the repair step runs again after a completed run
- **THEN** no record is copied a second time

### Requirement: The joining and leaving records are not readable by anonymous visitors

`MemberOnboarding` and `MemberOffboarding` SHALL each declare an `authorization` block granting `read` and `list` to `authenticated` only, and SHALL declare no write action.

These records carry a person's name, their account id, when they were installed and why they left. Their predecessors declared no block, so the register baseline governed them, and that baseline lists `public` among both readers and listers.

#### Scenario: An anonymous visitor asks for the collection

- **WHEN** an unauthenticated request lists the member onboarding collection
- **THEN** the schema's own block decides, and it names `authenticated`

### Requirement: No route is named in Dutch

No page route SHALL be spelled in Dutch, whether or not the schema beneath it was renamed.

`/wor-trajecten` and `/audit-statementen` sit on schemas already named in plain words; only their routes and page ids were left behind.

#### Scenario: A reader opens the works-council list

- **WHEN** the works-council consultation list is opened
- **THEN** its route reads `/works-council-consultations`
