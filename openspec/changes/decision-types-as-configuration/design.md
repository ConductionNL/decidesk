# Design: decision-types-as-configuration

## D1. The authority is app config, not a register schema

Two shapes were on the table: a small `decision-type` register schema seeded with rows, or an app-config registry. App config wins, for three reasons rooted in what the register pattern here actually supports.

1. **The seed-profiles ruling.** `register.d` fragments declare schemas only; installing this app plants zero objects. Seed objects live in operator-picked profiles. A vocabulary held in register rows would be empty on a fresh install that picked no profile, the hub would fail closed on every type, and the decidiq leg of the dossiq case flow would die silently. Bridging that with a code fallback next to the rows creates a second authority, which is the four-homes disease again.
2. **It is configuration, not domain data.** The vocabulary has no per-organisation instances, no lifecycle, no relations. The rich per-organisation type layer already exists: ADR-037 promoted DecisionTemplate to a live type object that `Decision.type` references by uuid. The string `decisionType` is the coarse category the fleet contract sends, which is exactly what an app setting holds.
3. **Precedent.** `voter_token_secret` and the process settings already live in `IAppConfig` under `Application::APP_ID`, migrated by `MigrateAppConfigKeys`, editable with `occ config:app:set`.

## D2. `DEFAULT_TYPES` is a seed, not a second authority

The registry carries the shipped list once, as `DecisionTypeRegistry::DEFAULT_TYPES`. It is consulted in exactly two places: the `SeedDecisionTypes` repair step writes it into the store once, and `getTypes()` falls back to it while the stored row is absent or unusable. After the first seed the store answers alone, in both directions: a type the admin added is accepted, a type the admin removed is refused, and the seed never grows back on upgrade. The parity test pins that the seed covers every fleet caller, so the bootstrap window is safe too.

## D3. The schema enums drop, and none is generated

The three declarations (`Decision` twice, `DecisionTemplate` once) keep `decisionType` as a plain string. Generating an enum from the store was considered and rejected: the JSON files are install-time input, the store is runtime state, and any generated copy can drift between imports. One authority means the schema declares the shape and the registry owns the values. The cost is that a raw write straight to OpenRegister's object API is no longer enum-checked. That path already bypasses the chair, quorum and terminal-completeness gates (documented on the schema in `x-decidesk-terminal-completeness`), and the hub path it never guarded keeps its check.

## D4. Refusal names the fix

`createDecision` still fails closed on an unknown type. The message now tells the caller what unblocks them: an administrator adds the type to the `decision_types` app setting. No release is needed. That sentence is the point of the change.

## D5. Per-type behaviour stays put, as a named follow-up

The brief was to move hardcoded per-type behaviour into the type entries where small. What exists is not small: `MotionService`, `MotionAmendmentService`, `AmendmentOrderService`, the lifecycle transitioners, `SubmissionDeadlineListener`, `PublicationEligibilityService` and `decisionLink.js` all branch on specific types (`motion`, `amendment`, `resolution`, `appointment`). That branching is behavioural code for the core parliamentary types, and moving it is the ADR-037 `configurable-types-domain-model` programme (its DecisionTemplate already carries stateMachine, votingRule and competence per type). Folding it into this change would couple a vocabulary fix to a domain-model rewrite. Follow-up: when ADR-037's consumer rewrite lands, richer per-type configuration (kind grouping for the integrations UI, default lifecycle domain) can move onto DecisionTemplate entries; the string registry stays the validity authority.

## D6. Version bumps

- `Decision` schema 0.10.0 to 0.11.0 in both registers, register info 0.12.0 to 0.13.0, mock info 1.2.0 to 1.3.0, `DecisionTemplate` 0.2.0 to 0.3.0. The fragment signature re-imports on any fragment change; the bumps keep the version history honest.
- App version bumped so `occ upgrade` runs the seed on existing instances. Until it runs, the registry fallback keeps them working; the bump only makes the row visible for editing.
