# Tasks: seed-profiles

## 1. Split the shipped seeds into example sets

- [x] 1.1 Build the seed reference graph and classify all 334 objects by the governance body they reach
- [x] 1.2 Close each set over outbound references so no reference dangles
- [x] 1.3 Write `lib/Settings/profiles/{municipality,association,corporate,works-council}.json`
- [x] 1.4 Strip `x-openregister.seedData` from `decidesk_register.json` and all 26 `register.d` fragments
- [x] 1.5 Verify the round trip: every object present, no content changed, nothing but `seedData` removed

## 2. Fix the defects validation surfaced

- [x] 2.1 Re-key the three `regulation` seeds onto `regeling`, the app's own schema slug
- [x] 2.2 Declare `Regeling.status`, which was required and lifecycle-driven but had no property and no column
- [x] 2.3 Correct six invalid enum values on the pub-quiz `decision-stage` seeds
- [x] 2.4 Add the required `domain` to two governance bodies
- [x] 2.5 Author a real works-council set, replacing seeds that described a city council

## 3. Serve and import the sets

- [x] 3.1 `SeedProfileService`: list from disk, resolve by declared id, import one, refuse traversal
- [x] 3.2 `SetupController`: report both steps and the set list; add `saveConfig`; dispatch `load-example-set`
- [x] 3.3 Route `POST /api/setup/config`
- [x] 3.4 Manifest: a `choice` step and a `run-action` step, with `none` and the ADR-111 generated set as options

## 4. Prove it

- [x] 4.1 Unit tests for the service, including traversal and the manifest-drift guard
- [x] 4.2 Retarget the register seed assertions at the profiles; invert the `@self` rule
- [x] 4.3 Rewrite the setup e2e spec for the choice-then-load contract
- [x] 4.4 Verify on a live instance: 45 objects imported, register row byte-identical, re-run adds nothing
