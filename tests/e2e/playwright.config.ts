/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite (28 spec files). This is the one CI
 *                  wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). Re-shoots every
 *                  tutorial screenshot; the dedicated `Journeydoc Capture`
 *                  job runs it explicitly with `--project docs-capture`.
 *   visual       — pixel-diff baselines. The root config's own header says the
 *                  PNGs are host-font/GPU specific and "a CI Linux runner will
 *                  not byte-match a dev-container baseline".
 *
 * Letting the root config be picked therefore runs two projects that are
 * documented as unable to pass on a CI runner, on top of the one that can.
 * Rather than delete or weaken them, `playwright-test-path: tests/e2e` in the
 * caller makes the workflow's FIRST lookup hit this file, which declares only
 * the regression project. The root config is untouched and stays the entry
 * point for local runs, `npm run test:e2e:docs` and `--project visual`.
 *
 * The report/output paths also differ deliberately: the workflow uploads
 * `server/apps/decidiq/playwright-report/` and
 * `server/apps/decidiq/test-results/`, so on CI the artifacts must land at
 * the app root, not under `tests/e2e/`. With the root config's paths the
 * "Upload Playwright report" step matches nothing and silently uploads an
 * empty artifact (`if-no-files-found: ignore`) — a failing run with no report
 * to read.
 *
 * TARGET RESOLUTION
 * -----------------
 * The workflow's "Run Playwright tests" step exports BASE_URL, NEXTCLOUD_URL
 * and NC_BASE_URL (all `http://localhost:8080`, its own `php -S`). The specs
 * themselves resolve `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`,
 * so the order below keeps `baseURL` identical to what the specs compute — a
 * mismatch between the two would send `page.goto()` and `page.request` to
 * different hosts.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'

const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),

	// ── The time budget, and why it is what it is ────────────────────────────
	// The shared workflow caps this job at `timeout-minutes: 45`, and a job the
	// runner kills at its cap reports **cancelled** — which is NO VERDICT. It is
	// not a pass and it is not a failure; the PR check simply carries no
	// information. That is the exact failure mode this whole change exists to
	// remove, so the suite has to be sized to finish, and to fail honestly if it
	// cannot.
	//
	// Measured, run 31022933529 (171 collected, killed at the cap having reached
	// 141): every test that PASSED finished in ≤ 7.6s — the slowest pass in the
	// entire suite. The failures cluster at exactly two values, 18s (a 15s
	// `expect` cap plus page load) and 30s (the per-test cap), 54 of them at 30s.
	// So the caps were not protecting slow-but-healthy tests; they were the price
	// of each failure, paid 90 times.
	//
	// Every lever below makes the suite STRICTER, never laxer:
	//
	//   timeout       — 20s. 2.6× the slowest observed pass, and the run before
	//                   this one used 30s (the first version of this file copied
	//                   60s from opencatalogi's, doubling the cost of every
	//                   failure for no stated reason).
	//   expect        — 10s, still 1.3× the slowest whole test.
	//   retries       — 0. A retry only ever converts red to green, so removing
	//                   it cannot hide a failure; it stops the suite paying
	//                   twice for each genuine one. Restore
	//                   `process.env.CI ? 1 : 0` once the remaining cost is
	//                   flake rather than failure.
	//   globalTimeout — 41 minutes, inside the job's 45. This is the one that
	//                   guarantees a verdict: when Playwright hits it, it stops
	//                   and exits NON-ZERO with a real summary (passed / failed /
	//                   did not run). A red with a tally is a measurement; a
	//                   cancelled job is not.
	//
	//                   🔴 RAISE IT **HERE**, NOT IN THE ROOT CONFIG. #1199
	//                   raised the root `playwright.config.ts` to 41 and the
	//                   next run still stopped at 2280s, because CI runs THIS
	//                   file: the shared workflow looks for
	//                   `${playwright-test-path}/playwright.config.ts` first and
	//                   only falls back to the repo root, and this repo ships
	//                   this file precisely so that lookup hits it. Both configs
	//                   are deliberate — the root one is what `npm run test:e2e`
	//                   uses locally, with all three projects — so the trap is
	//                   not a duplicate to delete, it is two files that must be
	//                   told apart.
	//
	//                   The budget is arithmetic, not taste. The job cap is 45
	//                   and is hard-coded in the shared workflow, not an input.
	//                   Measured on run 33991029428: 2m25s of setup before
	//                   Playwright starts and ~10s of uploads after, so 41
	//                   leaves the suite the most it can have and still report.
	//
	// `workers` stays at 1 deliberately. Raising it would halve the wall clock,
	// but these specs seed and delete objects in one shared OpenRegister
	// register — running spec files concurrently against a single instance
	// fabricates failures that belong to the parallelism, not the code.
	timeout: 20_000,
	expect: { timeout: 10_000 },
	globalTimeout: 41 * 60_000,
	fullyParallel: false,
	retries: 0,
	workers: 1,

	// The `github` reporter is what makes a killed run legible. `list` prints
	// its failure bodies in an end-of-run summary, so when the job is cut off
	// at the 45-minute cap the log shows a column of ✘ marks and NOT ONE error
	// message — the failures are invisible precisely when you most need them.
	// `github` emits a ::error:: annotation per failure AS IT HAPPENS, with the
	// message and the source location.
	reporter: process.env.CI
		? [
				['github'],
				['list'],
				[
					'html',
					{
						open: 'never',
						outputFolder: path.join(APP_ROOT, 'playwright-report'),
					},
				],
			]
		: [
				[
					'html',
					{
						open: 'never',
						outputFolder: path.join(APP_ROOT, 'playwright-report'),
					},
				],
				['list'],
			],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		// Single source of truth — see tests/e2e/base-url.ts. The specs import
		// the same resolver, so `page.goto()` (which uses this baseURL) and the
		// specs' own `page.request` calls can never address different hosts.
		baseURL: BASE_URL,
		// Written by global-setup.ts after the admin login.
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),

		// `on-first-retry` was self-defeating next to `retries: 0`: there is no
		// first retry, so it captured NOTHING — the uploaded trace artifact was
		// empty on every run and each failure had to be diagnosed from its one
		// -line message. `retain-on-failure` records every failing test and
		// discards the passing ones, so the artifact is exactly the evidence a
		// red run needs. Strictly more evidence, not a relaxed assertion.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts', '**/visual/**'],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
