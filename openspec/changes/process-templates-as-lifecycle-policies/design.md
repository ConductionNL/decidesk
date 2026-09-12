# Design: process-templates-as-lifecycle-policies

Kind: code. One schema annotation reshaped, one OpenRegister guard, one
template data model with its repair step, one admin editor, and two classes
deleted.

## What OpenRegister already does

Measured against `ConductionNL/openregister` `development` at `4bd08be`, and
on the shared dev instance. Every claim below was read in the source or
observed on a live write, not inferred.

**The rules run on every object update.** `Application.php:2773` registers
`LifecycleValidationListener` on `ObjectUpdatingEvent`. `MagicMapper.php:6994`
dispatches that event for every update, and a stopped event becomes a
`HookStoppedException` before the row is written. `ObjectsController.php:2970`
answers that exception with HTTP 422 and the listener's structured error. The
object API, `saveObject()` from any app, and OpenRegister's own
`TransitionEngine` all reach the same listener.

Observed on :8080, as `admin`, against the live `decision` schema (id 978):

```
PUT .../objects/decidiq/decision/<id>  {"lifecycle":"enacted"}   (from draft)
HTTP 422
{"error":"No transition allows moving \"lifecycle\" from \"draft\" to \"enacted\".",
 "errors":{"code":"lifecycle-invalid-transition","field":"lifecycle",
           "from":"draft","attempted":"enacted", ...}}

PUT .../objects/decidiq/decision/<id>  {"lifecycle":"proposed"}  (from draft)
HTTP 200
```

So OpenRegister already enforces decidiq's transition graph on a raw API
write. What it does not enforce yet is anything decidiq layers on top.

**A `requires` guard runs in the same listener.** From
`LifecycleValidationListener.php:227`:

```php
$requires = ($spec['requires'] ?? null);
if (is_string($requires) === true && $requires !== '') {
    $userId = ($this->userSession->getUser()?->getUID() ?? '');
    $guard = $this->guardRegistry->resolve($requires);
    $result = $guard->check($newData, $action, $userId);
    if ($result->isAllowed() === false) {
        $this->reject(event: $event, error: [
            'code' => 'lifecycle-guard-denied', ...
```

Four facts follow, and the design depends on each.

1. The guard receives the **new** payload, the **action key** of the matched
   transition, and the session uid. It does not receive the old object. The
   new payload carries the object id: `ObjectEntity::getObject()` prepends
   `'id' => $this->uuid`.
2. The action is the **map key** of the matched transition. With today's
   unnamed list the guard would be handed `"0"` to `"12"`.
3. With no session user the uid is the empty string, not null. An `occ`
   command or a background job therefore reaches a guard as `''`.
4. `LifecycleGuardRegistry::resolve()` tries OpenRegister's container, then
   the Nextcloud server container (`LifecycleGuardRegistry.php:96`), and throws
   when neither resolves the tag. A fully qualified class name in another app
   resolves through the server container. dossiq ships exactly this:
   `"requires": "OCA\\Dossiq\\Lifecycle\\VoorstelSubmitGuard"`.

**An empty transition set switches enforcement off.**
`LifecycleValidationListener.php:162` returns early when `transitions` is
empty, treating the lifecycle as app-managed. Decidiq's is not empty, so this
does not apply, but it is why the map must stay populated.

## Decisions

### D1. One transition map, keyed by action

The Decision `x-openregister-lifecycle.transitions` becomes OpenRegister's
action-keyed map. The names are the ones decidiq's API already accepts, plus
two the list held without a name.

| Action | From | To |
|---|---|---|
| `propose` | draft | proposed |
| `deliberate` | proposed | deliberating |
| `openVoting` | deliberating | voting |
| `decide` | voting | decided |
| `decideWithoutVote` | deliberating | decided |
| `enact` | decided | enacted |
| `archive` | decided, enacted | archived |
| `withdraw` | draft, proposed, deliberating, voting, decided | withdrawn |

These thirteen edges are exactly today's thirteen. Every (from, to) pair
belongs to one action, so the listener's first-match lookup is unambiguous.
Every transition declares
`"requires": "OCA\\Decidiq\\Lifecycle\\DecisionPolicyGuard"`.

`decide` used to accept `deliberating` too. `DecisionLifecycleService` keeps
accepting `decide` from `deliberating` as an alias, so no API client breaks.
The write lands on the `decideWithoutVote` edge and the guard judges it as
that.

The base register file changes in place. A `register.d` fragment cannot do
this: `SettingsService::deepMergeConfig()` recurses by key when an overlay map
meets a base list, so a fragment would leave keys `0` to `12` next to the new
names. `lib/Settings/decidiq_mock_register.json` carries the same block and
changes with it.

### D2. One guard, evaluated on every write

`OCA\Decidiq\Lifecycle\DecisionPolicyGuard implements LifecycleGuardInterface`.
It is read-only, as the interface demands. For the matched action it:

1. **Resolves the context** from the payload: the linked meeting, the
   governance body, the body's template. These reads run as system
   (`_rbac: false, _multitenancy: false`). The guard judges policy, and a
   caller who may update the decision must not skip quorum because they cannot
   read the meeting.
2. **Resolves the policy**: the template's policies when the body has a
   usable template, else the domain default (D4).
3. **Refuses what the policy forbids**, in this order, first refusal wins:
   - `decideWithoutVote` when the policy does not allow deciding without a
     vote;
   - chair-only, when the action is chair-only and the caller is not the
     resolved chair (scope decided by Q2);
   - quorum, on `openVoting`, when the policy requires quorum and a meeting is
     linked;
   - `all_amendments_resolved`, when the action's policy lists it;
   - on entering `enacted`: outcome must be `adopted`, and appointment posts
     must pair with candidates;
   - on entering `decided`, `enacted` or `archived`: `outcome` and
     `decisionDate` must be present and valid.

Each refusal returns `GuardResult::deny()` with a message a clerk can act on.
OpenRegister surfaces it as `lifecycle-guard-denied`.

**Fail closed.** A meeting that is referenced but cannot be loaded, when
quorum is required, refuses. A chair-only action with no resolvable chair
refuses. An empty uid refuses a chair-only action. A template that fails to
load falls back to the domain default, which is never looser than a template
could have made it.

**The same computation, both paths.** Quorum asks
`VotingRoundOpener::checkQuorum()`, the computation round-open already
refuses on. Amendments ask the same "is any amendment still undecided" check
`AmendmentOrderService` runs before a motion's round. A transition refused on
one path is therefore refused on the other, and one allowed on one path is not
refused on the other by a second opinion. The decision path used to read the
materialised `meeting.quorumWith`; it now reads the live count the round path
uses.

### D3. Templates become policies on the map

On `process-template` and `decision-template`:

```jsonc
"transitionPolicies": {
  "type": "array",
  "description": "Per-action policy on the Decision lifecycle. ...",
  "items": {
    "type": "object",
    "required": ["action"],
    "properties": {
      "action":    { "type": "string" },
      "chairOnly": { "type": "boolean", "default": false },
      "guards": {
        "type": "array",
        "items": { "type": "string", "enum": ["all_amendments_resolved"] }
      }
    }
  }
}
```

`quorumRequired`, `allowDecideWithoutVote`, `votingRule`, `quorumRule` and
`urgencyPolicy` keep their meaning. `initialState` and `stateMachine` leave
`required[]` and are marked deprecated (D6).

`action` has no enum on purpose. Its vocabulary is the Decision lifecycle's,
and copying it into a second schema is the duplication this change removes.
`ProcessTemplateService` refuses a template naming an action the live Decision
annotation does not declare (HTTP 400). A raw write that slips an unknown
action past it changes nothing: the guard only looks up actions that exist.

**The guard vocabulary.** The research asked each of the four tokens one
question: is there data that decides it?

| Token | Decided by | Outcome |
|---|---|---|
| `chair_only` | the meeting chair | folds into `chairOnly`, which it always duplicated |
| `quorum_met` | the meeting's live attendance | removed as a token; `quorumRequired` is the switch that has always been enforced, on `openVoting` |
| `all_amendments_resolved` | every Decision whose `amends` names this one is `decided`, `enacted`, `archived` or `withdrawn` | **kept and enforced** |
| `legal_review_complete` | nothing: no schema records a legal review | **removed**; the `decision-template` checklist is where such a step belongs |

`all_amendments_resolved` needs no new data. Amendments are Decisions with
`amends` pointing at their motion, and `AmendmentOrderService` already refuses
a motion's round while one is undecided.

### D4. Domain defaults in the same shape

The domain policies leave `DecisionTransitionGuard::DOMAIN_POLICIES` and are
restated per action, so a template and a default are one shape and one
resolver.

| Domain | Quorum before voting | Chair-only | Decide without a vote |
|---|---|---|---|
| legislative | yes | `openVoting`, `decide` | no |
| association | yes | `openVoting` | no |
| corporate | yes | `openVoting` | no |
| operations | no | none | yes |
| citizen | no | none | yes |
| unknown (default-deny) | yes | `openVoting`, `decide` | no |

Which domain a decision falls under is Q1.

### D5. `DecisionTransitionGuard` goes

It holds the second map, the domain policies and the outcome rules. Each part
gets one home:

- the transition map: the Decision annotation (D1), read at runtime through
  OpenRegister's `SchemaMapper`, as `ActionItemWriter` already does;
- the domain policies: the policy resolver (D4);
- the outcome and completeness rules: a small pure class the guard and
  `MotionLifecycleTransitioner` both call, so the motion path keeps its
  current messages.

`DecisionLifecycleService` keeps what only it does: the hash-chained audit
entry, the resolution record on enactment, appointment memberships and
`DecisionConcludedEvent`. It stops enforcing. It writes the lifecycle through
`saveObject()` and lets OpenRegister's listener run the guard. A
`HookStoppedException` becomes a failed transition carrying the guard's
message. Available actions come from the annotation, minus what the policy
forbids, with chair-only marked, as today. Pre-checking a rule the guard will
check anyway would make two opinions of one rule. The service only
pre-validates the `from` state, to name the allowed actions in its error, and
it reads those from the annotation.

`DecisionTransitionMatrixTest`, which pinned the two maps together, is
retired. A new test pins the annotation's action names to the domain policy
names and the editor's labels.

### D6. The repair step

`lib/Repair/ConvertTemplateGraphsToPolicies.php`, registered after
`MigrateLegacyTemplatesToDecisionTemplate` so rows that step copies are
converted too. It runs over `process-template` and `decision-template` as
system (`occ` has no session).

For each row with a `stateMachine`:

1. Map every legacy transition carrying `chairOnly: true` or any guard token
   to the Decision action whose `from` contains its `from` and whose `to`
   equals its `to`.
2. Merge into that action's policy: `chairOnly` is the OR of all
   contributions; `guards` is the union, kept to the vocabulary.
3. `chair_only` sets `chairOnly`. `quorum_met` and `legal_review_complete`
   are dropped. `all_amendments_resolved` is kept.
4. An edge with no Decision action (a state the Decision schema does not have)
   is dropped. It never had an effect: the only reader matched chair-only
   edges against real Decision transitions, which such an edge can never be.
5. An absent `quorumRequired` becomes `true`, which is what the resolver
   assumed when it was absent. `allowDecideWithoutVote` is left as stored.
6. The row is written without `stateMachine` and `initialState`.

Every dropped token and edge, and every chair-only edge that widens to a
multi-source action (`decided → archived` inside `archive`), is logged as a
warning naming the template. Widening only ever refuses more.

**Nothing is lost.** OpenRegister's audit trail records a removed key as
`{"old": <value>, "new": null}` on update (`AuditTrailMapper`, the
`action === 'update'` branch), so the full graph stays readable per row.

**Idempotent.** A row without `stateMachine` is skipped. Running the step
twice changes nothing, and a unit test runs it twice.

**Why the legacy properties stay declared for now.** The step reads
`stateMachine` from rows through `ObjectService`, and OpenRegister stores and
returns only declared properties. Dropping the declaration in the same
release could hide the graph from the step that converts it. The declarations
stay, marked deprecated and read by nothing but this step. A follow-up change
removes them once the step has shipped.

What the seeds and fixtures hold today, measured:

| Source | Templates | Chair-only edges | Tokens |
|---|---|---|---|
| `lib/Settings/profiles/*.json` (as `decision-template`) | 9 | all on `deliberating → voting`; council also `voting → decided` | `quorum_met` on every chair-only open-voting edge; council adds `all_amendments_resolved` |
| `tests/e2e/ci-seed.sh` | 1 (Municipal Council) | as council | as council |
| live :8080 `process-template` | 8 | as the seeds | as the seeds |

Every edge in all of them maps to an action. No `legal_review_complete`
appears anywhere. The conversion loses nothing that any known row carries.

### D7. What the admin sees

The template modal loses its graph editor and gains a policy editor,
`TransitionPolicyEditor.vue`. It lists the Decision lifecycle's actions in
lifecycle order, each with its from and to, and two checkboxes per action:
"Only the chair" and "All amendments decided first". Above the list sit the
two template-wide switches: "Quorum required before voting" (already there)
and "Allow deciding without a vote" (stored today, never editable).

There is nothing left to validate client-side. The editor cannot express an
unknown action or token, so `processTemplateGraph.js`, the error list and the
`POST /api/process-templates/validate` route go.

The governance body template tab (`GovernanceBodyTemplateTab.vue`) offers four
hardcoded ids (`standard-decision`, `statute-amendment`, `board-election`,
`urgent-decision`) that match no template. `ProcessTemplateService` resolves
`processTemplate` by slug, then UUID, finds nothing, and falls back to the
domain policy. So no body can be given a real template through the UI today.
The tab lists the real templates from the template store instead. Without
this, "the template takes effect" is true only through the API.

The action list reaches the frontend from the live annotation, through the
existing admin-gated template controller. No new place states it.

### D8. ADR-022 and ADR-031

- The Decision state machine is declared once, as schema metadata
  (`x-openregister-lifecycle`), and executed by OpenRegister's runtime
  (ADR-022 "Workflow engine", ADR-031).
- Per-body variation lives in data (template policies) and reaches
  OpenRegister through its documented seam for rules a schema cannot state
  statically: a `requires` guard implementing `LifecycleGuardInterface`.
  ADR-031 keeps PHP guards as a legitimate seam; dossiq's
  `migrate-status-engine-to-or-lifecycle` is the precedent.
- No app-local map remains. `DecisionTransitionGuard`, `StateMachineValidator`
  and the template graph go; nothing takes their place under another name.
- `MotionLifecycleTransitioner` still narrows the map per decision type. It
  can only forbid an edge the map allows, never add one, and a test holds it
  to that. It is listed as a follow-up, not hidden.

Gate 23 rule 5 matches `*StateMachine*.php`, `*StatusTransition*Service.php`
and `*WorkflowEngine*.php` under `lib/`. After this change none exist, and
none was renamed to get there.

## Open questions

These two change who may do what. Each has a recommendation; neither is
decided here.

### Q1. Which domain policy applies to a decision?

`DecisionContextResolver::resolveDomain()` reads `decision.domain`, then
`meeting.domain`, else `operations`. Neither schema declares `domain`, so
OpenRegister never stores it and every decision resolves to `operations`: no
quorum, no chair-only, deciding without a vote allowed. `GovernanceBody.domain`
is declared and holds the real value. Nobody reads it for this.

- **Q1-a. Read the body's domain.** The domain policies start to apply.
  Legislative, association and corporate bodies get quorum before voting and a
  chair-only open vote. This is what the code has always claimed. Every such
  body notices.
- **Q1-b. Keep today's effective behaviour.** Name `operations` as the
  default outright, and let templates carry any stricter policy. Nothing
  changes for users; the domain table becomes documentation of what a template
  can pick.

*Recommendation:* Q1-a, as its own small change after this one, so the
behaviour change ships visibly and on its own.

### Q2. Where does chair-only bind?

Opening and closing a voting round is allowed for the chair or the secretary
(`VotingController`, `requireChairOrSecretary`). Both paths write the
decision's lifecycle (`deliberating → voting` on open, `voting → decided` on
close). The template and the domain policy mark exactly those two actions
chair-only. Today that is enforced only on decidiq's decision endpoint, so a
secretary's round moves the decision regardless.

- **Q2-a. On every write.** The guard enforces chair-only for every writer.
  The round endpoints check the same policy up front, so a secretary is refused
  before a round is opened, not after. In a chair-only body only the chair can
  open or close the vote. Consistent with the rule REQ-RBAC-003 sets for
  meetings: chair-only meeting transitions are enforced by OpenRegister, for
  every writer.
- **Q2-b. On decidiq's decision endpoint only.** Chair-only stays where it is
  today. The guard enforces everything else. The raw API bypass the register
  documents stays open for chair-only.
- **Q2-c. Chair or secretary.** The guard accepts either, matching the round
  endpoints. This loosens the decision endpoint, where only the chair may act
  today.

*Recommendation:* Q2-a. It is the only option where "Only the chair" on the
screen is true on every path. Its cost falls on councils where the griffier
runs the vote on the chair's behalf, and that is a call for the product owner.

Under today's behaviour (Q1-b, and no template assignable through the UI) no
body is affected by either question yet. D7 makes templates assignable, so
the answers matter from the release that ships it.

## Delivery

Four PRs, each green on its own.

1. **This spec.**
2. **The map and the policies.** D1 without `requires`, D3, D6, D7, and the
   deletion of `StateMachineValidator`. `DecisionTransitionGuard` reads
   policies through the new resolver for one PR. Gate 23 is clean after this
   one, and nothing in it depends on Q1 or Q2.
3. **The guard.** `requires` on every transition, D2, D4, D5, with Q1 and Q2
   answered. `DecisionTransitionGuard` is deleted.
4. **Follow-ups.** Remove the deprecated declarations (D6); fold the motion
   narrowing into the guard (D8).

## Test plan

**Unit.** One test per guard rule, allow and deny, with the account in every
message. Mutation-checked: break the rule, watch the named assertion redden,
restore, confirm the file is byte-identical. Policy resolution: template wins,
malformed template falls back, unknown domain is default-deny. The repair
step: each mapping row above, each dropped token, widening, absent
`quorumRequired`, and a second run that changes nothing. A parity test: the
annotation's actions against the domain policies and the editor's labels.

**E2E**, following `tests/e2e/workflows/decision-write-authorization.spec.ts`:
run-unique accounts created over OCS, `OCS-APIRequest: true`, `node:crypto`
passwords, cleanup in `afterEach`, the fixture owned by `admin` so ownership
grants nothing, and the acting account named in every assertion.

- (a) As a non-superuser in `decidiq-administrators`, `PUT` a decision from
  `deliberating` to `decided` through OpenRegister's object API, in a body
  whose template does not allow deciding without a vote. Expect 422 with
  `errors.code = lifecycle-guard-denied`, and the stored lifecycle still
  `deliberating`. Asserting the code, not just the status, is what separates
  this guard from the graph check.
- (b) The same account moves a decision `deliberating → voting` in a body
  without quorum. Expect 200 and `voting` stored.
- (c) An admin opens the template in the admin settings, ticks "Allow
  deciding without a vote" and saves. The (a) write by the same
  non-superuser now succeeds.

**Gates.** Gate 23 in forced block mode
(`HYDRA_OR_GATE_BLOCK_AFTER_EPOCH=0`) exits 0 with 0 findings before each PR.

## Risks

- **OpenRegister fails closed on the guard.** If the guard class cannot be
  built, every Decision lifecycle change is refused. The E2E suite performs
  transitions in several specs and would go red on the first.
- **A read inside the guard is a read inside every write.** The guard loads
  a meeting, a body and a template per lifecycle change, and amendments on the
  actions that list them. Only a lifecycle change pays; a title edit leaves
  the field unchanged and the listener returns before any guard runs.
- **Round paths change order of failure.** `VotingRoundOpener` saves the round
  before the lifecycle write and swallows a refused write as a warning. Under
  Q2-a the round endpoint refuses first, so no round is left open over a
  decision that did not move.
