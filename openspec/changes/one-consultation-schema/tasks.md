# one-consultation-schema tasks

## 1. One pair of schemas

- [x] 1.1 Declare `Consultation` and `ConsultationResponse` as the union of the three pairs.
- [x] 1.2 Use `governance-consultation` slugs: the bare ones are global and taken.
- [x] 1.3 Record binding-versus-advisory as a field rather than as a schema boundary.
- [x] 1.4 Make `subjectType` a free string rather than one arrangement's seven values.
- [x] 1.5 Retire the six schemas non-destructively.
- [x] 1.6 Repoint `ConsultationRequest.constituencyConsultation`.
- [x] 1.7 Add both slugs to the register's own `schemas` list.

## 2. Carry the rows across

- [x] 2.1 Add `MigrateConsultationsToOneSchema`, asks before answers.
- [x] 2.2 Resolve every reference to a uuid before writing it.
- [x] 2.3 Map the two spellings of "no view given" onto one value.
- [x] 2.4 Skip an answer whose ask could not be copied.
- [x] 2.5 Register the repair step.

## 3. Four surfaces become one

- [x] 3.1 Replace three manifest fragments with one.
- [x] 3.2 Collapse the three identical decision widgets into one, and repack the layout.
- [x] 3.3 Register the icon the new widgets use.

## 4. Move the vocabulary to the example sets

- [x] 4.1 Convert every profile's consultations and responses.

## 5. Prove it

- [ ] 5.1 Unit tests for the migration.
- [ ] 5.2 E2E: a consultation and its responses render.
