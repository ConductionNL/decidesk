# plain-words-for-groups-and-the-installation-step Specification

**Status**: planned
**Scope**: decidiq

**OpenSpec changes**:
- [plain-words-for-groups-and-the-installation-step](../../changes/plain-words-for-groups-and-the-installation-step/)

## Purpose

The groups an organisation is notified through, and the steps it works through, are named for what they are.

## ADDED Requirements

### Requirement: Notification groups are named for what they do

No schema SHALL name a recipient group in one country's word for an office.

`decidiq-secretariat` SHALL receive what `griffie` received, and `decidiq-integrity` SHALL receive what `decidesk-integriteit` received.

#### Scenario: A company board is notified

- **WHEN** a director is onboarded and the created notification fires
- **THEN** it addresses the secretariat group, in that word

### Requirement: A renamed group keeps its members and its predecessor

The repair step SHALL copy every member of the old group into the renamed one, SHALL be safe to run again, and SHALL NOT remove a member from, empty, or delete the old group.

An admin's own automation may still name the old group. Renaming the app's declaration must not break it.

#### Scenario: The step runs twice

- **WHEN** the step runs again after a completed run
- **THEN** no membership is added a second time, and the old group is untouched

### Requirement: Stored steps follow the renamed value

Every stored member onboarding step carrying `swearing-in` SHALL be rewritten to `installation`.

The `stepType` vocabulary constrains, so a stored value the schema no longer offers is an invalid record rather than an old-fashioned one.

#### Scenario: A record predating the rename

- **WHEN** a member onboarding holds a step typed `swearing-in`
- **THEN** that step reads `installation` afterwards, and every other step is unchanged
