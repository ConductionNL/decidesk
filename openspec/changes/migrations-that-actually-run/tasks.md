# migrations-that-actually-run tasks

## 1. Resolve from the schema, not from a list

- [x] 1.1 Add coerceToTarget(), driven by the target's own `format` and `$ref`
  **files**: lib/Migration/ReadsLegacyRows.php
- [x] 1.2 Read the shipped schema with the same DEEP merge the app imports with
  **files**: lib/Migration/ReadsLegacyRows.php
- [x] 1.3 Never resolve a value the caller already resolved
  **files**: lib/Migration/ReadsLegacyRows.php
- [x] 1.4 Wire it into every migration that copies rows
  **files**: lib/Migration/MigrateCommitments.php, lib/Migration/MigrateConfidentialityRecords.php, lib/Migration/MigrateIntegrityDisclosures.php, lib/Migration/MigratePlanningCycles.php, lib/Migration/MigrateTheLastTwoDutchNames.php, lib/Migration/MigrateMemberOnboarding.php, lib/Migration/MigrateDocumentsToAgendaItems.php, lib/Migration/MigrateConsultationsToOneSchema.php
- [x] 1.5 Pin the behaviour
  **files**: tests/Unit/Migration/CoerceToTargetTest.php

## 2. The other three shapes

- [x] 2.1 Carry the required property into both partial updates
  **files**: lib/Migration/MigrateIntegrityDisclosures.php, lib/Migration/MigrateQuestionsToAgendaItems.php
- [x] 2.2 Translate the advice-request lifecycle vocabulary
  **files**: lib/Migration/MigrateConsultationsToOneSchema.php
- [x] 2.3 Drop `list` from the authorization block so the schemas exist at all
  **files**: lib/Settings/register.d/88-member-onboarding-in-plain-words.json, tests/Unit/RegisterAuthorizationTest.php

## 3. Evidence

- [x] 3.1 Measure on a live instance across successive upgrades: 18 → 0
  **files**: openspec/changes/migrations-that-actually-run/proposal.md
