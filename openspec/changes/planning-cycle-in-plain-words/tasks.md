# planning-cycle-in-plain-words tasks

## 1. Rename

- [x] 1.1 Declare the three schemas in plain words, property for property.
- [x] 1.2 Rename `cyclus` to `cycle`, the one property that was itself Dutch.
- [x] 1.3 Retire the three source schemas, non-destructively.
- [x] 1.4 Repoint `Consultation.cycleStep`.
- [x] 1.5 Add the three slugs to the register's own `schemas` list.

## 2. Carry the rows across

- [x] 2.1 Add `MigratePlanningCycles`, copying in dependency order.
- [x] 2.2 Make a reference follow the record it points at.
- [x] 2.3 Register the repair step.

## 3. Follow the rename through

- [x] 3.1 Rename the pages, menu entry and routes.
- [x] 3.2 Update the menu layout, and let the drift guard confirm it.
- [x] 3.3 Convert every profile's seeds.

## 4. Prove it

- [ ] 4.1 Unit tests for the migration, including the reference retarget.
