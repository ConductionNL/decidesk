# retire-the-unbuilt-rooster tasks

## 1. Move the configuration, retire the projection

- [x] 1.1 Add `notes` and `migratedFromObject` to `PositionType`.
- [x] 1.2 Retire `TermijnRegeling`, `RoosterVanAftreden` and `RoosterRegel`, non-destructively.
- [x] 1.3 Copy every term rule onto the position it governs, role enum to position name.
- [x] 1.4 Do NOT copy the projection, and say in the schema why.
- [x] 1.5 Register the repair step.

## 2. Replace the surfaces

- [x] 2.1 Remove the six rooster and term-rule pages, and the fragment they emptied.
- [x] 2.2 Add a `PositionHolds` index and detail, sorted by whose term ends next.
- [x] 2.3 Update the menu layout, and let the drift guard confirm it.

## 3. Move the vocabulary to the example sets

- [x] 3.1 Convert every profile's term rules; drop the seeded projection rows.

## 4. Prove it

- [ ] 4.1 Unit tests for the migration.
- [ ] 4.2 E2E: the position-holder surface resolves and reads its schema.
