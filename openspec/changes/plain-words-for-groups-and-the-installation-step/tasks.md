# plain-words-for-groups-and-the-installation-step tasks

## 1. The groups

- [x] 1.1 Rename the recipient groups in the schemas that declare them
  **files**: lib/Settings/register.d/88-member-onboarding-in-plain-words.json, lib/Settings/register.d/81-integrity-disclosures-in-plain-words.json
- [x] 1.2 Copy memberships across without emptying the old group
  **files**: lib/Repair/MigrateGovernanceGroupNames.php, tests/Unit/Repair/MigrateGovernanceGroupNamesTest.php
- [x] 1.3 Replace the mayor in the integrityNotificationGroup example
  **files**: lib/Settings/register.d/81-integrity-disclosures-in-plain-words.json

## 2. The step type

- [x] 2.1 Rename the enum value and its example
  **files**: lib/Settings/register.d/88-member-onboarding-in-plain-words.json
- [x] 2.2 Move the seeded steps onto it
  **files**: lib/Settings/profiles/municipality.json
- [x] 2.3 Rewrite stored steps, reading the objects rather than a column that does not exist
  **files**: lib/Migration/RenameSwearingInStepType.php

## 3. Registration

- [x] 3.1 Register both repair steps
  **files**: appinfo/info.xml
