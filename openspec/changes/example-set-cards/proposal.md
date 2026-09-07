---
kind: code
---

# Proposal: example-set-cards

## Summary

Show the example sets as cards, one per kind of organisation, each carrying its
description and how many objects it holds. Let an operator pick more than one.
The list comes from the server, so the manifest stops keeping a second copy of
it.

## Motivation

The wizard asks the right question and then hides the answer. Behind an
`NcSelect`, the six options read as six words: "Municipality", "Association or
VvE", "Company board". Every fact that makes the question answerable lives
somewhere the operator cannot see it.

The server already has those facts. Each descriptor in `lib/Settings/profiles/`
declares a label, a description and an object count, and `GET /api/setup/status`
has been returning all three as `profiles` since the sets shipped. Nothing read
them.

The manifest meanwhile carried its own hand-written list of the same six
options, guarded by a unit test whose entire job was to notice the two lists
drifting apart. A second copy that needs a test to stay honest is a second copy
that should not exist.

Three of the four sets are also worth loading together. A works council inside
a company and the company board that hears it are the same demo; so are a
municipality and the association it grants a subsidy to. The step allowed one.

## What changes

| | Before | After |
|---|---|---|
| The options | A static list in `src/manifest.json` | `optionsSource: profiles`, read from the status document |
| The renderer | `NcSelect` | `CnChoiceCards`, description and count per card |
| How many | One | Several, `multiple: true` |
| `none` | A manifest option | A `listChoices()` entry, so it is offered without being importable |
| Stored value | One id | A comma-separated list of ids |

## Affected Projects

- [x] `decidiq` — the step, the controller, `listChoices()`, an icon per set.
- [ ] `nextcloud-vue` — `CnChoiceCards` and the two manifest keys, shipped in
      `setup-choice-cards`. Requires >= 2.37.0.

## Design notes

**`none` is offered but not importable.** `listChoices()` is separate from
`listProfiles()` on purpose: `isKnown('none')` must keep answering false, or
`install()` would be handed an id no descriptor declares.

**Ticking "None" and a set is not an error, it is a correction.** The cards are
checkboxes, so both can be ticked. `saveConfig()` drops `none` when anything
else is picked rather than refusing the pick.

**A partial import is reported as a failure.** Three sets asked for and one
imported is not a success with a smaller number. The sets that did land stay
landed, because the import is idempotent by slug: running the step again
finishes the job.

**Comma-separated, not JSON.** `occ config:app:get decidiq example_profile`
stays readable, and a value stored before this change reads back as the
one-element list it is.

## Risks

- **The manifest can now ask for a list the app does not serve.** `display` and
  `optionsSource` are inert if the wizard is older than 2.37.0: the step falls
  back to a dropdown with no options at all, because the static list is gone.
  The dependency floor is the guard, and it has to be raised in the same commit.
- **The stored value's shape changed.** Anything reading `example_profile` as a
  single id reads `municipality,works-council` as one unknown set. Only this
  controller reads it, and it now goes through `pickedProfiles()`.
