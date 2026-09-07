# Spec Delta: decidesk-contract-decision-hub — decision types as configuration

The `decisionType` vocabulary moves out of code and schema enums into one stored authority: the `decision_types` app setting. Adding a decision type becomes an admin action, never a release.

## ADDED Requirements

### Requirement: REQ-DCDH-009 — The decisionType vocabulary is configuration with one authority

The system SHALL hold the valid `decisionType` vocabulary as data in the `decision_types` app-config value, seeded on install with the shipped list (which SHALL include every type a fleet caller sends: `contract`, `contract-renewal`, `report-adoption`, `advice`, `bezwaar-decision`, `woo-decision`). The seed step SHALL be idempotent and SHALL never overwrite a stored vocabulary. `DecisionIntegrationService` SHALL validate `decisionType` referentially against the stored vocabulary and SHALL fail closed on an unknown type, with a message naming the admin path that adds one. No other home for the vocabulary SHALL exist: neither a PHP constant nor a schema `enum`, generated or hand-written. The `decisionType` schema declarations SHALL remain free-text strings.

#### Scenario: An administrator adds a decision type without a release

@e2e exclude admin config plus API contract, not a UI flow: covered by PHPUnit on DecisionTypeRegistry and DecisionIntegrationService (testCreateDecisionAcceptsAdminAddedDecisionType)
- GIVEN the stored `decision_types` vocabulary contains `subsidy-award`
- WHEN a fleet app raises a Decision with `decisionType=subsidy-award`
- THEN the Decision is created
- AND no code or schema change was involved

#### Scenario: An unknown decision type is refused with the fix named

@e2e exclude API refusal contract, not a UI flow: covered by PHPUnit (testCreateDecisionRejectsUnknownDecisionType)
- GIVEN `totally-made-up` is not in the stored vocabulary
- WHEN a caller raises a Decision with `decisionType=totally-made-up`
- THEN the create is refused
- AND the message names the unknown type and the `decision_types` app setting an administrator can extend

#### Scenario: The seed never overwrites an edited vocabulary

@e2e exclude repair-step contract, not a UI flow: covered by PHPUnit (SeedDecisionTypesTest)
- GIVEN an administrator removed `resolution` from the stored vocabulary
- WHEN the app upgrades and the seed step runs again
- THEN the stored vocabulary is unchanged
- AND a Decision with `decisionType=resolution` is refused

#### Scenario: A fresh install accepts the shipped vocabulary before the seed runs

@e2e exclude bootstrap-window contract, not a UI flow: covered by PHPUnit (DecisionTypeRegistryTest fallback tests)
- GIVEN no `decision_types` row is stored yet
- WHEN a fleet app raises a Decision with any shipped type, `woo-decision` included
- THEN the Decision is created from the seed fallback

#### Scenario: Exactly one authority exists

@e2e exclude static structure contract: covered by PHPUnit (testDecisionTypeVocabularyHasExactlyOneAuthority)
- GIVEN the shipped code and register declarations
- WHEN the former vocabulary homes are inspected
- THEN `DecisionIntegrationService` declares no ALLOWED_TYPES constant
- AND no `decisionType` declaration in `decidesk_register.json`, `decidiq_mock_register.json` or `register.d/68-unified-decision-templates.json` carries an `enum`

## MODIFIED Requirements

### Requirement: REQ-DCDH-001 — Subject reference and provenance on a Decision

The system SHALL extend the existing `Decision` schema with additive, nullable properties that link a
Decision back to the originating object in a consuming app, without breaking existing Decisions:
`sourceApp` (the app that raised it), `subjectRegister` + `subjectSchema` + `subjectId` (the
OpenRegister coordinates of the originating object), `subjectLabel` (human display label),
`outcomeCallbackUrl` (optional registry callback target), and `externalReference` (the consumer's own
reference for idempotency/linking). The `decisionType` vocabulary is configuration per REQ-DCDH-009:
the schema declares a free-text string and the stored `decision_types` vocabulary decides validity, so
a consumer's new type needs no schema change. No new Decision/approval/signing entity SHALL be
introduced (ADR-005/006); these are schema metadata only (ADR-031), fragment-located per ADR-037, with
no required field added so existing decisions stay valid.

#### Scenario: A finance app raises a contract decision with a subject reference

@e2e exclude API contract, not a UI flow: covered by PHPUnit on DecisionIntegrationService and Newman against the integration endpoint (pre-existing scenario, unchanged behaviour)
- GIVEN shillinq holds a `Contract` object that requires board approval
- WHEN shillinq raises a decidiq Decision with `decisionType=contract`, `sourceApp=shillinq`,
  `subjectRegister=shillinq`, `subjectSchema=Contract`, `subjectId=<uuid>`, and a `subjectLabel`
- THEN the Decision is stored with those provenance fields populated
- AND the decidiq Decision detail shows a read-only "raised by" provenance block resolving to that
  originating object

#### Scenario: Existing decisions remain valid after the additive delta

@e2e exclude schema-migration contract, not a UI flow: covered by PHPUnit on the register JSON (pre-existing scenario, unchanged behaviour)
- GIVEN Decisions created before this change with none of the new fields set
- WHEN the updated `Decision` schema version is imported
- THEN those Decisions validate unchanged (all new fields are nullable, no required field added)

#### Scenario: Report sign-off uses report-adoption

@e2e exclude API contract, not a UI flow: covered by PHPUnit (the seeded vocabulary carries report-adoption)
- GIVEN a consumer raises a Decision to adopt/sign off a report (e.g. an ACM report)
- WHEN it sets `decisionType=report-adoption`
- THEN the value is accepted by the configured vocabulary and the Decision behaves as any other typed Decision
