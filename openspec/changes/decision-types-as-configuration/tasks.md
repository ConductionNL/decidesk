# Tasks: decision-types-as-configuration

## 1. Registry and seed

- [x] 1.1 Add `lib/Service/DecisionTypeRegistry.php`: `CONFIG_KEY`, `DEFAULT_TYPES` (today's 12 types plus `woo-decision`), `getTypes()` reading app config with seed fallback, strict `isAllowed()`.
- [x] 1.2 Add `lib/Repair/SeedDecisionTypes.php`: seeds the vocabulary once, never overwrites a stored row.
- [x] 1.3 Register the seed step in `appinfo/info.xml` under `<install>` and `<post-migration>`, and bump the app version so upgrades run it.

## 2. One authority

- [x] 2.1 `DecisionIntegrationService`: drop `ALLOWED_TYPES`, inject the registry, validate referentially, refusal message names the `decision_types` admin path.
- [x] 2.2 `decidesk_register.json`: Decision.decisionType loses its enum, description points at the registry, schema 0.10.0 to 0.11.0, register info 0.12.0 to 0.13.0.
- [x] 2.3 `decidiq_mock_register.json`: same edit, info 1.2.0 to 1.3.0.
- [x] 2.4 `register.d/68-unified-decision-templates.json`: DecisionTemplate.decisionType loses its enum, 0.2.0 to 0.3.0.

## 3. Tests

- [x] 3.1 Invert the parity test: no ALLOWED_TYPES constant, no enum in any of the three schema homes, seed covers every fleet caller type (dossiq and stackiq).
- [x] 3.2 New `DecisionTypeRegistryTest`: stored list wins in both directions, seed fallback for absent and unusable rows, malformed entries dropped, strict matching.
- [x] 3.3 New `SeedDecisionTypesTest`: seeds once, never overwrites.
- [x] 3.4 Service tests: admin-added type accepted, admin-removed type refused, `woo-decision` joins the delegated-type provider, refusal message asserted.
- [x] 3.5 Mutation-check the unknown-type refusal: inverted guard kills 11 tests, store-ignoring registry kills 5.

## 4. Follow-up (out of this change)

- [ ] 4.1 Move per-type behavioural configuration (kind grouping, lifecycle domain defaults) onto DecisionTemplate entries when the ADR-037 consumer rewrite lands.
- [ ] 4.2 Admin settings UI for the vocabulary (occ is the path today).
