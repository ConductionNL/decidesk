# Tasks: governance-body-events

## Implementation Tasks

### Task 1: `sourceApp` + `externalReference` on GovernanceBody
- **spec_ref**: `openspec/changes/governance-body-events/specs/governance-body-events/spec.md#requirement-req-gbe-001-a-governance-body-carries-where-it-came-from`
- **files**: `lib/Settings/register.d/71-governance-body-events.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register imports THEN `GovernanceBody` has both properties and no other schema is touched
  - GIVEN `GovernanceBody.required` WHEN compared to before THEN it is unchanged
- [x] Implement
- [x] Test

### Task 2: The two events
- **spec_ref**: `openspec/changes/governance-body-events/specs/governance-body-events/spec.md#requirement-req-gbe-002-the-seam-is-a-typed-event-dispatched-and-answered-in-process`
- **files**: `lib/Event/GovernanceBodyRequestedEvent.php`, `lib/Event/GovernanceBodyCreatedEvent.php`
- **acceptance_criteria**:
  - GIVEN the request event WHEN constructed positionally THEN every field reads back, because a consumer app builds it through a class-string and cannot use named arguments safely
  - GIVEN the result slots WHEN unset THEN `isHandled()` is false and `getGovernanceBodyId()` is empty
- [x] Implement
- [x] Test

### Task 3: GovernanceBodyCommandService — idempotent upsert + roster fan-out
- **spec_ref**: `.../spec.md#requirement-req-gbe-003-a-repeated-command-updates-it-does-not-duplicate` (+ REQ-GBE-004/REQ-GBE-005)
- **files**: `lib/Service/GovernanceBodyCommandService.php`
- **acceptance_criteria**:
  - GIVEN a command WHEN dispatched twice THEN one body, one Person per uid, one Membership per person — asserted by COUNTING rows after the second call, not by inspecting the first
  - GIVEN a member whose role changes WHEN re-dispatched THEN the existing membership is updated, not supplemented
  - GIVEN a roster WHEN written THEN the body is saved and its id read BEFORE the first membership write
  - GIVEN a command with no `active` WHEN dispatched THEN it is refused and nothing is written
  - GIVEN `active = false` WHEN re-dispatched identically THEN the body stays false
- [x] Implement
- [x] Test

### Task 4: Listener + registration
- **spec_ref**: `.../spec.md#requirement-req-gbe-006-the-producer-learns-the-outcome-by-correlation`
- **files**: `lib/Listener/GovernanceBodyRequestedListener.php`, `lib/AppInfo/Registrar/DomainServiceRegistrar.php`
- **acceptance_criteria**:
  - GIVEN a handled command WHEN it completes THEN `GovernanceBodyCreatedEvent` carries the request's correlationId and the same id
  - GIVEN the service throws WHEN the event is dispatched THEN `handled` is false and NO exception escapes handle()
  - GIVEN an event of another type WHEN passed to handle() THEN it returns without touching anything
- [x] Implement
- [x] Test
