# retire-the-unbuilt-rooster

**Status**: planned
**Scope**: decidiq

## Why

The rooster van aftreden was designed as a projection, and the projector was never written.

`RoosterVanAftreden` describes itself as "a regeneratable projection, never hand-maintained truth". `RoosterRegel` says it is "materialized on generation" by `RoosterService`. That service does not exist. `appointments-and-terms` shipped its declarative core and none of its sixteen implementation tasks, so an operator's rooster showed whatever an example set had seeded and never changed again.

Meanwhile `PositionType` and `PositionHold` model the same facts as source data. They already declare `termDurationMonths`, `maxConsecutiveTerms`, `reappointable`, `termNumber`, `startDate` and `endDate`, and configuration-surface just gave them pages.

## What changes

`TermijnRegeling` is real configuration, so it moves. A term rule is a property of a position: the `role` enum becomes the position's name, `body` becomes `governanceBody`, `maxConsecutivePeriods` becomes `maxConsecutiveTerms`.

The two projection schemas retire without being copied.

## Decision: the projection is not carried forward

It is tempting to migrate the rooster rows so nothing appears lost. Two reasons not to.

A projection is not source data. Those rows were a snapshot of facts that `PositionHold` owns, taken by a generator that never ran again, so copying them would promote a stale snapshot to source of truth.

And `PositionHold` requires a `startDate` that a rooster regel never recorded: it held only the END of a term. Any start date this migration wrote would be invented. An invented date that looks authoritative is worse than an empty page.

The rows stay readable under their original schemas, as with every other step of this programme.

## Impact

Two menu entries and six pages go. `PositionHolds` arrives in their place, asking the same question of source data: who holds which position, and until when, sorted by whose term runs out next.

An instance that relied on the seeded rooster will see it empty until positions are recorded. That is honest: it was never anything else.
