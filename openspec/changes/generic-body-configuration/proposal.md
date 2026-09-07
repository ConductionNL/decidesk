---
kind: code
---

# Proposal: generic-body-configuration

# Summary

Replace `VveConfiguration` with `BodyGovernanceConfiguration`: the same four facts, named so they fit a company, an association, a works council and a VvE alike.

## Motivation

`VveConfiguration` binds a governance body to four things. Every one of them is a general idea wearing a Dutch VvE name:

| VvE name | what it actually is |
|---|---|
| `deedOfDivisionDocument` (splitsingsakte) | the document that **constitutes** the body |
| `modelReglementVersion` (1992/2006/2017) | the **version of the model regulation** it follows |
| `fractionDenominator` (breukdelen) | the **denominator its weighted votes are expressed over** |
| `majorityOverrides[].decisionCategory` | a **template category** whose default majority is overridden |

A company limited by shares has articles of association, an articles version, and a share count its votes are weighted by. It could not describe any of that, because the schema only offered VvE words. So a body that was not a VvE simply had no configuration, and the gear offered an entry a municipality had no use for.

`modelRegulation` is worse than merely specific: it `$ref`s `modelreglement-preset`, a schema `unified-decision-templates` **retired**. It points at nothing that is still live.

## Affected Projects

- [x] Project: `decidiq` — this change.

## Scope

**In scope.** The generic schema, the migration that carries existing rows across, the settings surface, and the example-set seeds.

**Out of scope.** `KascommissieVerklaring`, which is the next link. It is already generic in shape (a financial year, a verdict, a body) and needs only a rename plus the two Vue files that carry its name, so it earns its own change rather than riding along here.

## How it supersedes

Non-destructively, exactly as `unified-decision-templates` did and as `67-model-debt-cleanup.json` did for `BoardProxy`: `VveConfiguration` keeps its definition and its rows (`active: false`, `hardDelete: false`), and `MigrateVveToBodyConfiguration` copies each row onto the generic schema. Nothing is edited and nothing is deleted, so a rollback still finds its data.

`modelRegulation` is deliberately **not** carried across: it referenced a retired schema, and the majorities it used to resolve now live on each `DecisionTemplate`'s own `votingRule`. The plain `modelReglementVersion` string that `unified-decision-templates` added alongside it for exactly this reason becomes `regulationVersion`.

## Two decisions worth stating

**`required` narrows to `governanceBody` alone.** It was `governanceBody`, `modelRegulation`, `fractionDenominator`. The first of those now points at a retired schema and the second is meaningless for a body whose members each have one equal vote, so requiring either would make the generic schema undeclarable for most organisations.

**Idempotency keys on the governance BODY, not the source row.** The schema declares exactly one configuration per body, so the body is the identity. Keying on the source uuid would let a re-seeded source create a second configuration for the same body, which is precisely the defect that duplicated every built-in decision template.

## A broken seed removed on the way

`vve-zeewaarts-configuratie` referenced `governanceBody: 00000000-0000-0000-0000-000000000000` and a null-UUID splitsingsakte. No such body exists in any example set. It was an orphan describing nothing, and it is dropped rather than carried into the generic schema.
