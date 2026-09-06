# A consumer can read a decision back

## Why

Decidiq can be asked to raise a Decision, and it announces the conclusion. It
cannot be ASKED what became of one.

That gap is not theoretical. dossiq#1756 taught `dossiq.askPerson` to re-read
its task on every re-entry, so a completion whose signal was refused or lost is
delivered by the next heartbeat instead of wedging the run forever. Its sibling
node `dossiq.requestDecision` has the identical defect and could not be fixed
with it, and the report said exactly why:

> `ContractDecisionDelegationService` can raise a decision and cannot read one
> back, so a heartbeat has nothing to consult.

A run parked on a decision whose `DecisionConcludedEvent` never arrived — the
consumer was mid-upgrade, its listener threw, the run had already been resumed
by something else — has no way to discover that the decision was made hours ago.
It re-suspends on a timer, indefinitely, while the answer sits in decidiq.

## What this is NOT

**It is not a second delivery mechanism.** `DecisionConcludedEvent` remains how
a conclusion reaches a consumer, and nothing about that path changes. This is
what a consumer consults when that announcement did not arrive. Building a poll
alongside the announcement would give one Decision two paths that can disagree;
making the state readable gives it one path and one fallback.

**It is not a second state machine, and not a second authorization rule.** Both
of those already exist and are already correct:

- `DecisionIntegrationService::getOutcomeEnvelope()` derives the status
  (`pending` / `approved` / `rejected` / `withdrawn`) that
  `DecisionConcludedEvent` already carries. The seam returns that envelope
  verbatim, so the announced answer and the read-back answer are the same array.
- `DecisionIntegrationAuthorizationGuard::isAuthorizedToReadOutcome()` is the
  REQ-DCDH-101 rule `GET /api/v1/decisions/{id}/outcome` enforces: the identity
  that raised the Decision (`@self.owner`), or anybody when it is published.
  The seam consults it rather than restating it.

## What changes

- **`DecisionStateRequestedEvent`** — a public cross-app event, in the same
  synchronous request/response-over-the-bus shape `DecisionRequestedEvent`
  already uses, so a consumer that can raise a decision reads one back without
  learning a second mechanism. It carries `sourceApp`, `decisionId` and
  `actorId`, and answers in four slots: `handled`, `permitted`, `found` and the
  `envelope`.
- **`DecisionStateRequestedListener`** — resolves the read through the existing
  guard and the existing envelope derivation, and registers as one more line in
  `CrossAppEventRegistrar::COMMANDS`.
- **`DecisionIntegrationAuthorizationGuard::resolveOutcomeReadAccess()`** — the
  same rule, with one more answer. See below.

## Authorization, and why the guard gained a third answer

The bus carries no session. An HTTP caller is whoever Nextcloud authenticated;
the recovery heartbeat that motivates this seam runs under the cron worker,
where `IUserSession` holds nobody. So the read is scoped to the uid the event
NAMES, and an event that names none is refused rather than read as a system
caller — the one place a nameless caller could plausibly be elevated is exactly
the place it must not be. There is no admin bypass on this path either: the one
`IntegrationController` has belongs to a real authenticated administrator, and
there is nobody here to check that against.

Naming an actor is not a claim about who is calling — in process there is no
boundary that would make it one, and any app that can dispatch this event can
already reach `ObjectService` directly. It is the identity the read is SCOPED
to, and that is what makes it useful: `@self.owner` is stamped from the identity
that raised the Decision, so a consumer naming any other uid is told "not
permitted". dossiq calls it as the flow run's own acting identity — the same
identity that raised the decision — which is precisely the scoping that keeps
one case's run from reading another's decisions.

The guard's boolean folds "I could not resolve this Decision" onto "no". For an
HTTP caller that is right: a request either proceeds or gets a 403, and failing
closed is the only safe collapse. It is wrong for a caller deciding whether to
come back, because a transient OpenRegister outage would fail a waiting run on
an authorization error it never had. So `resolveOutcomeReadAccess()` reports
`allowed` / `denied` / `unresolved`, and `isAuthorizedToReadOutcome()` now
delegates to it — the HTTP behaviour is byte-for-byte what it was, and the two
paths cannot drift into disagreeing about who may read.

The seam mirrors the endpoint's existence semantics too, deliberately: a
Decision that genuinely does not exist passes the guard so the answer is "not
found" rather than "not permitted", exactly as the endpoint answers 404 rather
than turning a 403 into an existence oracle.

## Testing posture

The seam's test drives the REAL listener over the REAL guard and the REAL
envelope derivation, with only OpenRegister faked — and the fake refuses what
live OpenRegister refuses. decidiq#1107 is the measurement behind that: five
store fakes accepted a top-level `id` filter that live OpenRegister matches
nothing for, and three production call sites shipped dead behind them, including
the announcer that was supposed to tell dossiq a route had concluded. So the
fake resolves a Decision only by uuid, only under the register and schema
production names, and returns no rows for a top-level id filter.

Verified negatively: replacing the guard consultation with an unconditional
`allowed` reds the refusal test and the unreachable-store test.
