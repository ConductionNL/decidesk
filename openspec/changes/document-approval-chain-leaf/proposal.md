---
kind: code
---

# Proposal: document-approval-chain-leaf

## Summary

Render an approval route on a document as a leaf. A clerk names the users in
order, sets one deadline, and the route splits it over the steps. Each actor
approves or rejects with a reason. dossiq places the leaf on its Documents
tab and reads the outcome; it holds no engine of its own.

## Motivation

OpenCase runs a review or approval chain on a document
(`oc/pages/DocumentDetail-Workflow.md`, dossiq competitor analysis,
`concurrentie-analyse/procest/_round2/`). dossiq retired its own parafering
and delegates sign-off to decidiq, but its `caseTask` carries one assignee and
one deadline, not an ordered chain on a file (finding B23, M1 3.13).

decidiq already owns the engine: `approval-routes` (ApprovalRoute template,
append-only ApprovalAction, DecisionStage sign-off vocabulary, return path)
and `approval-route-events` (typed commands to hold a route and record an
action). What is missing is the surface a sibling can place on an object, and
the two things OpenCase does that the engine does not: named users chosen at
start, and a deadline split over the steps.

Triage #8 of the same analysis: the `decidesk-decisions` leaf registers in
mount mode without a `tab`, so dossiq's sidebar falls back to copy naming
decidesk. This change ships the tab.

## Affected projects

- [ ] Project: `decidiq`: an ad-hoc route from named users, per-step due dates
  derived from one deadline, a `render-surface` leaf `decidiq-approval-chain`
  with tab and widget, and a `tab` on `decidesk-decisions`.

dossiq changes nothing but its manifest (place the leaf). The engine and the
event seam are reused as they are.

## Scope

### In scope

1. An ad-hoc route: `ApprovalRouteService::holdFor(subject, actors[],
   deadline)` builds a route from named users without a stored template.
2. Deadline split: `dueAt` per stage, the deadline divided evenly over the
   steps in working days, adjustable per stage.
3. The leaf, ADR-066 render-surface: the route timeline on a document or
   any object, and approve or reject with a reason for the current actor.
   The actions run in decidiq's own service; nothing calls the consumer.
4. A `tab` on the `decidesk-decisions` leaf next to its mount surface.

### Out of scope

- Signing (`eidas` stays where it is).
- The route on a meeting agenda (`routedDocumentsJoin.js`, a different sense
  of routed).
