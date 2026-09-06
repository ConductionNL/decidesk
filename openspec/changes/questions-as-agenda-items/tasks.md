# questions-as-agenda-items tasks

## 1. Retire the three schemas

- [x] 1.1 Add `76-questions-as-agenda-items.json` marking `MondelingeVraag`, `Interpellatieverzoek` and `VragenuurConfiguratie` `active:false`, `hardDelete:false`, each naming its destination.

## 2. Carry the rows across

- [x] 2.1 Add `MigrateQuestionsToAgendaItems`, wrapped in `runAsSystem()` and keyed on the source object's uuid.
- [x] 2.2 Add `AgendaItemTypeResolver`, resolving a kind by (owning body, name) and creating it when a body has none.
- [x] 2.3 Fold `vragenuur-configuratie` onto the types: the submission window onto both kinds, the support threshold only onto the kind that needs support.
- [x] 2.4 Register the repair step after `MigrateKascommissieToAuditStatement`.

## 3. Remove the surfaces

- [x] 3.1 Delete `src/manifest.d/vragenuur-interpellatie.json`: two menu entries, four pages.
- [x] 3.2 Remove the two MeetingDetail facets and their layout cells, and close the gap they left.
- [x] 3.3 Show the configured kind in the agenda's Type column, falling back to the coarse enum.

## 4. Move the vocabulary to the example sets

- [x] 4.1 Convert the municipality set's questions into typed agenda items, and its question-hour configuration into two seeded types.
- [x] 4.2 Nest admitted oral questions under the question-hour item, which is what the seed always described.
- [x] 4.3 Drop the two hand-written interpellation stubs the old model needed beside each request.

## 5. Pay the debt this uncovered

- [x] 5.1 Stop every example set seeding `process-template`, `vve-decision-template` and `modelreglement-preset`, retired by unified-decision-templates.
- [x] 5.2 Keep the one rule the model regulations actually disagree on: MR 1992 needs three quarters where 2006 and 2017 need two thirds.
- [x] 5.3 Add a test refusing any example set that seeds a retired schema.
- [x] 5.4 Add a test asserting each set's advertised `objectCount` matches its seeds.
- [x] 5.5 Extract `ReadsLegacyRows`: three migrations had the same four helpers written out.
- [x] 5.6 Fix `($a['id'] ?? $a['uuid']) ?? ''` in four places. The parentheses defeat the null coalesce and it warns.

## 6. Prove it

- [x] 6.1 Unit tests for the migration: mapping, idempotency, one type per body, an unidentifiable row, the configuration fold.
- [x] 6.2 Rewrite the meeting-facet e2e: assert an agenda item renders under its configured type name, and that the interpellations facet is gone.
