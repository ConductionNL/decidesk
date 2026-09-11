---
kind: code
---

# Proposal: process-templates-as-lifecycle-policies

## Summary

A process template stops drawing its own state machine. The Decision
lifecycle has one map, and OpenRegister owns it: the `x-openregister-lifecycle`
block on the `decision` schema. A template becomes a policy on that map. Per
action it says who may take the step (chair only) and what must be true first
(all amendments decided). Template-wide it says whether quorum applies before
voting and whether a body may decide without a vote.

The rules move to where every write passes. Today they run only when a
decision moves through decidiq's own endpoint. A write straight to
OpenRegister's object API skips them, and the register says so in as many
words. After this change one OpenRegister lifecycle guard evaluates them on
every write, whoever makes it.

`StateMachineValidator` is deleted, not renamed. It validated template graphs,
and templates no longer have graphs.

## Motivation

Hydra gate 23 (`or-abstraction`, rule 5 `consume-or-workflow-engine-fleet-wide`)
flags `lib/Service/StateMachineValidator.php` and turns blocking on
2026-10-03 00:00 UTC. Renaming the file would clear the gate and change
nothing, so it is not on the table. The finding is right: decidiq carries an
app-local state machine for a Decision lifecycle OpenRegister already runs.

Reading the code behind the finding turned up four more defects of the same
kind. Each is a claim the code does not keep.

1. **Two transition maps.** `DecisionTransitionGuard::TRANSITIONS` and the
   Decision schema's `x-openregister-lifecycle` both declare the graph. A unit
   test pins them together. Nothing makes one derive from the other.
2. **Template graphs are decorative.** `ProcessTemplatePolicyResolver` reads
   two flags and the chair-only edges of a template. It never reads the
   template's states, its other transitions or its initial state. An admin can
   draw any graph; nothing walks it.
3. **Guard tokens that guard nothing.** The template schema describes
   `guards` as "Guard tokens enforced before this transition". Three of the
   four accepted tokens (`quorum_met`, `all_amendments_resolved`,
   `legal_review_complete`) are validated and then enforced nowhere.
4. **The rules bypass.** Chair-only, quorum, decide-without-vote, the adopted
   outcome before enactment and terminal completeness all live in
   `DecisionLifecycleService`. The register's own note on the Decision schema:
   "KNOWN GAP: a raw write straight to OpenRegister's object API bypasses that
   gate, exactly as it already bypasses the chair-only and quorum gates."

## Affected projects

- [ ] Project: `decidiq`. Decision lifecycle annotation, one lifecycle guard,
  the template policy model and its repair step, the admin template editor,
  the governance body template picker, deletion of `StateMachineValidator`
  and `DecisionTransitionGuard`.

OpenRegister changes nothing. Every mechanism used here already ships:
`x-openregister-lifecycle`, `requires`, `LifecycleGuardInterface`,
`LifecycleGuardRegistry` and `LifecycleValidationListener`.

## Scope

### In scope

1. **One map.** Reshape the Decision `x-openregister-lifecycle.transitions`
   from an unnamed list into OpenRegister's action-keyed map, so actions have
   names. Every transition names the decidiq lifecycle guard in `requires`.
2. **One guard.** `DecisionPolicyGuard` implements OpenRegister's
   `LifecycleGuardInterface`. It resolves the body's policy (template, else
   the domain default) and refuses what that policy forbids.
3. **Templates become policies.** `transitionPolicies[]` replaces
   `initialState` and `stateMachine` on `process-template` and, for parity,
   on `decision-template`. The guard vocabulary becomes a declared
   `items.enum`.
4. **Honest guard vocabulary.** `all_amendments_resolved` is enforced for
   real. `legal_review_complete` is removed: no data behind it. `quorum_met`
   is removed as a token: the template's `quorumRequired` flag is the switch
   that is actually enforced, and one switch is enough. `chair_only` folds
   into the `chairOnly` flag it always duplicated.
5. **Repair.** An idempotent repair step converts every stored template graph
   into policies, reports what it drops by template name, and is safe to run
   twice. Seeds and the E2E fixture move to the new shape.
6. **Admin UI.** `StateMachineEditor.vue` gives way to a policy editor that
   lists the lifecycle's own actions. The governance body template picker
   lists real templates instead of four hardcoded ids that match none.
7. **Deletions.** `StateMachineValidator`, the `validate` endpoint,
   `processTemplateGraph.js`, `DecisionTransitionGuard` and
   `ProcessTemplatePolicyResolver`'s graph reading.

### Out of scope

- Repointing template consumers from `process-template` to
  `decision-template`. That is `unified-decision-templates-consumer-rewrite`.
  This change gives both schemas the same policy shape, so that rewrite
  inherits policies and cannot bring a graph back.
- The motion and amendment narrowing in `MotionLifecycleTransitioner`
  (motions never decide without a vote, amendments are never enacted). It is a
  strict subset of the map, pinned by a test. Folding it into the guard is a
  follow-up.
- Removing the legacy `initialState` and `stateMachine` property declarations.
  They stay declared, deprecated and unread, until the repair has run on every
  instance. A follow-up removes them (see design, D6).

## Open product questions

Two questions change who may do what. They are written up in design.md,
"Open questions", with a recommendation each. Implementation of the guard
waits for the answers.

- **Q1.** Which domain policy applies. Today every decision resolves to the
  permissive `operations` policy, because the domain is read from two fields
  no schema declares. Reading it from the governance body, where it lives,
  switches on quorum and chair-only for legislative, association and corporate
  bodies.
- **Q2.** Where chair-only binds. On every write, which means a secretary can
  no longer open or close the vote in a chair-only body. Or on decidiq's own
  decision endpoint only, which keeps today's bypass.

## Impact

- `lib/Settings/decidesk_register.json`, `lib/Settings/decidiq_mock_register.json`:
  the Decision lifecycle becomes an action-keyed map with `requires`.
- `lib/Settings/register.d/43-process-config-v1.json`,
  `lib/Settings/register.d/68-unified-decision-templates.json`: the policy
  properties; legacy properties marked deprecated.
- `lib/Settings/profiles/*.json`, `tests/e2e/ci-seed.sh`: templates in the new
  shape.
- `lib/Lifecycle/`: `DecisionPolicyGuard` and its rules;
  `DecisionTransitionGuard` deleted.
- `lib/Service/`: `DecisionLifecycleService` delegates enforcement;
  `ProcessTemplateService` loses graph validation; `StateMachineValidator`
  deleted.
- `lib/Repair/` and `appinfo/info.xml`: the conversion step.
- `src/`: the policy editor, the edit modal, the body template picker.
- Specs: deltas on `process-configuration` and `decision-management`.

## Risks

- **Every write path now meets the rules.** Motion and voting-round writes go
  through `saveObject()` too, so the guard sees them. Where a rule was
  already enforced on that path (quorum and amendment order on round open,
  terminal completeness on round close) the guard computes it the same way,
  so nothing new is refused. Chair-only is the exception, and Q2 decides it.
- **Stricter, never looser.** A legacy chair-only edge on a shared action
  (`decided → archived` inside `archive`) widens to the whole action. The
  repair reports each case.
- **A guard that cannot resolve refuses.** OpenRegister fails closed on an
  unregistered `requires` tag. A broken guard blocks every Decision lifecycle
  change, which the E2E suite would catch on its first transition.
