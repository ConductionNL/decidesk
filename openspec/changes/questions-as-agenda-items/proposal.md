# questions-as-agenda-items

**Status**: planned
**Scope**: decidiq

## Why

Decidiq ships a Dutch council's committee procedure as code.

`MondelingeVraag`, `Interpellatieverzoek` and `VragenuurConfiguratie` are three schemas, two top-level menu entries and four pages. They exist because a council calls one thing a mondelinge vraag and another an interpellatieverzoek. A works council calls both an agendapunt. A VvE calls them a vraag aan het bestuur. None of that is a difference in shape. It is a difference in vocabulary, and vocabulary is configuration.

The destination already exists. `AgendaItemType` was added by configurable-types-domain-model, and its own description says what it is for: to let "oral questions, interpellations, incoming documents, council information letters and kascommissie reports stop being five separate schemas with five top-level menu entries". Three of its properties carry notes reading "Absorbs VragenuurConfiguratie". The target was built. Nothing moved into it.

## What changes

A question becomes an agenda item. Its kind becomes a row an organisation configures. Its distinctive fields move to `AgendaItem.typeFields`, which is what that property was added for.

- Retire the three schemas, non-destructively: `active:false`, `hardDelete:false`, rows kept.
- Add a repair step copying every existing row across, keyed on the source object so a second run copies nothing.
- Fold the question-hour configuration onto the types, where its three facts were already declared.
- Drop two top-level menu entries, four pages and two meeting facets. The agenda already lists these items, and the Type column now names the configured kind.
- Move the vocabulary into the example sets. A Dutch council picking the municipality set gets "Mondelinge vraag" and "Interpellatieverzoek", seeded with their own submission window and support threshold.

## Impact

An operator loses nothing and gains a question hour they can describe in their own words. A council that never used the question hour stops carrying two menu entries for it.

Two pre-existing defects surfaced while doing this, and are fixed here rather than filed:

- Every example set seeded schemas that unified-decision-templates had already retired. Eight templates were planted twice on a fresh association install, once live and once retired. A new test now refuses any example set that seeds a retired schema, which guards every remaining step of this programme rather than this one.
- `objectCount` in each example set is hand-written and shown in the setup wizard. Nothing checked it against the seeds. It had already drifted.
