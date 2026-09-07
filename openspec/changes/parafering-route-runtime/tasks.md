# Tasks: parafering-route-runtime

> The decidiq half of the parafering-runtime move. Counterpart:
> dossiq's `parafering-runtime-to-decidiq`, which MUST merge after this —
> dossiq retires its route advancement on the assumption this engine already
> holds it.

## Implementation Tasks

### Task 1: The stage-typed vocabulary and mandatory free text
- **spec_ref**: `openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md#requirement-req-prr-001-a-completing-verb-fits-its-stage-type`
- **files**: `lib/Service/ApprovalRouteService.php`
- [x] Implement
- [x] Test — mutation-checked: short-circuiting `assertVerbFitsStage` turns the suite red

### Task 2: Mandated delegate signing with the registry judgement
- **spec_ref**: `.../spec.md#requirement-req-prr-002-a-mandated-delegate-may-sign-and-only-a-mandated-delegate`
- **files**: `lib/Service/ApprovalRouteService.php`, `lib/Service/MandateDirectory.php`
- [x] Implement
- [x] Test — mutation-checked twice: dropping the structural onBehalfOf/mandate check and dropping the `MandateDirectory` call each turn named tests red

### Task 3: The terminal return (terugsturen)
- **spec_ref**: `.../spec.md#requirement-req-prr-003-a-return-naming-no-step-concludes-the-route-to-its-sender`
- **files**: `lib/Service/ApprovalRouteService.php`
- [x] Implement — a refused forward return now also refuses BEFORE the append, closing a pre-existing leak where the refused action row survived
- [x] Test — mutation-checked: a no-op `applyTerminalReturn` turns two tests red

### Task 4: Parallel groups, and sequence = the step's own order
- **spec_ref**: `.../spec.md#requirement-req-prr-004-steps-sharing-an-order-sign-side-by-side`
- **files**: `lib/Service/ApprovalRouteService.php`
- [x] Implement
- [x] Test — mutation-checked: advancing past a still-signing sibling turns the suite red

### Task 5: One announcer, every concluding path
- **spec_ref**: `.../spec.md#requirement-req-prr-005-a-conclusion-is-announced-from-every-concluding-path`
- **files**: `lib/Service/ApprovalRouteConclusionAnnouncer.php`, `lib/Event/ApprovalRouteConcludedEvent.php`, `lib/Listener/ApprovalActionRequestedListener.php`, `lib/Controller/ApprovalRouteController.php`, `lib/Settings/register.d/75-parafering-route-runtime.json` (DecisionStage.route)
- [x] Implement
- [x] Test

### Task 6: The task-surface ride
- **spec_ref**: `.../spec.md#requirement-req-prr-006-the-ask-rides-the-task-surface-and-only-rides`
- **files**: `lib/Service/ApprovalStageTaskProjector.php`, `lib/Listener/ApprovalTaskDecisionListener.php`, `lib/AppInfo/Registrar/CrossAppEventRegistrar.php`, `lib/Settings/register.d/75-parafering-route-runtime.json` (DecisionStage.taskUuid)
- [x] Implement
- [x] Test — the consumed-task non-echo and the no-surface no-op are both pinned

### Task 7: l10n
- **files**: `l10n/en.json`, `l10n/nl.json`, generated `l10n/*.js`, schema-l10n baseline
- [x] The two new schema property descriptions carried in English and Dutch
