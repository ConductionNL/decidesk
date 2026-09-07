---
kind: code
---

# Proposal: governance-body-events

## Summary

Give decidiq an in-process command seam for governance bodies: a typed
`GovernanceBodyRequestedEvent` another fleet app dispatches to ask decidiq to
raise a `GovernanceBody` with its roster, and a `GovernanceBodyCreatedEvent`
carrying the correlation and the resulting id back. This is the door dossiq's
committee migration needs and the one thing decidiq#874 did not ship.

## Motivation

decidiq#874 made `GovernanceBody` capable of holding a Dutch
bezwaaradviescommissie: it added `active`, numeric `quorum`, `jurisdiction`,
`statutoryBasis`, `Membership.external`, and a cross-app write path on
`ApiController`. dossiq's `migrate-committees-to-decidiq` is blocked anyway,
and its proposal says why: that write path is REST, and REST is the wrong door
for an in-process app-to-app command.

Two reasons, both load-bearing:

1. **ADR-041 says commands travel as typed events.** ADR-066 amended it for
   collection only; the command rule stands, and gate-27
   (`no-phantom-cross-app-rpc`) enforces it.
2. **An in-process HTTP call to our own instance has no session to
   authenticate with.** `ApiController::write()` refuses when
   `userSession->getUser()` is null, which is exactly the state a background
   migration runs in.

The pattern already exists in this app and is the one being copied:
`DecisionRequestedEvent` → `DecisionRequestedListener` →
`DecisionIntegrationService::createDecision()`, with the resolved id written
back onto the event instance and `DecisionConcludedEvent` carrying the
correlation home.

## Affected Projects

- [x] Project: `decidiq` — this change. Two events, a command service, a
  listener, an idempotency key on `GovernanceBody`.
- [ ] Project: `dossiq` — the consumer. `migrate-committees-to-decidiq`
  unblocks on this and is not part of it.

## Scope

### In Scope

1. **`GovernanceBody` gains `sourceApp` and `externalReference`**, additively,
   in a register.d fragment. This is the idempotency key and the audit trail of
   where a body came from. `Decision` already carries exactly this pair for
   exactly this reason; a governance body raised by another app needs it for
   the same one.
2. **`GovernanceBodyRequestedEvent`** — the inbound command. Body fields plus a
   `members[]` roster of `{uid, role, external}`, a `sourceApp`, an
   `externalReference`, a `correlationId`, and an `actorId`. Result slots for
   `governanceBodyId`, `created` and `handled`, written by the listener and read
   by the producer right after dispatch.
3. **`GovernanceBodyCommandService`** — the engine:
   - `upsert()` resolves an existing body by `(sourceApp, externalReference)`
     BEFORE writing anything, so a re-run updates rather than mints a second.
   - The roster fans out to `Person` + `Membership`. A `Person` is resolved by
     `nextcloudUserId` (which `67-model-debt-cleanup` already added) and created
     only when absent.
   - A `Membership` is resolved by `(person, governanceBody)` so a re-run
     updates the role rather than adding a second seat.
   - **The body is written before the memberships**, so a crash between them
     leaves a body with a partial roster that the next run completes, rather
     than orphan memberships pointing at nothing.
4. **`GovernanceBodyRequestedListener`** — maps the event onto the service,
   writes the result slots, dispatches `GovernanceBodyCreatedEvent`, and never
   lets an exception escape into the dispatcher.
5. **Registration** in `DomainServiceRegistrar`, beside `registerDecisionEvents`.

### Out of Scope

- **Any dossiq change.** The migration, the read path and the retirement of
  dossiq's local schema are dossiq's.
- **A UI.** Bodies raised this way are ordinary `GovernanceBody` rows and appear
  in the existing list and detail pages with no new surface.
- **Deleting a body.** The seam creates and updates; retirement is `active:
  false` through the normal surface.

## Risks

- 🔴 **A fan-out migration is not idempotent by default.** This is the risk the
  consuming proposal names, and it is answered here rather than there:
  resolution by `(sourceApp, externalReference)` for the body and by
  `nextcloudUserId` for the person are what make a re-run safe. Both are
  asserted by tests that call the seam TWICE and count rows, because a test
  that calls it once cannot tell an idempotent write from a duplicating one.
- 🔴 **`active` is load-bearing on the consumer side.** dossiq's
  `AdvisoryCommitteeService` throws "Committee is archived" on it. The event
  carries it explicitly and the service never defaults it silently: an absent
  `active` is an error, not a `true`.
- ⚠️ **A `Person` matched by `nextcloudUserId` may be a different human with a
  recycled uid.** Accepted: NC uids are not reissued in practice, and the
  alternative (matching on name) is worse.
- ⚠️ **The listener runs synchronously inside the producer's request.** A slow
  fan-out is the producer's latency. Bounded by the roster size, which for an
  Awb 7:13 committee is single digits.

## Status

Ready. No dependency outside this repo.
