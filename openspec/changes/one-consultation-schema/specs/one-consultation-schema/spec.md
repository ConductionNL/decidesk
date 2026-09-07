# one-consultation-schema Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [one-consultation-schema](../../changes/one-consultation-schema/)

## Purpose

One schema for asking a set of parties their view, and one for their answer, whatever the organisation calls either.

## ADDED Requirements

### Requirement: One pair of schemas models being asked and answering

`Consultation` SHALL model an organisation asking a set of parties for their view on something by a deadline. `ConsultationResponse` SHALL model one party's answer and what the asking body did with it.

The app SHALL NOT declare a second pair for the same concept under another organisation's vocabulary.

Both SHALL use qualified slugs, because schema slugs are global on a shared OpenRegister and the bare ones are taken.

#### Scenario: A works council records a constituency poll

- **WHEN** a works council asks its constituency a question
- **THEN** it is a consultation, and no council-specific schema is involved

#### Scenario: A board records a formal advice request

- **WHEN** a board asks an advisory committee for a formal advice
- **THEN** it is a consultation with `binding` set, and the advice is a response

### Requirement: Binding is a field, not a schema boundary

`Consultation.binding` SHALL record whether the answers bind the asking body or only inform it.

The retired schemas drew that line in prose, which nothing can filter on.

#### Scenario: An operator finds every binding consultation

- **WHEN** an operator filters consultations by binding
- **THEN** formal instruments are listed and informal polls are not

### Requirement: The subject vocabulary is configuration

`Consultation.subjectType` SHALL be a free string.

The retired round schema fixed seven values from one arrangement, which is a vocabulary the app should not ship.

#### Scenario: An organisation uses its own word

- **WHEN** an organisation consults on something its own vocabulary names
- **THEN** it records that word, without the app declaring it first

### Requirement: Existing consultations are carried across

A repair step SHALL copy every `advice-request`, `zienswijzeronde` and `member-consultation` row onto the consultation schema, and every `advies`, `zienswijze` and `member-consultation-response` row onto the response schema.

It SHALL copy the asks BEFORE the answers, because an answer references its ask.

It SHALL be idempotent, keyed on the SOURCE object's identifier in the declared `migratedFromObject` property, and SHALL NOT edit or delete any source row.

An answer whose ask could not be copied SHALL be skipped rather than bound to nothing.

`no-advice` and `no-view` SHALL both become `none`, because both mean the party declined to give a view.

#### Scenario: Rows survive the fold

- **WHEN** the repair step runs on an instance holding advice requests and views
- **THEN** each is a consultation or a response carrying its subject, party, position and status

#### Scenario: Answers point at their ask

- **WHEN** a round with four views is migrated
- **THEN** all four responses reference the copied consultation

#### Scenario: Running twice changes nothing

- **WHEN** the repair step runs a second time
- **THEN** no additional consultation or response is created

#### Scenario: Two spellings of no view become one

- **WHEN** an advice recorded `no-advice` and a view recorded `no-view`
- **THEN** both are `none` after the fold

### Requirement: Nothing references a retired schema

`ConsultationRequest.constituencyConsultation` SHALL reference the generic consultation.

#### Scenario: The works-council request still resolves

- **WHEN** a works-council request naming a constituency consultation is read
- **THEN** its reference resolves to a live schema

### Requirement: The superseded schemas are kept, not deleted

`Adviesaanvraag`, `Advies`, `Zienswijzeronde`, `Zienswijze`, `MemberConsultation` and `MemberConsultationResponse` SHALL remain declared with `active: false` and `hardDelete: false`.

#### Scenario: The old schemas still hold their rows

- **WHEN** the change is applied to an instance holding these records
- **THEN** every source row is still readable under its original schema
