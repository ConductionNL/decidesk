# Tasks: retire-vve-template-surfaces

- [x] 1.1 Add `src/manifest.d/decision-templates.json`: index + detail over `decision-template`
- [x] 1.2 Remove the `VveDecisionTemplates` and `ModelreglementPresets` pages and menu entries
- [x] 1.3 Replace both ids with `DecisionTemplates` in `menu-layout.json` `settingsSection`
- [x] 1.4 Confirm no route, spec or test still references the removed page ids
- [x] 1.5 Manifest validates; nav ceiling holds
- [x] 1.6 Verify on a live instance that the page lists templates of more than one context
- [x] 1.7 Stop the legacy-template migration duplicating every seeded built-in (28 -> 15, re-run adds none)
