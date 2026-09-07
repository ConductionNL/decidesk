# Tasks

## 1. The fields

- [x] 1.1 A `register.d` fragment adds the four, per ADR-037, rather than
      editing the monolith.
- [x] 1.2 `responsibleOrganisation` is an RSIN string with no `format` and no
      `$ref`.
- [x] 1.3 No `case` reference.
- [x] 1.4 `decisionDate`, `effectiveDate`, `publishedAt` and `isPublished` are
      untouched.

## 2. Verification

- [x] 2.1 Assert on the MERGED register, not the fragment. A fragment naming a
      schema the monolith does not declare creates one instead of failing, and
      the overlay then applies to nothing — stackiq shipped exactly that for
      eight months.
- [x] 2.2 A control asserting the BASE register does NOT carry them, so the test
      cannot pass if the fragment stops being merged.
- [x] 2.3 A test that the four existing date fields survive.
- [x] 2.4 1,426 tests green.

## 3. Not in this change

- [ ] 3.1 dossiq repointing `BrcController` at this register, and retiring its
      own `decision`. That is what actually clears the collision; this only
      makes the target able to hold the record.
