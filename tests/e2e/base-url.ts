/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ONE place that decides which Nextcloud the decidiq e2e suite talks to.
 *
 * Why this file exists
 * --------------------
 * Every spec used to compute its own target as
 *
 *     const BASE = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
 *
 * — 22 identical copies, plus a third variant in `global-setup.ts` and a fourth
 * in the root `playwright.config.ts`. Three things were wrong with that:
 *
 *  1. `BASE_URL` was ignored. That is the variable the shared
 *     `ConductionNL/.github/.github/workflows/quality.yml` job actually exports
 *     to both the "Seed test data" step and the Playwright run step. A suite
 *     that only reads `NEXTCLOUD_URL` still works on CI purely because the CI
 *     fallback happens to be the same `http://localhost:8080` — i.e. it works
 *     by accident, and would keep "working" if the workflow ever moved the
 *     runner's Nextcloud to another port.
 *  2. `PLAYWRIGHT_BASE_URL` — the variable every runbook in this programme uses
 *     to point a suite at a disposable instance — was ignored outright.
 *     Exporting it did nothing.
 *  3. The `|| 'http://localhost:8080'` default is the SHARED development
 *     container on the dev box. These specs perform real writes (the workflow
 *     layer seeds GovernanceBody / Meeting / Participant objects through the
 *     OpenRegister object API), so a suite that quietly falls back to it
 *     creates fixture governance data in other people's environment.
 *
 * So: the target must be stated explicitly. Locally there is NO default; a
 * missing variable is a hard error naming the fix, not a silent redirect onto
 * somebody else's instance.
 *
 * The one exception is CI. A GitHub runner has no shared instance — the shared
 * workflow starts a throwaway Nextcloud on the runner's own localhost:8080 via
 * `php -S 0.0.0.0:8080` — so falling back there is safe.
 *
 * ⚠️ `BASE_URL` is in the list on purpose. openconnector adopted a
 * `PLAYWRIGHT_BASE_URL`-only resolver during its own Vue 3 migration and its
 * "E2E Tests (Playwright)" job has hard-failed on every run since with
 * "Error: PLAYWRIGHT_BASE_URL is not set." Accepting the workflow's own
 * variable is what keeps a strict resolver compatible with CI.
 */

const CI_DEFAULT_BASE_URL = 'http://localhost:8080'

/**
 * Resolve the Nextcloud base URL for this run.
 *
 * @return the base URL, without a trailing slash
 * @throws when no target is configured outside CI
 */
export function resolveBaseURL(): string {
	const explicit =
		process.env.PLAYWRIGHT_BASE_URL
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
		// Exported by the shared ConductionNL/.github quality workflow.
		?? process.env.BASE_URL

	if (explicit) {
		return explicit.replace(/\/+$/, '')
	}

	if (process.env.CI || process.env.GITHUB_ACTIONS) {
		console.warn(
			'[decidiq e2e] no PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / NC_BASE_URL / BASE_URL set; '
				+ `using the CI-local default ${CI_DEFAULT_BASE_URL}.`,
		)
		return CI_DEFAULT_BASE_URL
	}

	throw new Error(
		'[decidiq e2e] No target Nextcloud configured. Set PLAYWRIGHT_BASE_URL (preferred), '
			+ 'NEXTCLOUD_URL, NC_BASE_URL or BASE_URL to the instance you want to test, e.g.\n\n'
			+ '    PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test\n\n'
			+ 'There is deliberately no default: the historic one was http://localhost:8080, '
			+ 'the SHARED development container, and this suite writes governance fixtures '
			+ "through the OpenRegister object API — which would corrupt other people's "
			+ 'environments.',
	)
}

/** The resolved base URL for this run, without a trailing slash. */
export const BASE_URL = resolveBaseURL()
