# every-index-route-resolves tasks

## 1. The sweep

- [x] 1.1 Derive the index-route table from `buildManifest`, over the base manifest, the sorted `manifest.d/*.json` and the menu-layout
  **files**: tests/e2e/spec-coverage/every-index-route-resolves.spec.ts
- [x] 1.2 Navigate to each derived route and assert its manifest title renders as a heading
  **files**: tests/e2e/spec-coverage/every-index-route-resolves.spec.ts
- [x] 1.3 Assert the derived table is populated and its titles are unique, so a broken merge fails instead of running nothing
  **files**: tests/e2e/spec-coverage/every-index-route-resolves.spec.ts

## 2. Evidence

- [x] 2.1 Run the sweep against a seeded instance and record which routes fail
  **files**: openspec/changes/every-index-route-resolves/proposal.md

## 3. What the guard found

- [x] 3.1 Rename the citizen consultation surface to "Public consultations" so the two consultation entries under Decisions are distinguishable
  **files**: src/manifest.d/citizen-participation.json
