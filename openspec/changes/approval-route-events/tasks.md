# Tasks: approval-route-events

## Implementation Tasks

### Task 1: `sourceApp` + `externalReference` on ApprovalRoute
- **spec_ref**: `openspec/changes/approval-route-events/specs/approval-route-events/spec.md#requirement-req-are-001-a-route-carries-where-it-came-from`
- **files**: `lib/Settings/register.d/73-approval-route-events.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN merged THEN `ApprovalRoute` has both properties and `required` is unchanged
  - GIVEN the merge WHEN inspected THEN no other schema is touched
- [x] Implement
- [x] Test — merge run through the real deep-merge, not assumed

### Task 2: The three events
- **spec_ref**: `.../spec.md#requirement-req-are-002-holding-a-route-is-a-typed-command`
- **files**: `lib/Event/ApprovalRouteRequestedEvent.php`, `lib/Event/ApprovalActionRequestedEvent.php`, `lib/Event/ApprovalRouteConcludedEvent.php`
- **acceptance_criteria**:
  - GIVEN each event WHEN constructed POSITIONALLY THEN every field reads back, because a consumer builds it through a class-string
  - GIVEN the action event's result slots WHEN a refusal occurs THEN the engine's REASON is readable, not just a false
- [x] Implement
- [x] Test

### Task 3: ApprovalRouteCommandService — idempotent template, delegated travel
- **spec_ref**: `.../spec.md#requirement-req-are-003-a-command-may-also-start-a-subject-travelling` (+ REQ-ARE-004/REQ-ARE-005)
- **files**: `lib/Service/ApprovalRouteCommandService.php`, `lib/Service/ApprovalRouteService.php`
- **acceptance_criteria**:
  - GIVEN a command dispatched twice THEN one route exists and the second reports `created = false`
  - GIVEN a subject travelling twice THEN it still has one set of stages
  - GIVEN an edited template THEN a sign-off already in flight keeps its stages and outcomes
  - GIVEN an action THEN every rule is the ENGINE's: recording through the seam and through the service produce identical stage rows
  - GIVEN a route with no steps THEN it is refused before anything is written
- [x] Implement
- [x] Test — mutation-checked: making the route lookup always miss, never reporting completion, and dropping the empty-steps guard each turn the suite red

### Task 4: Listeners + a registrar of their own
- **spec_ref**: `.../spec.md#requirement-req-are-006-a-completed-route-announces-itself`
- **files**: `lib/Listener/ApprovalRouteRequestedListener.php`, `lib/Listener/ApprovalActionRequestedListener.php`, `lib/AppInfo/Registrar/CrossAppEventRegistrar.php`, `lib/AppInfo/Registrar/DomainServiceRegistrar.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a final approval THEN `ApprovalRouteConcludedEvent` carries the correlationId, subject and outcome
  - GIVEN a non-final approval THEN nothing is announced
  - GIVEN the service throws THEN `handled` is false, the reason is readable, and NO exception escapes handle()
  - GIVEN an action command THEN no producer-supplied `step` reaches the engine — it decides which stage is active
  - GIVEN the registrar WHEN measured THEN `DomainServiceRegistrar` is back under the coupling threshold this change pushed it over (0 → 14 → 18 → clean)
- [x] Implement
- [x] Test

### Task 5: UI write affordances on DecisionRouteTab
- **spec_ref**: deferred
- **acceptance_criteria**:
  - Still deferred, as `approval-routes` left it. The tab declares itself read-only; giving it write affordances is a separate change.
- [ ] Implement
- [ ] Test
