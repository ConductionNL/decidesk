# the-last-two-dutch-names tasks

## 1. Rename

- [x] 1.1 Declare `PlannedAgendaItem` and `AuthorityDelegation`.
- [x] 1.2 Rename the four properties that were themselves Dutch.
- [x] 1.3 Make `expectedType` and `ownerType` free strings.
- [x] 1.4 Retire both source schemas, non-destructively.
- [x] 1.5 Add both slugs to the register's own `schemas` list.

## 2. Carry the rows across

- [x] 2.1 Add `MigrateTheLastTwoDutchNames`, renaming the four properties as it copies.
- [x] 2.2 Retarget `parentAllocation` and `originCommitment`.
- [x] 2.3 Register the repair step.

## 3. Follow the rename through

- [x] 3.1 Rename the pages, menu entries, routes and column keys.
- [x] 3.2 Update the menu layout, and let the drift guard confirm it.
- [x] 3.3 Convert every profile's seeds, renaming the four properties.

## 4. Prove it

- [ ] 4.1 Unit tests for the migration, including the property renames.
