# documents-as-agenda-items

**Status**: planned
**Scope**: decidiq

## Why

This finishes a sentence the model has been carrying since configurable-types-domain-model. `AgendaItemType`'s own description says it exists so that "oral questions, interpellations, incoming documents, council information letters and kascommissie reports stop being five separate schemas with five top-level menu entries".

Three of the five are done. These are the other two.

An incoming document and a letter from the executive are both something put before a body at a meeting, which is what `AgendaItem` already models. Their distinctive fields go in `typeFields`, which is what that property was added for.

`TechnischeVraag` never had an identity of its own: `rib` was required, so a question without a letter could not exist. It is a sub-item, which the agenda already models through `parentItem`.

## What changes

- Retire the three schemas non-destructively.
- Copy every row onto `agenda-item`, with its kind in a per-body `AgendaItemType`.
- Turn the references they held into the nesting they were describing: a document hangs under the list item it was registered on, a question under its letter.
- Move the vocabulary into the example sets, which is where a council's words for its post belong.

## Decision: letters are copied before questions

A question's parent is a letter. Copied in the other order, or copied with `rib` untouched, every question would name the RETIRED letter while living on the new schema — so the sub-item relationship this change exists to express would point at the wrong side of the migration.

## Decision: the title is truncated, the text is not

`AgendaItem.title` is required and a technical question can run to a paragraph. The title takes the first 250 characters; the full question stays in `typeFields.question`, so nothing is lost and no list is broken by a title that is really a body.

## Impact

Two more top-level surfaces go, and the raadsinformatiebrieven fragment empties out entirely. Nothing is lost: the documents are on the agenda, which is where a clerk looks for them.
