# Tasks: decision-state-read-seam

## Implementation Tasks

### Task 1: The read event
- **spec_ref**: `openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md#requirement-req-dde-005-public-decisionstaterequestedevent-contract-class`
- **files**: `lib/Event/DecisionStateRequestedEvent.php`
- **acceptance_criteria**:
  - GIVEN the event WHEN constructed POSITIONALLY THEN every request field reads back, because a consumer builds it through a class-string
  - GIVEN the result slots WHEN read THEN "could not answer", "does not exist" and "not permitted" are three distinguishable answers, not one absent envelope
- [x] Implement
- [x] Test

### Task 2: The listener, over the existing envelope and the existing guard
- **spec_ref**: `.../spec.md#requirement-req-dde-006-listener-answers-a-state-read-from-the-existing-envelope-and-the-existing-guard`
- **files**: `lib/Listener/DecisionStateRequestedListener.php`, `lib/AppInfo/Registrar/CrossAppEventRegistrar.php`
- **acceptance_criteria**:
  - GIVEN a concluded Decision THEN the reported status is the value `getOutcomeEnvelope()` derived, not a second derivation
  - GIVEN an unreachable OpenRegister THEN the event is left unhandled, never reported as a refusal
  - GIVEN any failure THEN no exception reaches the dispatcher
- [x] Implement
- [x] Test — the REAL guard and the REAL service, over a fake that refuses what live OpenRegister refuses

### Task 3: The guard's third answer
- **spec_ref**: `.../spec.md#requirement-req-dde-007-a-state-read-is-scoped-to-a-named-actor-and-never-elevated`
- **files**: `lib/Service/DecisionIntegrationAuthorizationGuard.php`
- **acceptance_criteria**:
  - GIVEN `resolveOutcomeReadAccess()` THEN it reports allowed / denied / unresolved
  - GIVEN `isAuthorizedToReadOutcome()` THEN it delegates, so REQ-DCDH-101 is stated once and the HTTP behaviour is unchanged
  - GIVEN an empty actor or an empty decision id THEN the answer is denied, never elevated
- [x] Implement
- [x] Test

## Verification
- [x] `composer lint` / `phpcs` / `phpmd` / `psalm` / `phpstan` green
- [x] PHPUnit green
- [x] Negative control: replacing the guard consultation with an unconditional `allowed` reds the refusal test and the unreachable-store test
