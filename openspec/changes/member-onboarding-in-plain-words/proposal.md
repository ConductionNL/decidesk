# member-onboarding-in-plain-words

**Status**: planned
**Scope**: decidiq

## Why

A traject is a pathway. Joining a governance body, and leaving one, is a pathway every organisation walks: a council installs a member, a board admits a director, an association welcomes someone onto a committee.

`the-last-two-dutch-names` said decidiq shipped no Dutch-named schema outside the Woo plumbing. It was wrong. `OnboardingTraject` and `OffboardingTraject` were still here, and they were not only Dutch: their descriptions were written for a griffie handling a raadswisseling, one country's office doing one country's job.

Measured on development after that change landed: 67 active schemas, and these two were the only ones left carrying a Dutch name.

## What changes

`OnboardingTraject` becomes `MemberOnboarding` and `OffboardingTraject` becomes `MemberOffboarding`.

Three properties are renamed with them. `beëdigingsType` was the last Dutch-spelled property in the app and becomes `installationType`. `swearingInDate` becomes `installedOn` and `swearingInMeeting` becomes `installationMeeting`: swearing in is a council's ceremony, and a board signs a deed instead.

Three vocabularies stop constraining, the same treatment `the-last-two-dutch-names` gave `expectedType` and `ownerType`. `trigger` fixed `council-turnover-batch`, and `endReason` fixed `end-of-council-membership`; `installationType` fixed oath and affirmation, which a company board has neither of. Existing values stay valid because the fields no longer constrain them.

Every description and example is rewritten. Many were also simply stale: they documented Dutch values such as `gestart`, `afgerond` and `verplicht` that an earlier change had already anglicised, so the schema was describing values that no longer existed.

## Decision: the surfaces move too, including two this rename does not touch

The user asked for the surfaces, so the routes follow: `/onboarding-trajecten` becomes `/member-onboarding` and `/offboarding-trajecten` becomes `/member-offboarding`.

Two more routes were Dutch without a Dutch schema under them. `/wor-trajecten` sits on the neutral `consultation-request` and becomes `/works-council-consultations`, with its page ids. `/audit-statementen` sits on the neutral `audit-statement` and becomes `/audit-statements`. Neither needed a migration; only the route and the ids were left behind.

## 🔴 Decision: these two get an authorization block, and it is new

Every rename before this one carried its predecessor's `authorization` block across. These two had none to carry.

That meant their rule came from the decidiq register baseline, which lists `public` among both readers and listers. A member's name, their account id, when they were installed, and why they left, including reasons such as death and relocation, were readable and listable by anonymous visitors. Nothing reported it, which is the same defect `conflict-of-interest-authorization-guard` fixed for `ConflictOfInterest`.

`MemberOnboarding` and `MemberOffboarding` declare `read` and `list` for `authenticated` only. Both are declared rather than just `read`, because a schema block shadows the register baseline and leaving `list` unstated would leave its resolution to a fallback this repository does not own. `list` is a canonical action in OpenRegister's `PermissionHandler`, checked against the installed library rather than assumed. No write action is declared, so create, update and delete stay on the baseline.

The authorization count in `RegisterAuthorizationTest` therefore moves by two, not by nothing. Every earlier rename moved it by one or by zero.

## What this change does not do

Two things in these schemas are still one country's words, and both are left alone deliberately.

The notification recipients name a `griffie` group. That is a Nextcloud group name, it appears across 28 files in this app, and renaming it is a deployment concern with its own consequences. It needs its own change.

The `swearing-in` step type is still in the `stepType` vocabulary. Its value is already English and it is stored data on existing rows, so moving it is a value migration rather than a rename, and it belongs with whatever change takes on the `griffie` group.

## Impact

Existing rows are copied, never edited or deleted. Both source schemas keep their definition with `active:false` and `hardDelete:false`, so nothing stored becomes unreadable.

After this, decidiq ships no Dutch-named schema and no Dutch route.
