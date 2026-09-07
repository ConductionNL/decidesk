# documents-as-agenda-items tasks

## 1. Retire the three schemas

- [x] 1.1 Mark all three `active:false`, `hardDelete:false`, each naming its destination.

## 2. Carry the rows across

- [x] 2.1 Add `MigrateDocumentsToAgendaItems`, letters before questions.
- [x] 2.2 Retarget a question's parent at the letter's copy.
- [x] 2.3 Truncate the title, keep the full text in `typeFields`.
- [x] 2.4 Register the repair step.

## 3. Remove the surfaces

- [x] 3.1 Drop the four pages and the menu entry.
- [x] 3.2 Delete the fragment that emptied, and correct the note on the one that did not.
- [x] 3.3 Update the menu layout, and let the drift guard confirm it.

## 4. Move the vocabulary to the example sets

- [x] 4.1 Seed the three kinds and convert the municipality set's rows.

## 5. Prove it

- [ ] 5.1 Unit tests for the migration, including the parent retarget.
