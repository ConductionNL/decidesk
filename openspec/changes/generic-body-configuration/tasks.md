# Tasks: generic-body-configuration

- [x] 1.1 Declare `BodyGovernanceConfiguration` in `74-generic-body-configuration.json`
- [x] 1.2 Supersede `VveConfiguration` non-destructively (`active:false`, rows kept)
- [x] 1.3 `MigrateVveToBodyConfiguration`, idempotent by governance body
- [x] 1.4 Register the repair step in `<post-migration>`
- [x] 1.5 Retarget the settings surface and the gear entry
- [x] 1.6 Move the example-set seeds onto the generic schema; drop the orphaned one
- [x] 1.7 Unit tests for the migration (10, three of them regressions for defects found live)
- [x] 1.8 Verify on a live instance: 2 migrated, re-run adds none, sources untouched
