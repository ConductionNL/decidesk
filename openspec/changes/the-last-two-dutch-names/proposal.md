# the-last-two-dutch-names

**Status**: planned
**Scope**: decidiq

## Why

A termijnagenda is a forward agenda. A bevoegdheidstoedeling is an authority delegation.

Both are things any organisation does. It plans what it expects to handle, and it records who may act on whose behalf. Neither is particular to a Dutch council.

These are the last two Dutch-named schemas in this app that are not Woo and DiWoo publication plumbing, which moves to integriq separately.

## What changes

`TermijnagendaItem` becomes `PlannedAgendaItem` and `Bevoegdheidstoedeling` becomes `AuthorityDelegation`.

Four properties are renamed with their schema, because they were the only ones still written in Dutch: `delegans` becomes `delegatingBody`, `delegansDescription` becomes `delegatingDescription`, `delegatarisBody` becomes `delegateBody`, `beperkingen` becomes `restrictions`.

Two enums stop constraining. `expectedType` fixed four council document kinds and `ownerType` fixed three council roles. A forward agenda is a planning tool, and what an organisation plans to handle is its own vocabulary. Existing values stay valid because the fields no longer constrain them.

## Decision: one change for two schemas

Every other step of this programme took one concept at a time. These two share no surface, no reference and no vocabulary, so there is nothing for a reviewer to hold in their head at once. Splitting them would double the migration plumbing and the repair-step registration for no reviewer benefit.

## Impact

Routes follow: `/termijnagenda` becomes `/planned-agenda`, `/bevoegdheidstoedelingen` becomes `/authority-delegations`.

After this, decidiq ships no Dutch-named schema except the three Woo and DiWoo ones.
