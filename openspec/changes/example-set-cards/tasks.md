# Tasks: example-set-cards

> Cards over the example sets, several at a time (ADR-032 `kind: code`).
> Checkbox budget: 4 tasks × 2 = 8 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: The offered list, declining included
- **spec_ref**: `openspec/changes/example-set-cards/specs/example-set-cards/spec.md#requirement-the-wizard-offers-the-sets-the-app-ships`
- **files**: `lib/Service/SeedProfileService.php`, `lib/Settings/profiles/*.json`, `tests/Unit/Service/SeedProfileServiceTest.php`
- **acceptance_criteria**:
  - `listChoices()` leads with `none` and then the shipped sets in order
  - `isKnown('none')` stays false, so the importer is never handed it
  - Every entry carries a label, a description and a PascalCase icon name
- [x] Implement
- [x] Test

### Task 2: The step asks for cards and reads the server's list
- **spec_ref**: `openspec/changes/example-set-cards/specs/example-set-cards/spec.md#requirement-the-wizard-offers-the-sets-the-app-ships`
- **files**: `src/manifest.json`, `l10n/nl.json`, `l10n/nl.js`, `package.json`
- **acceptance_criteria**:
  - The step declares `display`, `optionsSource` and `multiple`, and no `options`
  - The Dutch copy covers the changed bodies and the six descriptions the cards render
  - The `@conduction/nextcloud-vue` floor is raised to the version that renders cards
- [x] Implement
- [x] Test

### Task 3: Several sets, stored and imported
- **spec_ref**: `openspec/changes/example-set-cards/specs/example-set-cards/spec.md#requirement-several-example-sets-can-be-loaded-at-once`
- **files**: `lib/Controller/SetupController.php`, `tests/Unit/Controller/SetupControllerTest.php`
- **acceptance_criteria**:
  - A list is stored comma-separated; a repeat is stored once; `none` loses to a set
  - One bad id refuses the whole pick and stores nothing
  - Every picked set is imported and the message names the total and the count
  - A failure halfway through returns 500 naming what landed, and does not record the step as decided
- [x] Implement
- [x] Test

### Task 4: The wizard is walked end to end
- **spec_ref**: `openspec/changes/example-set-cards/specs/example-set-cards/spec.md#requirement-loading-imports-every-set-that-was-picked`
- **files**: `tests/e2e/spec-coverage/example-set-setup-step.spec.ts`
- **acceptance_criteria**:
  - The step renders one card per offered set, with its description visible
  - Two cards can be picked and both sets land
  - The recap names the picked sets by label
- [x] Implement
- [x] Test
