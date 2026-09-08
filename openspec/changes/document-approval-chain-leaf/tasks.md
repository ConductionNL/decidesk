# Tasks: document-approval-chain-leaf

## Implementation tasks

### Task 1: Ad-hoc route from named users
- **spec_ref**: `openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md#requirement-req-ar-008-a-route-can-be-held-from-named-users`
- **files**: `lib/Service/ApprovalRouteService.php`, `lib/Event/HoldApprovalRouteRequestedEvent.php`
- **acceptance_criteria**:
  - GIVEN three user ids and a subject WHEN `holdFor()` runs THEN a route with `origin: adhoc` and three stages in that order exists
  - GIVEN the event carries `actors[]` WHEN handled THEN the same route results
- [ ] Implement
- [ ] Test

### Task 2: Deadline split over the stages
- **spec_ref**: `openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md#requirement-req-ar-009-one-deadline-splits-over-the-steps`
- **files**: `lib/Settings/register.d/*.json` (DecisionStage `dueAt`), `lib/Service/ApprovalRouteService.php`
- **acceptance_criteria**:
  - GIVEN a deadline 9 working days out and three stages WHEN held THEN `dueAt` falls on days 3, 6 and 9
  - GIVEN a stage past `dueAt` with no action WHEN the timeline renders THEN it is marked overdue
- [ ] Implement
- [ ] Test

### Task 3: The leaf
- **spec_ref**: `openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md#requirement-req-ar-010-the-route-is-a-render-surface-leaf`
- **files**: `lib/Integration/ApprovalChainLeafListener.php`, `src/integrations/approvalChainLeaf.js`, `src/integrations/ApprovalChainTab.vue`, `src/integrations/ApprovalChainWidget.vue`
- **acceptance_criteria**:
  - GIVEN a host object with a route WHEN the widget renders THEN it shows the current step and, for the current actor, approve and reject
  - GIVEN the parity check WHEN run THEN both halves agree on id and render pair
- [ ] Implement
- [ ] Test (`tests/e2e/approval-chain-leaf.spec.ts`)

### Task 4: A tab on decidesk-decisions
- **spec_ref**: `openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md#requirement-req-ar-011-the-decisions-leaf-names-its-tab`
- **files**: `src/integrations/registerDecisionsLeaf.js`, its PHP descriptor
- [ ] Implement
- [ ] Test

### Task 5: i18n and docs
- Dutch and English strings; `docs/features/approval-chain.md` with screenshots.
- [ ] Implement
