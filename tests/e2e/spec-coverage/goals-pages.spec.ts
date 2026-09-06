/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — the Goals index and detail pages
 * (src/manifest.d/organisation-goals.json), declared entirely via the
 * generic `data`/`related` widgets with no bespoke Vue component. This
 * surface had no e2e coverage at all.
 *
 * Read-only: the five seeded `goal` objects
 * (lib/Settings/register.d/66-organisation-goals.json) already cover the
 * index listing and one is used for the detail page — no fixtures are
 * created, so no cleanup is needed.
 *
 * @e2e openspec/specs/organisation-goals/spec.md#scenario-goals-pages-render-without-new-vue-components
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`

const SEEDED_GOAL_TITLES = [
	'Duurzame omzetgroei 2028',
	'Operationele effectiviteit 2026',
	'Amsterdam klimaatneutraal',
	'Herzien parkeerbeleid vastgesteld',
	'Digitale dienstverlening leden',
]

/** Dismiss the cn-support-dialog if it auto-opened and is intercepting clicks. */
async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page
		.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]')
		.first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

/**
 * Navigate to a decidiq page, preferring the APP's left navigation entry
 * (app-scoped) when it exists. `menu-layout.json` folds the "Goals" nav
 * entry into the "ActionItems" (Tasks & Commitments) cluster, so
 * `cn-nav-entry-Goals` is not expected to exist as a top-level entry — the
 * fallback to the app-scoped route is the normal path here, not an error
 * case. `route` is the app-scoped path used when `cn-nav-entry-<entryId>`
 * is absent.
 */
async function appNavClick(
	page: Page,
	entryId: string,
	route: string,
): Promise<void> {
	await page.goto(`${BASE}/apps/decidiq/`)
	// app-root appearing only proves the shell mounted, not that data has
	// arrived — mount itself blocks on initializeStores()'s settings round
	// trip, so 30s (double the old budget) before even the shell shows up.
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })
	await dismissSupportDialog(page)
	const entry = page.locator(`[data-testid="cn-nav-entry-${entryId}"]`).first()
	if (await entry.isVisible().catch(() => false)) {
		await entry.click()
		return
	}
	await page.goto(`${BASE}/apps/decidiq${route}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })
	await dismissSupportDialog(page)
}

/** GET a collection through the authenticated page session (read-only). */
async function listObjects(page: Page, schema: string): Promise<any[]> {
	const resp = await page.request.get(`${OR}/${schema}?_limit=200`, {
		headers: { Accept: 'application/json' },
	})
	if (!resp.ok()) return []
	const body = await resp.json()
	return body.results ?? body.items ?? []
}

// @e2e openspec/specs/organisation-goals/spec.md#scenario-goals-pages-render-without-new-vue-components
test('Goals: index lists all five seeded goals', async ({ page }) => {
	// The Goals index needs several seconds past mount before its rows
	// render (an object-list query on top of the pre-mount
	// initializeStores() settings round trip every navigation blocks on) —
	// the 30s global test timeout is nowhere near enough.
	test.setTimeout(120_000)
	await appNavClick(page, 'Goals', '/goals')

	await expect(page).toHaveURL(/\/apps\/decidiq\/.*goals/, { timeout: 45_000 })
	await expect(page.getByTestId('cn-index-page').first()).toBeVisible({
		timeout: 45_000,
	})

	// The index container appearing does not mean its rows have arrived —
	// give each seeded row real headroom instead of the 10s expect default.
	//
	// 🔴 `.first()` IS LOAD-BEARING, AND IT IS NOT PAPERING OVER A BUG.
	// `example-set-setup-step.spec.ts` runs earlier in the same worker against
	// the same instance and, to prove a multi-set pick lands, actually IMPORTS
	// the `works-council` and `association` example sets. `association.json`
	// ships exactly one Goal, and its title is verbatim the last entry in
	// SEEDED_GOAL_TITLES — the fixture copies the profiles on purpose, so the
	// two cannot drift into disagreeing about what a Goal looks like.
	//
	// So on a full run this page legitimately holds TWO rows titled 'Digitale
	// dienstverlening leden': the one ci-seed.sh created, and the one that
	// import planted. Two goals may share a title; they belong to different
	// bodies. Without `.first()` Playwright's strict mode turns that into
	// "resolved to 2 elements" and fails a test whose actual claim is "this
	// seeded goal is listed" — which is true, twice over.
	//
	// Measured: development push run on 6eca4ec7, 204 passed / 1 failed, the
	// only failure. It was invisible until then because every earlier push run
	// died during setup before Playwright started, so the suite reported
	// failure without ever having executed a spec.
	for (const title of SEEDED_GOAL_TITLES) {
		await expect(page.getByText(title, { exact: true }).first()).toBeVisible({
			timeout: 45_000,
		})
	}
})

// @e2e openspec/specs/organisation-goals/spec.md#scenario-goals-pages-render-without-new-vue-components
test('Goals: detail page renders a seeded goal via the generic data/related widgets', async ({
	page,
}) => {
	// Composed detail pages in this app declare 16-18 widgets, each an
	// object-list query costing ~1s on this instance, on top of the
	// pre-mount initializeStores() settings round trip every navigation
	// blocks on — the 30s global test timeout is nowhere near enough.
	test.setTimeout(120_000)

	const goals = await listObjects(page, 'goal')
	const target = goals.find((g) => g.title === 'Amsterdam klimaatneutraal')
	test.skip(!target, 'Seed goal "Amsterdam klimaatneutraal" not found')
	const goalId = target.id ?? target['@self']?.id
	test.skip(!goalId, 'Seed goal has no id')

	await page.goto(`${BASE}/apps/decidiq/goals/${goalId}`)
	// app-root appearing only proves the shell mounted, not that data has
	// arrived — mount itself blocks on initializeStores()'s settings round
	// trip, so 30s (double the old budget) before even the shell shows up.
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

	// Give content assertions real headroom instead of the 10s expect default.
	// The `goal-data` widget is declared with title "Goal" (manifest.d/
	// organisation-goals.json:62), but a `type: "data"` widget's card heading
	// is NOT the manifest title — verified live (both here and on
	// GovernanceBodyDetail's `body-data` widget, title "Body details", same
	// rendering): the card heading is always the generic "Data", while the
	// declared title only surfaces as a page-level kicker paragraph above the
	// h2 object name. `type: "related"` widgets do NOT have this quirk — the
	// "Related" heading renders exactly as declared. Assert what actually
	// renders, plus the goal's own title text, so the test still proves this
	// is the real seeded goal's detail page and not an empty shell.
	// The kicker text "Goal" is not unique on the page (the collapsed audit
	// sidebar's header also renders a "Goal" mainname for the schema type),
	// so scope to the kicker's own data-testid to disambiguate.
	await expect(page.getByTestId('cn-detail-page-type-eyebrow')).toHaveText(
		'Goal',
		{ timeout: 45_000 },
	)
	await expect(
		page.getByRole('heading', { name: 'Data', exact: true }),
	).toBeVisible({ timeout: 45_000 })
	await expect(
		page.getByRole('heading', { name: 'Related', exact: true }),
	).toBeVisible({ timeout: 45_000 })
	// The seeded goal's own title must render — this is what separates a real
	// detail page from an empty widget shell. `.first()` because the title
	// legitimately appears more than once (the page header and the data
	// widget's own title field both render it); asserting on either occurrence
	// proves the object was loaded, and a strict locator would fail on the
	// page being MORE complete rather than less.
	await expect(
		page.getByText('Amsterdam klimaatneutraal', { exact: true }).first(),
	).toBeVisible({ timeout: 45_000 })
})
