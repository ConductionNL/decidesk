# member-onboarding-in-plain-words tasks

## 1. The schemas

- [x] 1.1 Declare MemberOnboarding and MemberOffboarding, renaming beëdigingsType, swearingInDate and swearingInMeeting with them
  **files**: lib/Settings/register.d/88-member-onboarding-in-plain-words.json
- [x] 1.2 Free the trigger, endReason and installationType vocabularies
  **files**: lib/Settings/register.d/88-member-onboarding-in-plain-words.json
- [x] 1.3 Retire OnboardingTraject and OffboardingTraject with active:false and hardDelete:false
  **files**: lib/Settings/register.d/88-member-onboarding-in-plain-words.json
- [x] 1.4 Rewrite every description and example, including the ones documenting Dutch values an earlier change had already anglicised
  **files**: lib/Settings/register.d/88-member-onboarding-in-plain-words.json
- [x] 1.5 Declare the read and list block both schemas were missing
  **files**: lib/Settings/register.d/88-member-onboarding-in-plain-words.json, tests/Unit/RegisterAuthorizationTest.php
- [x] 1.6 Register the two new slugs on the register row
  **files**: lib/Settings/decidesk_register.json

## 2. Carrying the rows

- [x] 2.1 Copy every row onto its renamed schema, resolving swearingInMeeting as a reference AND writing it under the new key
  **files**: lib/Migration/MigrateMemberOnboarding.php
- [x] 2.2 Register the repair step
  **files**: appinfo/info.xml

## 3. The surfaces

- [x] 3.1 Rename the pages, routes, menu entries and schema references
  **files**: src/manifest.d/member-onboarding.json, src/menu-layout.json
- [x] 3.2 Rename /wor-trajecten and its page ids, and /audit-statementen
  **files**: src/manifest.d/works-council-consultation.json, src/manifest.d/vve-alv-pack.json, src/manifest.json, src/menu-layout.json
- [x] 3.3 Follow the renamed ids into the live specs
  **files**: openspec/specs/app-navigation/spec.md, openspec/specs/decision-management/spec.md
- [x] 3.4 Move the seeds onto the renamed schemas and properties
  **files**: lib/Settings/profiles/municipality.json
- [x] 3.5 Translate the new strings and tighten the schema-l10n ratchet
  **files**: l10n/en.json, l10n/nl.json, l10n/.schema-l10n-baseline.json
