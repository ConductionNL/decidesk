# vve-alv-pack Specification

@e2e exclude a schema-declaration record with no page of its own: `vve-alv-pack` is still `Status: planned`, and no screen reads `VveDecisionTemplate`, `ModelreglementPreset` or `VveConfiguration`. Their only PHP readers are one-shot migrations under lib/Migration, and those are covered: MigrateLegacyTemplatesToDecisionTemplateTest::testRunMapsVveDecisionTemplateFields for the template port and MigrateVveToBodyConfigurationTest::testRunMapsEveryFactOntoItsGenericName for the configuration. No test pins the seed values.

## Purpose
Delta for the unified-decision-templates change: records that
`VveDecisionTemplate` and `ModelreglementPreset` (REQ-VVE-001) are superseded
by the `process-configuration` capability's unified `DecisionTemplate`
schema, non-destructively. `VveConfiguration` and `KascommissieVerklaring`
(the rest of REQ-VVE-001) are unaffected in shape; only
`VveConfiguration.modelRegulation` is retargeted from a
`ModelreglementPreset` reference to a plain version enum, since the schema it
referenced is superseded. Note (2026-08-19, decidiq `refactor/back-to-six`
programme): as of this delta, `vve-alv-pack` remains `Status: planned` and
unarchived, and grep confirms `VveDecisionTemplate`, `ModelreglementPreset`,
`VveConfiguration` have zero PHP/Vue consumers today — this delta is a
schema-declaration record, not a behavioural change to a live consumer.

## Requirements

### Requirement: REQ-VVE-010 VveDecisionTemplate and ModelreglementPreset superseded by DecisionTemplate

`VveDecisionTemplate` and `ModelreglementPreset` (defined by REQ-VVE-001)
SHALL be marked superseded: `x-openregister.active` SHALL be set to `false`
via the `process-configuration` capability's
`68-unified-decision-templates.json` fragment, with each schema's
`description` naming `decision-template` (`context=association`) as the
successor. The six `VveDecisionTemplate` built-in seeds (`decharge-bestuur`,
`vaststelling-jaarrekening`, `dotatie-reservefonds`, `vaststelling-mjop`,
`machtiging-boven-drempel`, `wijziging-huishoudelijk-reglement`) SHALL be
re-seeded as built-in `DecisionTemplate` objects with `context=association`,
`decisionType=resolution`, and `templateCategory` set to their former
`decisionCategory` value, carrying `proposedText` and `regulationSource`
forward unchanged. The `ModelreglementPreset` 2017 version's `categoryRules`
SHALL be folded into the corresponding `DecisionTemplate` seeds'
`votingRule.voteThreshold` and `quorumRule` as the shipped default (current
law); the 1992/2006 category rules remain documented in this requirement's
history and in `regulationSource` for a future re-seed if a live VvE on an
older modelreglement needs an explicit template row before the ALV pack
ships (today the mechanism for that deviation is
`VveConfiguration.majorityOverrides[]`, unchanged). No `VveDecisionTemplate`
or `ModelreglementPreset` object is deleted; both schemas and their existing
objects remain readable, matching the non-destructive posture of the
`process-configuration` delta.

#### Scenario: The six built-in VvE templates exist as DecisionTemplate objects

- **GIVEN** the `68-unified-decision-templates.json` fragment is loaded
- **WHEN** the `decision-template` schema's built-in objects are listed for
  `context=association`
- **THEN** six objects exist with `decisionType=resolution` and
  `templateCategory` in `{discharge, annual-accounts,
  reserve-fund-contribution, mjop-adoption, authorisation-above-threshold,
  amendment-internal-regulations}`
- **AND** each carries the same `proposedText` and `regulationSource` its
  source `VveDecisionTemplate` object carried

#### Scenario: VveDecisionTemplate and ModelreglementPreset remain readable but inactive

- **GIVEN** the fragment is loaded
- **WHEN** `vve-decision-template` and `modelreglement-preset` schemas are
  inspected
- **THEN** `x-openregister.active` is `false` on both, their existing
  objects remain readable, and no object is deleted

### Requirement: REQ-VVE-011 VveConfiguration.modelRegulation retargeted to a version enum

`VveConfiguration.modelRegulation` SHALL change from a `$ref` to
`ModelreglementPreset` to a plain string enum `modelReglementVersion`
(`1992`, `2006`, `2017`), since the schema it referenced is superseded and
`VveConfiguration` has zero consumers today (a safe, additive-shaped
retarget). `VveConfiguration.fractionDenominator`,
`deedOfDivisionDocument`, and `majorityOverrides[]` (with
`majorityOverrides[].decisionCategory` renamed to `templateCategory` for
consistency, no value-set change) are unaffected in shape.

#### Scenario: VveConfiguration seed carries the plain version enum

- **GIVEN** a VveConfiguration object seeded by this delta
  (`vve-parkstaete-configuratie`)
- **WHEN** it is loaded after this delta
- **THEN** `modelReglementVersion` reads `"2017"` (the plain-enum successor
  to the prior `modelRegulation: "modelreglement-2017"` slug reference) and
  `majorityOverrides[0].templateCategory` reads
  `"amendment-internal-regulations"`
- **AND** the pre-existing `vve-zeewaarts-configuratie` object is left
  untouched, still carrying only the legacy `modelRegulation` /
  `decisionCategory` shape — OpenRegister's seed import is CREATE-ONLY, so a
  patch to an already-imported object is inert on any live instance;
  demonstrating the new fields on a NEW object is the only honest proof on a
  fresh install, and migrating existing rows is the repair step's job, not
  the seed's
