# one-consultation-schema

**Status**: planned
**Scope**: decidiq

## Why

Three pairs of schemas wrote the same sentence three times.

An organisation asks a set of parties for their view on something by a deadline. They answer. The answers are processed into a decision.

That is `Adviesaanvraag` and `Advies`. It is also `Zienswijzeronde` and `Zienswijze`. It is also `MemberConsultation` and `MemberConsultationResponse`. Six schemas, four menu entries, seven pages, three sets of quick filters, three lifecycle enums that differ only in spelling. On a decision's detail page, three of the four consultation widgets filtered on the same field and listed the same kind of thing under three different council words.

## What changes

`Consultation` and `ConsultationResponse` are new, and the six retire into them.

New rather than one of the three widened, because none could absorb the others honestly. `MemberConsultation` says in its own description that it is "explicitly NOT a formal ballot", and its audience can only be members, a fraction or a Nextcloud group. A statutory advice request is formal, and it addresses another organisation. Widening that schema would have meant deleting the sentence that made it what it is.

The distinction those schemas drew in prose becomes a field: `binding` records whether an answer binds the asking body or only informs it. That is queryable; a sentence in a description is not.

`subjectType` becomes a free string. It was seven values fixed by one Dutch arrangement, which is exactly the kind of vocabulary this programme moves into configuration.

Four menu entries become one. Seven pages become three. On the decision page, four widgets become two.

## Decision: the slug is `governance-consultation`

Schema slugs are global on a shared OpenRegister, and `consultation` already belongs to dossiq. Two apps declaring it would resolve to each other's definition, and the wrong one could answer.

gate-106 refused it, correctly, and the qualified slug says which of the two this is.

## Decision: PublicConsultation is left alone

It carries `marketScope`, `awardCriteria`, `estimatedValue`, `awardedTo` and `procestProcessRef`. It is a procurement schema that happens to be called a consultation. Folding it in would put award criteria on every consultation an organisation ever runs.

Splitting it is worth doing, and it is its own change.

## Decision: ConsultationRequest is not folded yet

The works-council request carries its own response inline, plus a second decision stage. Folding it is a reshape rather than a rename, so it gets its own step. Its reference to the retired member poll is repointed here so it does not dangle.

## Impact

An operator loses four menu entries and finds every consultation in one place, filterable by whether it binds. A works council that never runs a zienswijzeronde stops carrying a menu entry for one.
