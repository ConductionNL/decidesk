---
kind: code
---

# Proposal: decision-types-as-configuration

## Summary

A decision type becomes configuration on the existing decision abstraction, not code. The closed `decisionType` vocabulary today lives in four homes: the `DecisionIntegrationService::ALLOWED_TYPES` constant, the Decision enum in `decidesk_register.json`, its copy in `decidiq_mock_register.json`, and the DecisionTemplate narrowing in `register.d/68-unified-decision-templates.json`. A parity test pins the four together. Adding one type costs a release touching all four.

This change replaces the four homes with one authority: the `decision_types` app-config value. A repair step seeds it on install with today's vocabulary, including `advice`, `bezwaar-decision` and `woo-decision`. The integration hub validates referentially against the stored list and fails closed on an unknown type. The refusal message names the fix: an administrator adds the type, no release.

## Motivation

The closed vocabulary is how fleet needs stall. dossiq's `advice` waited on a decidiq release. The pending `woo-decision` need waits on one now. Each addition is a four-file mechanical edit plus a release, for what is domain configuration.

ADR-037 (`configurable-types-domain-model`) already names the disease: variability that belongs to an organisation's configuration is frozen into hardcoded enums. It moved the rich per-organisation type layer (DecisionTemplate as a live type object) but left the string vocabulary itself closed. This change applies the same ruling one level down: the vocabulary is data.

## Affected Projects

- [x] Project: `decidiq`. Integration hub validation, register schema declarations, seed repair step, parity test inversion.

## Scope

### In scope

- A `DecisionTypeRegistry` service reading the `decision_types` app-config array, with the shipped list as seed and bootstrap fallback.
- A `SeedDecisionTypes` repair step writing the seed once, registered in `info.xml` under `<install>` and `<post-migration>`. It never overwrites a stored vocabulary.
- `DecisionIntegrationService` validates against the stored types. Fail closed on an unknown type. The message names the admin path.
- The three schema homes drop their `decisionType` enum and become free text with referential validation. An enum that drifts from the store recreates the four-homes problem, so no enum is generated either.
- The parity test inverts: it proves there is exactly one authority and that the seed covers every type a fleet caller sends (dossiq: `contract-renewal`, `report-adoption`, `advice`, `bezwaar-decision`, `woo-decision`; stackiq: `contract`, `contract-renewal`).
- `woo-decision` joins the seed as data. No enum literal is added anywhere.

### Out of scope

- Per-type behavioural configuration (motion and amendment service branching, lifecycle transitioners, the `decisionLink.js` kind grouping). That is the ADR-037 programme; see design.md for the named follow-up.
- An admin settings UI for the vocabulary. The occ path works today; a UI is a follow-up.
- Migrating stored decisions. Every stored type string stays in the seeded vocabulary, so nothing changes for existing data.

## Approach

Config-first. The registry and seed land with the schema edits in one change, and the seed ships today's list, so no caller breaks. Validation stays at the write path of the integration hub, where it already was.

## New Dependencies

None.

## Risks

- The Decision schema no longer enum-validates `decisionType` on direct OpenRegister object writes. Accepted: the enum never guarded the hub path (the constant did), the transition guards ignore unknown types, and the alternative (a generated enum) reintroduces drift between store and schema. Documented on the schema declaration itself.
- A vocabulary an admin empties would refuse everything. The registry falls back to the seed when the stored row holds nothing usable.
