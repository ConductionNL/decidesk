# plain-words-for-groups-and-the-installation-step

**Status**: planned
**Scope**: decidiq

## Why

`member-onboarding-in-plain-words` left two things behind and said so in its own proposal: a notification recipient group called `griffie`, and a step type called `swearing-in`. Measuring them properly to size the follow-up turned up a third: `decidesk-integriteit`.

A griffie is a Dutch council's clerk office. Swearing in is a council's ceremony. Neither belongs in a model that is meant to fit a company board and an association as well.

## What changes

Two groups are renamed, and their memberships are carried across:

| was | is | what it does |
|---|---|---|
| `griffie` | `decidiq-secretariat` | recipient of the member joining and leaving notifications |
| `decidesk-integriteit` | `decidiq-integrity` | recipient of the gift and outside-position notifications |

The `swearing-in` onboarding step type becomes `installation`, matching the `installationType`, `installedOn` and `installationMeeting` properties the previous change already renamed.

One schema example changes: `integrityNotificationGroup` illustrated itself with `decidesk-burgemeester`, a mayor. It now reads `decidiq-chair`.

## Decision: copy memberships, never empty the old group

The old groups are not deleted and not emptied. An admin whose own automation still names one keeps a working group; only the schemas stop naming it. That is what `MigrateAdminGroup` did for `decidesk-administrators`, and this step follows it.

## Decision: `decidesk-members` stays

It appears 95 times and its prefix is the old app id, not a Dutch word. An app id moves as a coordinated fleet pass, not piecemeal inside one change.

## 🔴 Decision: the step type is NOT migrated by RenameDutchDecidiqValues

That step exists to rewrite stored values and already handles `beëdigingsType`, so it is the obvious home. It is the wrong one.

`DbValueMigrationGateway::columnsOf()` reads `information_schema.columns`, and `plannedRewrites()` only plans a rewrite where a value-map key IS a column. `stepType` is not a column: it lives inside the `steps` array of an object payload. Adding it to `VALUE_MAP` would have planned nothing, rewritten nothing, and reported success.

So `RenameSwearingInStepType` reads the objects instead and rewrites the nested value. The class docblock says why, so nobody moves it back.

## What this leaves

`political-group-assignment` is still in the `stepType` vocabulary, and a political group is a council's word for a faction. It is stored data like `swearing-in` was, and it was not part of what was asked for here. Recorded rather than folded in quietly.

Prose mentions of `griffie` remain in the descriptions of thirteen register fragments, most of them on schemas that are already retired. Those are documentation, not identifiers, and none of them changes behaviour.

## Impact

No route or schema changes. After this, the app names no Nextcloud group, and no step type, in one country's words.
