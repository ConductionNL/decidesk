---
kind: code
---

# Proposal: approval-route-events

## Summary

Give the approval-route engine the same in-process command seam
`governance-body-events` gave governance bodies: typed events another fleet app
dispatches to hold a sign-off route, travel a subject down it, and record what
each actor did. The engine landed in `approval-routes`; today it is reachable
only over REST, which is the wrong door for an app-to-app command.

## Motivation

`approval-routes` shipped `ApprovalRoute`, `ApprovalAction`,
`ApprovalRouteService` and a controller. Its own proposal names the consumer it
was built for: dossiq's parafering, "implemented today in dossiq, where it
works", which "does not belong there".

That migration cannot start, for the reason `governance-body-events` already
documented and fixed once:

1. ADR-041 makes a cross-app **command** a typed event; gate-27
   (`no-phantom-cross-app-rpc`) enforces it.
2. `ApprovalRouteController` refuses a request with no signed-in user, and an
   in-process call to our own instance has none — which is the state a
   migration runs in.

Measured on 2026-08-30, dossiq's parafering is **not** the retired
implementation its schema description claims. `parafeerroute` is marked
`DEPRECATED (migrate-parafering-to-or-approval-workflow)`, and the archive
records that change as *"archived prematurely; implementation not present on
development"*. It is live in 15 PHP files, 7 frontend files, 4 routes and 4
test files, across roughly 1,810 lines of engine in six services. The banner
describes an intention; a reader would take it for a state.

## Affected Projects

- [x] Project: `decidiq` — this change. Two command events, a conclusion event,
  a command service, listeners.
- [ ] Project: `dossiq` — the consumer. It migrates its parafeerroutes onto
  these and retires its engine. It cannot start until this lands.

## Scope

### In Scope

1. **`ApprovalRoute` gains `sourceApp` and `externalReference`**, additively.
   The same provenance pair `GovernanceBody` carries, for the same reason: it
   is the key a repeated command resolves on, so a re-run of a consuming
   migration updates one route rather than minting a second.
2. **`ApprovalRouteRequestedEvent`** — hold this route. Carries the template
   (name, subjectType, isDefault, description, `steps[]`) and, optionally, a
   subject to instantiate it against in the same command, because a migration
   that moves a template almost always wants the in-flight subjects travelling
   it too. Result slots: `routeId`, `created`, `stageCount`, `handled`.
3. **`ApprovalActionRequestedEvent`** — record one actor's action against a
   subject already travelling a route. Result slots: `recorded`, `completed`
   (true when that action decided the last stage), `handled`.
4. **`ApprovalRouteConcludedEvent`** — emitted when an action decides the final
   stage, carrying the correlation home so the consumer can act on a finished
   sign-off without polling.
5. **`ApprovalRouteCommandService`** — the thin idempotent layer over the
   EXISTING `ApprovalRouteService`. It resolves the template and delegates;
   it does not re-implement instantiate/record/return.

### Out of Scope

- **Any change to `ApprovalRouteService`'s rules.** Which stage is active, what
  a return does, what is refused: all of that already exists and is tested.
  This change adds a door, not a second engine.
- **Any dossiq change.**
- **A UI.** `DecisionRouteTab` stays read-only; write affordances remain the
  follow-up `approval-routes` deferred.

## Risks

- 🔴 **A second engine is the failure this must not become.** The command
  service delegates to `ApprovalRouteService` for every rule. A test asserts
  that recording through the seam produces the same stage transitions as
  recording through the service, so a divergent second path fails rather than
  drifts.
- 🔴 **`instantiate()` is already idempotent by early-return** — it returns the
  existing stages when the subject has any. That is inherited deliberately, and
  pinned by a test that instantiates twice and counts stages, because a
  behaviour relied on across an app boundary should fail loudly if it changes.
- ⚠️ **A route matched by `(sourceApp, externalReference)` whose steps changed**
  is UPDATED, and subjects already travelling it keep the stages they were
  given. Stages are materialised at instantiation, so an edited template does
  not retroactively rewrite a sign-off in flight. That is the correct
  behaviour for an audit trail and is asserted, not assumed.
- ⚠️ **The listener runs inside the producer's request.** Bounded by the step
  count of one route.

## Status

Ready. No dependency outside this repo.
