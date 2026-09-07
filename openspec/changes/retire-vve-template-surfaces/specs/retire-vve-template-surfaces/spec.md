# retire-vve-template-surfaces Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [retire-vve-template-surfaces](../../changes/retire-vve-template-surfaces/)

## Purpose

One settings surface for every decision template the app holds, whatever kind of organisation it belongs to, replacing two pages that addressed superseded schemas.

## ADDED Requirements

### Requirement: Decision templates are reachable

The app SHALL provide an index page over the `decision-template` schema, listing every template regardless of `context`, and a detail page for one template.

The index SHALL show `context` so the reader can tell an association template from a municipal one, and `builtIn` so a read-only-but-duplicable template is distinguishable from one the operator authored.

#### Scenario: Every template is listed

- **WHEN** an operator opens Decision templates from the gear
- **THEN** templates of every context are listed, not only `association`

#### Scenario: A template can be inspected

- **WHEN** the operator opens one template
- **THEN** its voting rule, quorum rule, proposed text and cited regulation are shown

### Requirement: A superseded schema keeps no settings surface

The app SHALL NOT ship a settings page whose `config.schema` names a schema declared `x-openregister.active: false`.

`vve-decision-template` and `modelreglement-preset` are both superseded by `unified-decision-templates`; their pages and menu entries SHALL be removed.

Their rows SHALL NOT be deleted and their schema definitions SHALL remain declared, per the non-destructive supersession the earlier change chose.

#### Scenario: The gear offers no retired surface

- **WHEN** the settings menu is rendered
- **THEN** it offers no entry backed by a schema OpenRegister reports inactive

#### Scenario: Superseded data survives

- **WHEN** the pages are removed
- **THEN** the `vve-decision-template` and `modelreglement-preset` rows still exist
