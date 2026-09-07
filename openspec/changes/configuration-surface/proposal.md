# configuration-surface

**Status**: planned
**Scope**: decidiq

## Why

This programme keeps replacing code with configuration, and an operator could not edit any of it.

`MeetingType`, `AgendaItemType`, `PositionType` and `GovernanceBodyComposition` have existed since configurable-types-domain-model. None of them has a page. Not an index, not a detail, not a menu entry. The only way a kind ever reached an instance was a seeded example set, and once seeded nobody could rename it, add another, or change a submission window.

questions-as-agenda-items made that worse in the right direction: an oral question is now an agenda item whose TYPE says what kind it is, so `AgendaItemType` went from unused to load-bearing while still having nowhere to live.

Configuration nobody can edit is not much better than the code it replaced.

## What changes

An index and a detail page for each of the four, in the settings foldout. `DecisionTemplates` is already there, so the group exists and this joins it.

Each detail page shows what uses the type, because the question an operator actually has before editing one is what it will affect. An agenda-item type lists the items carrying it; a position type lists who holds it.

## Also: seven rules that pointed at nothing

`src/menu-layout.json` relocated seven menu entries that no longer exist. They were removed by questions-as-agenda-items, fold-regulations-into-governing-documents and one-consultation-schema, which took the entries out and left the rules that placed them.

`check:nav-ceiling` cannot see this. It asks whether every entry that exists is placed, never whether every rule still has an entry, so the drift was invisible and accumulating. A test now checks the other direction, and it is proven to fail on a planted stale rule before being trusted.

## Impact

The configurable types become editable, which is what the rest of this programme has been assuming. Four entries join the settings foldout; the primary menu is untouched and stays at its ADR-004 ceiling of six.
