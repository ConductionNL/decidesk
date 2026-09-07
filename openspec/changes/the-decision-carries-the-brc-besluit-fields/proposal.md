# The Decision carries the BRC Besluit fields

## Why

Two apps declared a `decision`. A schema slug is global per organisation and
`SchemaMapper::find()` matches `LOWER(slug)`, so whichever row was reached first
answered for both.

They were not two copies of one record:

| | decidiq `decision` | dossiq `decision` |
| --- | --- | --- |
| properties | 48 | 12 |
| what it is | the governance decision: motion, voting, adoption, resolution number, amends/repeals/implements | the VNG **Besluit** behind the BRC API |
| wired into | meetings, agenda items, voting rounds | `BrcController`, ZRC, WOO assessments, the bezwaar lifecycle |

dossiq's is a standards-compliant municipal API surface, not a second copy of
this one. Folding them therefore means this schema has to hold what that API
serves.

## What changes

`Decision` gains four fields, in a `register.d` fragment per ADR-037:

- `deliveryDate` — BRC `verzenddatum`
- `expiryDate` — BRC `vervaldatum`
- `publicationDate` — BRC `publicatiedatum`
- `responsibleOrganisation` — the RSIN

`responsibleOrganisation` is a plain string, not a `$ref`. The BRC identifies the
responsible party by RSIN, and a reference that only resolves inside one instance
would break the contract.

## What is deliberately not added

**`case`.** Cases and decisions are already linked. A second link would let the
two disagree, with nothing to say which is right.

**Nothing is collapsed.** `effectiveDate` and `expiryDate` are not the same
field, and neither are `publishedAt` and `publicationDate`: the first of each
pair is about this system, the second is a date the decision itself carries.
Merging either pair would look like tidying and would lose a distinction the BRC
makes. A test asserts all four survive.

## What comes next, in dossiq

dossiq keeps its `BrcController` — the standard surface stays where the standard
lives — and repoints it at this register. That is a separate change in dossiq,
and it is what actually retires the duplicate schema.
