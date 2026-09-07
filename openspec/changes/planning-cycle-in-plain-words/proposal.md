# planning-cycle-in-plain-words

**Status**: planned
**Scope**: decidiq

## Why

A cyclus is a cycle.

The planning and control cycle is not a Dutch invention. Every organisation that sets a budget, reports against it and closes a year runs one, and this schema already said so: its `context` enum covers association, corporate and operations alongside legislative.

The properties were already written in plain words. Only the schemas were not.

## What changes

`PCCyclus` becomes `PlanningCycle`, `CyclusTemplate` becomes `PlanningCycleTemplate`, `CyclusStap` becomes `PlanningCycleStep`.

One property is renamed with its schema: `CyclusStap.cyclus` becomes `PlanningCycleStep.cycle`. It was the one property that was itself Dutch.

`Consultation.cycleStep` is repointed, because it referenced the retired step.

## Decision: the copy follows its own references

A step references its cycle, and a cycle references its template. Copying them in any order would leave a record on the new schema pointing at the retired one it came from: readable, plausible, and joined to the wrong side of the migration.

So the migration copies in dependency order, and a reference to something copied in the same run follows it to its new identifier.

## Impact

Three Dutch schema names go. The routes follow: `/pc-cycli` becomes `/planning-cycles`, `/cyclus-stappen` becomes `/planning-cycle-steps`.

Nothing in PHP or Vue read these schemas, and no e2e named them, so the change is the schemas, the manifest and the seeds.
