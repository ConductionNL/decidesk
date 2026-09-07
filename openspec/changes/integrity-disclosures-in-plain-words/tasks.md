# integrity-disclosures-in-plain-words tasks

## 1. Rename what is only named in Dutch

- [x] 1.1 Declare `AncillaryPosition` and `DeclaredGift`, property-for-property.
- [x] 1.2 Use `declared-gift`, because a bare `gift` is a slug another app would claim.
- [x] 1.3 Retire `Nevenfunctie` and `Geschenk`, non-destructively.
- [x] 1.4 Add both slugs to the register's own `schemas` list.

## 2. Fold the second per-body configuration

- [x] 2.1 Add the four integrity fields to `BodyGovernanceConfiguration`.
- [x] 2.2 Retire `Integriteitsbeleid`.
- [x] 2.3 UPDATE an existing configuration rather than replacing it, so another change's fields survive.

## 3. Carry the rows across

- [x] 3.1 Add `MigrateIntegrityDisclosures`, resolving every reference to a uuid.
- [x] 3.2 Register the repair step.

## 4. Follow the rename through the surfaces

- [x] 4.1 Rename the pages, menu entries and routes.
- [x] 4.2 Repoint the two governance-body widgets.
- [x] 4.3 Update the menu layout, and let the drift guard confirm it.
- [x] 4.4 Convert every profile's seeds.

## 5. Prove it

- [ ] 5.1 Unit tests for the migration.
- [ ] 5.2 E2E: the renamed surfaces resolve and read their schema.
