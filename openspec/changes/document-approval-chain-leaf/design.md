# Design: document-approval-chain-leaf

Kind: code. A service method, two schema properties, a leaf.

## Architecture

`approval-routes` holds a subject (any `{register, schema, id}`) on an
`ApprovalRoute` template and records `ApprovalAction`s that advance
`DecisionStage`s. `approval-route-events` exposes hold and record as typed
events. This change adds nothing to that engine's rules. It adds one way in
(named users instead of a template), one derived field (`dueAt` per stage)
and one way out (the leaf).

## D1. Ad-hoc route from named users

`ApprovalRouteService::holdFor(subject, actors, deadline, kind)` where
`actors` is an ordered list of Nextcloud user ids and `kind` is `review` or
`approval`. It creates a transient `ApprovalRoute` with `origin: adhoc` and
one stage per actor, then reuses the existing instantiate path
(REQ-AR-004). `HoldApprovalRouteRequestedEvent` gains an optional `actors[]`
so a sibling can start one through the seam too.

## D2. Deadline split

`DecisionStage` gains `dueAt`. On hold with a `deadline`, the service divides
the working days between now and the deadline evenly over the stages, last
stage on the deadline. A stage's `dueAt` may be edited by the route holder;
the sum is not enforced. A stage past `dueAt` without an action is `overdue`
in the timeline, a computed view, not a state.

## D3. `decidiq-approval-chain`, kind render-surface

- PHP: a `LeafDescriptor` with id `decidiq-approval-chain`,
  `renderMode: component`, on `RegisterLeafProvidersEvent`.
- JS: `registerIntegration()` under the same id with `tab` (the timeline,
  every action, the reasons) and `widget` (current step, due date, and for
  the current actor the approve and reject buttons).
- Start: the widget offers "Start approval" to a user with write on the host
  object: pick users in order, one deadline, a kind. It calls
  `holdFor()` through decidiq's own controller.
- Act: approve or reject with a required reason calls the existing record
  action path (REQ-AR-005). A reject is a return to the previous step
  (REQ-AR-006) or a close, chosen by the actor.
- The consumer reads the outcome from the route objects through the leaf.
  No verb crosses into the consumer (ADR-066 decision 2).

## D4. `tab` on `decidesk-decisions`

The mount-mode leaf keeps `mount` and `unmount` and adds a `tab` label and
icon so a sidebar host names it correctly. Parity for mount mode is
`mount` plus `unmount` (ADR-066 decision 7); the `tab` is metadata.

## Risks

- Working-day arithmetic differs per instance. Use the instance's calendar
  setting; fall back to Monday to Friday.
- A route on a file that is later deleted. The route stays; the timeline
  shows the subject as gone.
