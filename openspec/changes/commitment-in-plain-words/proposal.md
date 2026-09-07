# commitment-in-plain-words

**Status**: planned
**Scope**: decidiq

## Why

A toezegging is a commitment.

Every organisation whose officers answer to a body has them. A portfolio holder promises the council a memo. A director promises the works council a figure. A board member promises the ALV a report. It is made in a meeting, it has a deadline, and it is settled or it lapses.

The properties were already written in plain words. Only the schema was not.

## What changes

`Toezegging` becomes `Commitment`. Every property keeps its name.

Two live schemas referenced it and are repointed: `Raadsinformatiebrief.settledCommitment` and `TermijnagendaItem.originCommitment`. A third reference, on the retired `MondelingeVraag`, is left alone: a retired schema pointing at a retired schema harms nothing.

## Decision: the slug is `governance-commitment`

Schema slugs are global on a shared OpenRegister, and `commitment` belongs to **shillinq**. This is the third time in this programme a bare noun was already taken, after `consultation` (dossiq) and a near miss on `gift`.

## Impact

The dashboard KPI that counts overdue commitments moves with it. Both its schema and its deep-link route had to change: leaving either would give a card that counts nothing, or a link that navigates nowhere, with no error in either case.

Routes follow: `/toezeggingen` becomes `/commitments`.
