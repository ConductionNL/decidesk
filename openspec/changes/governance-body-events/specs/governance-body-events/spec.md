# governance-body-events Specification

## Purpose

An in-process command seam that lets another installed fleet app ask decidiq to
raise a `GovernanceBody` with its roster, and read back the id decidiq gave it.
Per ADR-041 a cross-app command travels as a typed event, not as REST.

## Requirements

### Requirement: REQ-GBE-001 A governance body carries where it came from

`GovernanceBody` SHALL carry `sourceApp` and `externalReference`. Together they
identify the originating record in the producing app, and they are the key the
seam resolves on so a repeated command updates one body rather than minting a
second.

#### Scenario: The pair is additive

- **GIVEN** the register fragment
- **WHEN** the register imports
- **THEN** `GovernanceBody` has `sourceApp` and `externalReference`
- **AND** its `required` list is unchanged, so every stored body stays valid
- **AND** a body created through the existing UI leaves both empty

### Requirement: REQ-GBE-002 The seam is a typed event, dispatched and answered in process

Decidiq SHALL register a listener for `GovernanceBodyRequestedEvent`. The
listener SHALL write the resolved id, the created flag and the handled flag onto
the dispatched instance, so a producer reads the result immediately after
dispatch.

#### Scenario: A request raises a body and answers with its id

- **GIVEN** an installed decidiq and no body for `(dossiq, cmte-1)`
- **WHEN** a producer dispatches `GovernanceBodyRequestedEvent` for it
- **THEN** a `GovernanceBody` exists with the mapped fields
- **AND** the event reports `handled = true`, `created = true`, and a non-empty
  `governanceBodyId`

#### Scenario: A failure leaves the event unhandled and throws nothing

- **GIVEN** OpenRegister rejects the write
- **WHEN** the event is dispatched
- **THEN** `handled` is false and `governanceBodyId` is empty
- **AND** no exception escapes the dispatcher, so an unrelated listener still runs

### Requirement: REQ-GBE-003 A repeated command updates, it does not duplicate

The service SHALL resolve an existing body by `(sourceApp, externalReference)`
before writing. A second command carrying the same pair SHALL update that body
and SHALL NOT create another. The same rule applies to each roster member: a
`Person` resolves by `nextcloudUserId` and a `Membership` by its
`(person, governanceBody)` pair.

#### Scenario: Dispatching the same command twice creates one body

- **GIVEN** a command for `(dossiq, cmte-1)` that has already been handled
- **WHEN** the identical command is dispatched again
- **THEN** exactly one `GovernanceBody` exists for that pair
- **AND** the event reports `created = false` with the same id as the first run

#### Scenario: Dispatching the same command twice creates one seat per member

- **GIVEN** a command whose roster names `alice` as chair, already handled
- **WHEN** the identical command is dispatched again
- **THEN** exactly one `Person` exists with `nextcloudUserId = alice`
- **AND** exactly one `Membership` links that person to that body

#### Scenario: A changed role updates the seat rather than adding one

- **GIVEN** a handled command naming `alice` as `member`
- **WHEN** a command with the same `externalReference` names `alice` as `chair`
- **THEN** her single membership reads `chair`

### Requirement: REQ-GBE-004 The body is written before its roster

The service SHALL persist the `GovernanceBody` and obtain its id BEFORE writing
any `Membership`. A membership SHALL never be written against an unsaved body.

#### Scenario: A crash mid-fan-out leaves a completable body

- **GIVEN** a roster of three where the second membership write fails
- **WHEN** the command is dispatched
- **THEN** the body exists with the first membership
- **AND** re-dispatching the command completes the roster without duplicating
  the first

### Requirement: REQ-GBE-005 `active` is never defaulted silently

`active` decides whether a body may be assigned new work, and the consuming app
throws on it. The service SHALL require the command to state it, and SHALL
refuse a command that omits it rather than assuming `true`.

#### Scenario: An omitted active is refused

- **WHEN** a command is dispatched with no `active`
- **THEN** the seam refuses it, no body is written, and `handled` is false

#### Scenario: An archived committee stays archived across a re-run

- **GIVEN** a handled command that set `active = false`
- **WHEN** the identical command is dispatched again
- **THEN** the body still reads `active = false`

### Requirement: REQ-GBE-006 The producer learns the outcome by correlation

After a successful command the listener SHALL dispatch
`GovernanceBodyCreatedEvent` carrying the `correlationId` from the request, the
resulting `governanceBodyId`, and whether the body was created or matched.

#### Scenario: The conclusion echoes the correlation

- **GIVEN** a command carrying `correlationId = abc`
- **WHEN** it is handled
- **THEN** a `GovernanceBodyCreatedEvent` is dispatched with `correlationId =
  abc` and the same id the request reports
