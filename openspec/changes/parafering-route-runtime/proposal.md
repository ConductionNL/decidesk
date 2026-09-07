---
kind: code
---

# Proposal: parafering-route-runtime

## Summary

The parafering runtime moves from dossiq into this app's approval-route
engine, completely. dossiq raises a route, waits for the conclusion event and
keeps only case-data records — the same posture it already holds for
decisions. This change absorbs the four capabilities dossiq's pipeline still
owned that the engine lacked, and closes the two seams that made full
delegation impossible.

## Motivation

`parafering-to-decidiq` (dossiq) moved the route TEMPLATE here and deferred
its Task 5 with a precise reason: *"dossiq's pipeline owns a status
vocabulary, a return notification, accordering effects and mandate validation
that the decision app's engine does not; replacing it means reproducing all
four or losing them."* That sentence is this change's work order. The ruling
that triggers it now: the runtime moves completely, dossiq delegates
parafering exactly like it delegates decisions.

## Affected Projects

- [x] Project: `decidiq` — this change.
- [x] Project: `dossiq` — the counterpart (`parafering-runtime-to-decidiq`):
  the raise fails closed on this engine, a concluded-route listener records
  the case data, and the local runtime retires. **Merge order: this change
  first.** dossiq's retirement leaves it nothing to advance a route with, so
  it breaks without this engine already absorbing.

## Scope

### What the engine absorbs

1. **A stage-typed action vocabulary.** An advisory stage completes with
   `advised` (and requires the advice text), an endorsement stage with
   `endorsed`, a decisive stage with `approved`; a return requires its
   reason. Absorbed from dossiq's `ParafeerStepGuard`, where an accordering
   step accepted only `accorded`. Stage types outside the sign-off trio
   (preparatory, ratifying) keep the engine's original permissive behaviour —
   no producer constrains them yet.
2. **Mandated delegate signing, with a real registry check.** dossiq's guard
   accepted any non-empty mandate string and left the registry to "the future
   MandaatService". The engine now refuses a delegate whose `onBehalfOf` is
   not the stage's person or whose mandate is empty, and judges a mandate
   reference that resolves in the local `bevoegdheidstoedeling` register:
   not-effective, out-of-window or issued-to-somebody-else refuses. A
   reference that resolves to nothing is an EXTERNAL mandate (dossiq's
   mandateringsbesluit rows live in dossiq's register, unreachable per
   ADR-022) and travels verbatim, exactly as the approval-action schema
   always recorded it.

   **REQ-DMR-006 boundary, argued rather than skipped:** delegatie-
   mandaatregister forbids gating any *Decision* creation, transition or
   enactment on a toedeling. An approval-route stage action is a sign-off by
   a named person, not a Decision lifecycle transition; verifying that a
   delegate holds the mandate they wave is the one thing a sign-off chain
   exists to do. The prohibition and this check do not overlap.
3. **Terugsturen: a return that concludes.** dossiq's return never re-opened
   an earlier step — it sent the voorstel back to the steller and asked
   nobody else. A `returned` action naming no `returnToStep` now concludes
   the route with outcome `returned`; naming one keeps the existing rewind.
   Never-reached stages go back to `pending`, not `skipped` — nobody chose
   to skip them.
4. **Parallel co-signing.** Steps declaring the same `order` become stages
   sharing that sequence, active together; the group advances when its last
   live member completes. A stage's sequence is now the step's OWN order (a
   route numbered 10/20 projects 10/20), which is what dossiq's parafering
   surfaces have always read.

### The two closed seams

5. **A conclusion from every path.** `ApprovalRouteConcludedEvent` fired only
   from the cross-app action listener; a route concluded over this app's own
   REST surface, or from the task inbox, announced nothing — and a producer
   that delegated its runtime here would wait forever.
   `ApprovalRouteConclusionAnnouncer` is now the one door: it resolves the
   provenance pair from the route the stages back-reference (new additive
   `DecisionStage.route`), and every concluding path calls it. The event
   gains defaulted trailing parameters — `subjectSchema`,
   `externalReference`, and `actions`, the full chronological sign-off
   record — so the producer can keep who-signed-what-when (actor,
   onBehalfOf, mandate, comment, advice) as case data without reading this
   register back. An internal route (no sourceApp) still announces nothing,
   the precedent decisions set.
6. **The ask rides the task surface.** `ApprovalStageTaskProjector` mirrors
   every active person-assigned stage onto an OpenRegister flow-task
   (person-assigned, `templateId: decidiq:approval-stage`, the stage id as
   provenance), so the sign-off lands in the work queue the approver already
   reads; `ApprovalTaskDecisionListener` turns an answered projected task
   back into an engine action — same actor rules, same vocabulary, same
   refusals. Both are best-effort and container-resolved: an instance whose
   OpenRegister predates the task surface loses visibility, never
   correctness.

### Why the stages stay the engine (and TaskSequence does not replace them)

OpenRegister's `TaskSequenceService` shipped this week and was the candidate
to replace the `DecisionStage` rows outright. It is deliberately ordinal — no
parallelism, no return primitive, every position offered to a GROUP
(`single-role`) — and OR's own design note says an approval needing more than
a straight line belongs to a richer driver. A parafering route needs exactly
the three things it excludes: per-person assignment, parallel co-signing and
terugsturen, plus a per-action mandate record its `candidateGroups` model has
nowhere to put — the "loss of record dressed as an engine change" dossiq's
own flow-projection work already diagnosed once. So the ride is at the task
LAYER of that surface: real OR flow-tasks carry every ask, the sequence rows
do not replace the stages. If TaskSequence ever grows person positions,
parallel groups and returns, the projector is the seam to swap.

## Risks

- 🔴 **A vanished guard is an open door.** Every absorbed refusal — the
  vocabulary map, the delegate structural check, the registry judgement, the
  terminal-return conclusion, the parallel-group hold — is mutation-checked:
  removing each one turns a named test red (`ParaferingRouteRuntimeTest`).
- 🔴 **The projector's consume must not echo into the engine.** The task
  listener acts only on `state: completed` tasks carrying the projector's
  template marker; a consumed/cancelled/terminated task decided nothing and
  is ignored. Pinned by test.
- ⚠️ **Existing seeded stages carry no `route`.** They announce no cross-app
  conclusion (provenance unresolvable ⇒ treated as internal), which is
  correct: no producer raised them.
