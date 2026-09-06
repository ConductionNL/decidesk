# integrity-disclosures-in-plain-words

**Status**: planned
**Scope**: decidiq

## Why

A nevenfunctie is an ancillary position. A geschenk is a gift.

Every organisation with a board has both. A director declares the other boards they sit on; a member declares the case of wine a supplier sent. Nothing about either is particular to a Dutch council, and the properties were already written in plain words. Only the schemas were not.

`Integriteitsbeleid` is a different case. It is a per-body configuration keyed on `governanceBody`, and so is `BodyGovernanceConfiguration`, which describes itself as "per-body governance context, for ANY kind of organisation". Two configurations for one body means two places to look and two to keep in step.

## What changes

`Nevenfunctie` becomes `AncillaryPosition` and `Geschenk` becomes `DeclaredGift`. Every property keeps its name, so the migration is a copy with a different schema on it.

`Integriteitsbeleid` folds into `BodyGovernanceConfiguration`, which gains its four fields.

The routes follow: `/nevenfuncties` becomes `/ancillary-positions`, `/geschenken` becomes `/declared-gifts`, and `/mijn-opgaven` becomes `/my-declarations`.

## Decision: the slug is `declared-gift`, not `gift`

Schema slugs are global on a shared OpenRegister. `gift` is broad enough that another app in this fleet would reasonably claim it, and the last time this programme picked a bare noun, gate-106 refused it because dossiq already owned it.

`declared-gift` also says the thing that matters: it is a gift somebody declared, not a gift the organisation gave.

## Decision: this is a rename, not a fold

It is tempting to make one `InterestDisclosure` with a type, since an ancillary position and a gift are both things a member must declare. They do not share a shape. An ancillary position has a duration and a remuneration; a gift has a giver, a value, and a decision about whether it was kept. Folding them would leave every row with half its fields empty, which is the shape the consultation fold deliberately avoided by leaving `PublicConsultation` alone.

## Impact

Three Dutch schema names go and one schema disappears. An operator who reads English gets pages named for what they hold.
