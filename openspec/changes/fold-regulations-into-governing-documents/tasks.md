# fold-regulations-into-governing-documents tasks

## 1. Merge the two schemas

- [x] 1.1 Add the absorbed properties to `GoverningDocument`, with `cvdrIdentifier` renamed.
- [x] 1.2 Union the `type` and `status` enums.
- [x] 1.3 Declare `migratedFromObject` on both generic schemas, so the idempotency key persists.
- [x] 1.4 Retire `Regeling` and `RegelingVersie`, non-destructively.
- [x] 1.5 Repoint `RegelingExportPackage.regulation` at `governing-document`.

## 2. Carry the rows across

- [x] 2.1 Add `MigrateRegulationsToGoverningDocuments`, documents before versions.
- [x] 2.2 Resolve every reference to a uuid before writing it.
- [x] 2.3 Skip a version whose parent could not be copied, rather than binding it to nothing.
- [x] 2.4 Register the repair step.

## 3. Remove the surfaces

- [ ] 3.1 Delete the verordeningenregister manifest fragment: one menu entry, three pages.
- [ ] 3.2 Check whether the version detail page has a generic counterpart, and add one if not.

## 4. Move the vocabulary to the example sets

- [ ] 4.1 Convert the municipality set's regulations into governing documents.

## 5. Prove it

- [ ] 5.1 Unit tests: mapping, the rename, idempotency, ordering, an orphan version.
- [ ] 5.2 E2E: the regulations still render, under the generic surface.
