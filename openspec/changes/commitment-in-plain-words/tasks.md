# commitment-in-plain-words tasks

## 1. Rename

- [x] 1.1 Declare `Commitment`, property for property, carrying its authorization block.
- [x] 1.2 Use `governance-commitment`; `commitment` belongs to shillinq.
- [x] 1.3 Retire `Toezegging`, non-destructively.
- [x] 1.4 Repoint the two LIVE schemas that referenced it, naming the properties they actually declare.
- [x] 1.5 Add the slug to the register's own `schemas` list.

## 2. Carry the rows across

- [x] 2.1 Add `MigrateCommitments`, resolving every reference to a uuid.
- [x] 2.2 Register the repair step.

## 3. Follow the rename through

- [x] 3.1 Rename the pages, menu entry and route.
- [x] 3.2 Repoint the decision-detail widget and the dashboard KPI, schema AND route.
- [x] 3.3 Update the menu layout, and let the drift guard confirm it.
- [x] 3.4 Convert every profile's seeds.

## 4. Prove it

- [ ] 4.1 Unit tests for the migration.
- [ ] 4.2 Extend the authorization-inheritance guard to this rename.
