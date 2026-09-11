# Tasks: process-templates-as-lifecycle-policies

Delivered in the PR order of design.md "Delivery". Tasks 1 to 6 do not depend
on the open questions; tasks 7 to 10 wait for Q1 and Q2.

## PR A: the map and the policies

### Task 1: Name the Decision lifecycle actions
- **spec_ref**: `openspec/changes/process-templates-as-lifecycle-policies/specs/decision-management/spec.md#requirement-declarative-decision-lifecycle`
- **files**: `lib/Settings/decidesk_register.json`, `lib/Settings/decidiq_mock_register.json`, `tests/Unit/RegisterJsonTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN the `decision` schema is read THEN `x-openregister-lifecycle.transitions` is a map with exactly `propose`, `deliberate`, `openVoting`, `decide`, `decideWithoutVote`, `enact`, `archive`, `withdraw`
  - GIVEN the map WHEN its (from, to) pairs are listed THEN they are exactly today's thirteen, each owned by one action
  - GIVEN a write from `draft` to `enacted` on :8080 WHEN made through the object API THEN HTTP 422 `lifecycle-invalid-transition`, unchanged from before
- [ ] Implement
- [ ] Test

### Task 2: The policy shape on both template schemas
- **spec_ref**: `openspec/changes/process-templates-as-lifecycle-policies/specs/process-configuration/spec.md#requirement-transition-policies-on-the-decision-lifecycle`, `...#requirement-the-guard-vocabulary-is-declared-and-every-token-is-enforced`
- **files**: `lib/Settings/register.d/43-process-config-v1.json`, `lib/Settings/register.d/68-unified-decision-templates.json`
- **acceptance_criteria**:
  - GIVEN both schemas WHEN inspected THEN each declares `transitionPolicies[]` with `guards.items.enum = ["all_amendments_resolved"]`, identical in shape
  - GIVEN both schemas WHEN inspected THEN `initialState` and `stateMachine` are not in `required[]` and their descriptions say deprecated, read only by the conversion step
  - GIVEN a payload whose `guards` lists `legal_review_complete` WHEN validated THEN it is refused
- [ ] Implement
- [ ] Test

### Task 3: The conversion repair step
- **spec_ref**: `openspec/changes/process-templates-as-lifecycle-policies/specs/process-configuration/spec.md#requirement-stored-template-graphs-are-converted-to-policies`
- **files**: `lib/Repair/ConvertTemplateGraphsToPolicies.php`, `appinfo/info.xml`, `lib/Migration/MigrateLegacyTemplatesToDecisionTemplate.php`, `tests/Unit/Repair/ConvertTemplateGraphsToPoliciesTest.php`
- **acceptance_criteria**:
  - GIVEN the shipped Municipal Council template WHEN converted THEN `openVoting: {chairOnly: true, guards: [all_amendments_resolved]}` and `decide: {chairOnly: true}`, no `stateMachine`, no `initialState`
  - GIVEN `quorum_met`, `legal_review_complete`, an edge with no Decision action, and a chair-only `decided → archived` edge WHEN converted THEN each is logged naming the template
  - GIVEN an absent `quorumRequired` WHEN converted THEN it is stored as `true`
  - GIVEN a converted instance WHEN the step runs a second time THEN no row is written
  - GIVEN no user session WHEN the step runs THEN it reads and writes as system
- [ ] Implement
- [ ] Test (mutation-checked)

### Task 4: Seeds and fixtures in the new shape
- **files**: `lib/Settings/profiles/association.json`, `lib/Settings/profiles/corporate.json`, `lib/Settings/profiles/municipality.json`, `tests/e2e/ci-seed.sh`
- **acceptance_criteria**:
  - GIVEN every seeded template WHEN inspected THEN it carries `transitionPolicies` and no `stateMachine` or `initialState`
  - GIVEN the conversion step's own mapping WHEN applied to the old seed THEN it yields the new seed byte for byte (the seeds are produced by the step, not by hand)
- [ ] Implement
- [ ] Test

### Task 5: Service and controller without graph validation
- **spec_ref**: `openspec/changes/process-templates-as-lifecycle-policies/specs/process-configuration/spec.md#requirement-process-template-management`
- **files**: `lib/Service/ProcessTemplateService.php`, `lib/Controller/ProcessTemplateController.php`, `appinfo/routes.php`, `lib/Lifecycle/ProcessTemplatePolicyResolver.php`, delete `lib/Service/StateMachineValidator.php` and its test
- **acceptance_criteria**:
  - GIVEN a template naming an action the live annotation does not declare WHEN saved THEN HTTP 400 naming it
  - GIVEN `POST /api/process-templates/validate` WHEN called THEN the route no longer exists
  - GIVEN a template with policies WHEN resolved THEN the existing guard receives the same chair-only edges as before the change
  - GIVEN gate 23 in forced block mode WHEN run THEN exit 0, 0 findings
- [ ] Implement
- [ ] Test

### Task 6: The admin surfaces
- **spec_ref**: `openspec/changes/process-templates-as-lifecycle-policies/specs/process-configuration/spec.md#requirement-transition-policies-on-the-decision-lifecycle`, `...#requirement-process-template-management`
- **files**: `src/components/processTemplates/TransitionPolicyEditor.vue` (new), `src/modals/ProcessTemplateEditModal.vue`, `src/components/tabs/GovernanceBodyTemplateTab.vue`, `src/store/modules/processTemplates.js`, delete `src/components/processTemplates/StateMachineEditor.vue`, `src/services/processTemplateGraph.js` and its test, `l10n/*`, `tests/e2e/spec-coverage/process-configuration.spec.ts`
- **acceptance_criteria**:
  - GIVEN the template modal WHEN opened THEN it lists the lifecycle's actions with "Only the chair" and "All amendments decided first", plus "Quorum required before voting" and "Allow deciding without a vote"
  - GIVEN the body template tab WHEN opened THEN the choices are the stored templates by name, not the four hardcoded ids
  - GIVEN Dutch and English WHEN the new strings are read THEN both exist, in sentence case, without em-dashes
- [ ] Implement
- [ ] Test

## PR B: the guard (after Q1 and Q2)

### Task 7: The guard and its rules
- **spec_ref**: `openspec/changes/process-templates-as-lifecycle-policies/specs/decision-management/spec.md#requirement-decision-state-machine`
- **files**: `lib/Lifecycle/DecisionPolicyGuard.php`, `lib/Lifecycle/DecisionPolicyResolver.php`, `lib/Lifecycle/DecisionOutcomeRules.php`, `lib/Service/VotingRoundOpener.php` (quorum shared), `lib/Service/AmendmentOrderService.php` (undecided-amendment check shared), tests under `tests/Unit/Lifecycle/`
- **acceptance_criteria**:
  - GIVEN each rule WHEN checked allowed and refused THEN `GuardResult::allow()` and `GuardResult::deny()` with a message naming what is missing
  - GIVEN each guard test WHEN its rule is broken on purpose THEN the named assertion reddens, and the restored file is byte-identical
  - GIVEN an empty uid WHEN a chair-only action is checked THEN it is refused
  - GIVEN a referenced meeting that cannot be loaded WHEN quorum is required THEN it is refused
- [ ] Implement
- [ ] Test (mutation-checked)

### Task 8: Wire the guard into the map
- **files**: `lib/Settings/decidesk_register.json`, `lib/Settings/decidiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN every transition WHEN inspected THEN `requires` is `OCA\Decidiq\Lifecycle\DecisionPolicyGuard`
  - GIVEN :8080 WHEN a decision is moved through the object API THEN the guard runs (a refused write carries `lifecycle-guard-denied`)
- [ ] Implement
- [ ] Test

### Task 9: Retire the decidiq-local map
- **files**: `lib/Service/DecisionLifecycleService.php`, `lib/Lifecycle/MotionLifecycleTransitioner.php`, `lib/Controller/VotingController.php` or the round services (per Q2), delete `lib/Lifecycle/DecisionTransitionGuard.php`, `tests/Unit/Lifecycle/DecisionTransitionGuardTest.php`, `tests/Unit/Lifecycle/DecisionTransitionMatrixTest.php`, update the tests that construct it
- **acceptance_criteria**:
  - GIVEN `decide` from `deliberating` on decidiq's endpoint WHEN called THEN it is judged as `decideWithoutVote`
  - GIVEN a guard refusal WHEN decidiq's endpoint transitions THEN the response carries the guard's message and nothing is persisted
  - GIVEN available actions WHEN requested THEN they come from the annotation minus what the policy forbids
  - GIVEN `git grep DecisionTransitionGuard -- lib` WHEN run THEN nothing matches
- [ ] Implement
- [ ] Test

### Task 10: E2E through OpenRegister's object API
- **spec_ref**: `openspec/changes/process-templates-as-lifecycle-policies/specs/decision-management/spec.md#requirement-decision-state-machine`, `openspec/changes/process-templates-as-lifecycle-policies/specs/process-configuration/spec.md#requirement-transition-policies-on-the-decision-lifecycle`
- **files**: `tests/e2e/workflows/decision-lifecycle-policy.spec.ts`
- **acceptance_criteria**:
  - GIVEN a run-unique non-superuser in `decidiq-administrators` WHEN it moves a decision `deliberating → decided` in a body that forbids deciding without a vote THEN 422 with `lifecycle-guard-denied`, lifecycle unchanged, and the account named in the assertion
  - GIVEN the same account WHEN it moves a decision `deliberating → voting` in a body without quorum THEN 200 and `voting` stored
  - GIVEN an admin who ticks "Allow deciding without a vote" in the admin settings WHEN the same account repeats the first write THEN it succeeds
  - GIVEN the guard's `requires` removed on purpose WHEN the spec runs THEN the first test reddens on the code assertion
- [ ] Implement
- [ ] Test

## Follow-ups (not in this change)

- Remove the deprecated `initialState` and `stateMachine` declarations once the conversion step has shipped.
- Fold `MotionLifecycleTransitioner`'s per-type narrowing into the guard.
- Read the domain from the governance body (Q1-a), as its own change.
- Correct `authorization-via-or-rbac` REQ-RBAC-003, which cites a `DecisionTransitionGuard::isOpenAllowed` that has never existed (the meeting guard owns `isOpenAllowed`).
