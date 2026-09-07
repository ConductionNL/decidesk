/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright config for Decidesk.
 *
 * Scaffolded by /journeydoc-init (ADR-030). Decidesk previously had
 * no Playwright setup — this is a from-scratch config with two
 * projects:
 *
 *   - `chromium`     — the default regression project. Excludes the
 *                      docs capture spec so PR pipelines don't reshoot
 *                      screenshots on every push. Add regression specs
 *                      under `tests/e2e/` and they run here.
 *   - `docs-capture` — the journeydoc screenshot capture project.
 *                      Opt-in: `npx playwright test --project docs-capture`.
 *                      Output lands in
 *                      `docs/static/screenshots/tutorials/{user,admin}/`.
 *
 * Point at a running Nextcloud with NEXTCLOUD_URL (default
 * http://localhost:8080). `globalSetup` logs in once (admin/admin by
 * default; override with NC_ADMIN_USER / NC_ADMIN_PASS) and persists
 * the session to `tests/e2e/.auth/admin.json`; every spec reuses it via
 * `use.storageState`.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from './tests/e2e/base-url.ts'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	timeout: 30_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so the budget here has to stay clear of
	// 45m by that overhead plus room to write the report.
	//
	// 38m stopped being enough on 2026-09-05. every-index-route-resolves added
	// 39 navigations at ~20s each, and the arithmetic since:
	//
	//   development 6b564ca2  31.8 min  205 passed, completed with ~6 min spare
	//   development 59376666  TIMED OUT at 38m, 200 passed, 47 skipped,
	//                         "5 did not run" — NOTHING FAILED, it ran out of
	//                         time, and a timeout reads in `gh pr checks`
	//                         exactly like a broken test
	//
	// Those 5 are ~2 min, so a slow run needs ~40. 41m leaves ~1.1 min under
	// the cap after the 2.4 min of setup, which is thin on purpose: it is the
	// ceiling this repo can reach alone, because `timeout-minutes: 45` is
	// hardcoded in ConductionNL/.github with no input to override it.
	//
	// 🔴 THIS BUYS HEADROOM, IT DOES NOT FIX THE SHAPE. decidiq is now the
	// fleet outlier: quality.yml sized that 45 against runs of 4-10 min
	// (planix 0.8, doriath 4.2, openconnector 7.7, opencatalogi 10.0). If this
	// times out again, the two real options are deduplicating the sweep — 20
	// of its 38 routes are already navigated by another spec, worth ~6.7 min —
	// or raising the cap in .github. Measured and rejected as fixes: reusing
	// one page across routes saves 8% (the cost is the app's own boot, not the
	// browser context), and in-app router navigation is not reachable from the
	// DOM.
	globalTimeout: 41 * 60_000,
	reporter: [
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
		['list'],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		// 🔴 RESOLVED BY base-url.ts, NEVER INLINE HERE.
		//
		// This line used to read `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`,
		// which is a SECOND resolution disagreeing with the guard in
		// tests/e2e/base-url.ts. That guard accepts PLAYWRIGHT_BASE_URL
		// (its own documented preference), so setting only that variable
		// satisfied it, no error was raised, and this line still resolved to
		// the SHARED dev instance.
		//
		// Measured 2026-09-04: a full run launched with
		// PLAYWRIGHT_BASE_URL=http://localhost:8710 against a private throwaway
		// container logged in to, and wrote governance fixtures into,
		// localhost:8080 instead. The private instance was never contacted.
		// The guard's whole purpose is to stop exactly that, and a hardcoded
		// fallback beside it made the guard decorative.
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		// `on-first-retry` captures nothing on the first attempt, so a failure that
		// a retry then fixes is the ONLY one that gets a trace — exactly inverted.
		// `retain-on-failure` records every failing test and discards the passing
		// ones. Strictly more evidence; no assertion is relaxed. (The CI config in
		// tests/e2e/playwright.config.ts already does this; this keeps local runs
		// and `--project visual` / `docs-capture` consistent with it.)
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts', '**/visual/**'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Documentation capture project (ADR-030 / journeydoc). Opt-in:
		//   npx playwright test --project docs-capture
		// Output lands in `docs/static/screenshots/tutorials/{user,admin}/`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 90_000,
		},
		// Visual-regression project (GAP-5). Opt-in / non-gating:
		//   npx playwright test --project visual
		//   npx playwright test --project visual --update-snapshots  (rebaseline)
		// Fixed viewport + authenticated session => deterministic shots.
		// Baselines live in tests/e2e/visual/*-snapshots/ and ARE committed.
		// PLATFORM CAVEAT: PNG baselines are host-font/GPU specific, so a CI
		// Linux runner will not byte-match a dev-container baseline; the visual
		// project must regenerate its baselines in-CI before it can gate.
		{
			name: 'visual',
			testMatch: /visual\/.*\.visual\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
				storageState: 'tests/e2e/.auth/admin.json',
			},
			timeout: 90_000,
		},
	],
})
