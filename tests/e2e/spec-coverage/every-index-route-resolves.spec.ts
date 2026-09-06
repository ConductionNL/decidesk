/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every index route in the merged manifest resolves in a browser.
 *
 * WHY THIS EXISTS. Auditing this programme's renames turned up 16 of the app's
 * 30 declared index routes with no e2e navigation anywhere in the suite — among
 * them `/planning-cycles`, `/planned-agenda`, `/commitments`,
 * `/authority-delegations` and `/confidentiality-grounds`, every one of which
 * this programme had just renamed. A green suite was proving nothing about
 * whether they open.
 *
 * 🔴 BE PRECISE ABOUT WHAT THIS CATCHES. The heading comes from the manifest's
 * `title`, so a page bound to the WRONG SCHEMA still renders it, and so does a
 * page whose id collided with another fragment's. Neither is what this test is
 * for — `tests/vitest/manifestIdsAreUnique.spec.js` covers the collision, and
 * `configuration-surface.spec.ts` seeds a row over the API to prove one schema
 * binding.
 *
 * What this catches is the route that does not resolve AT ALL: a page dropped
 * by the fragment merge, a `type` the renderer does not know, a bundle built
 * before a fragment landed. Those render the app shell and nothing else, which
 * on a list page is indistinguishable by eye from "no rows yet".
 *
 * The route table is DERIVED, not typed out. It comes from the same
 * `buildManifest` the app calls in `src/main.js`, over the same base manifest,
 * the same `manifest.d/*.json` in the same sorted order, and the same
 * menu-layout. A page added by a future fragment is covered the day it lands,
 * and a route renamed without its test being updated cannot go stale, because
 * there is no second copy of the list to update.
 *
 * @e2e openspec/changes/every-index-route-resolves/specs/every-index-route-resolves/spec.md#requirement-every-declared-index-route-opens
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { expect, test } from '@playwright/test'
import fs from 'fs'
import path from 'path'
import { BASE_URL as BASE } from '../base-url.ts'

const APP_ROOT = path.resolve(__dirname, '../../..')

/** Read one JSON file from the app, relative to the repo root. */
function readJson(relative: string): any {
	return JSON.parse(fs.readFileSync(path.join(APP_ROOT, relative), 'utf8'))
}

/**
 * The merged manifest, built exactly as `src/main.js` builds it at boot.
 *
 * `src/main.js` collects the fragments with webpack's `require.context`, which
 * has no Node equivalent, so the sort is spelled out here. Sorted order is not
 * cosmetic: ADR-037 resolves a same-id page by letting the LAST fragment win,
 * so a different order is a different manifest.
 */
function mergedManifest(): any {
	const dir = path.join(APP_ROOT, 'src/manifest.d')
	const fragments = fs
		.readdirSync(dir)
		.filter((f) => f.endsWith('.json'))
		.sort()
		.map((f) => readJson(path.join('src/manifest.d', f)))
	return buildManifest(
		readJson('src/manifest.json'),
		fragments,
		readJson('src/menu-layout.json'),
	)
}

/**
 * Index pages only, and only those whose route takes no parameter.
 *
 * A `:id` route needs a real object to open, which is what the per-schema specs
 * already do with seed data. The other page types (`dashboard`, `reports`,
 * `store`, `roadmap`, `custom`) each render their own chrome rather than a
 * titled list, so a single heading assertion would not mean the same thing
 * across them; `app-chrome.spec.ts` covers the ones that are reachable from the
 * menu.
 */
const INDEX_ROUTES: Array<{ route: string; title: string; id: string }> = (
	mergedManifest().pages || []
)
	.filter(
		(p: any) =>
			p.type === 'index'
			&& typeof p.route === 'string'
			&& !p.route.includes(':')
			&& typeof p.title === 'string',
	)
	.map((p: any) => ({ route: p.route, title: p.title, id: p.id }))

// 🔑 THE GUARD THAT KEEPS THE REST HONEST. Everything below is generated from
// INDEX_ROUTES, so if the merge ever returns nothing this file runs ZERO tests
// and reports green — the exact shape of hollow pass this programme keeps
// meeting. This test fails instead. The floor is deliberately well under the
// current count so it does not have to be edited every time a page lands; it
// only has to be high enough that a broken merge cannot clear it.
test('the derived route table is not empty', async () => {
	expect(INDEX_ROUTES.length).toBeGreaterThanOrEqual(25)
	// Titles are what the assertions below match on, so a duplicate would make
	// two routes indistinguishable and let a missing page pass on its twin.
	const titles = INDEX_ROUTES.map((r) => r.title)
	expect(new Set(titles).size).toBe(titles.length)
})

for (const page_ of INDEX_ROUTES) {
	// @e2e openspec/changes/every-index-route-resolves/specs/every-index-route-resolves/spec.md#requirement-every-declared-index-route-opens
	test(`${page_.route} resolves and renders "${page_.title}"`, async ({
		page,
	}) => {
		// Index pages are lighter than this suite's detail pages, but they still
		// block on initializeStores() before the shell mounts.
		test.setTimeout(60_000)

		await page.goto(`${BASE}/apps/decidiq${page_.route}`)
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

		// 🔑 THE HEADING IS THE ASSERTION. A route that does not resolve renders
		// the app shell and nothing else, which looks the same as a list that
		// happens to be empty. The heading is what tells the two apart.
		await expect(
			page.getByRole('heading', { name: page_.title, exact: true }).first(),
		).toBeVisible({ timeout: 30_000 })

		// An empty list is a legitimate state — an instance that loaded no example
		// set has no rows — so this asserts the page RENDERED, not that it has
		// content. Asserting rows would tie the suite to whichever example set the
		// run happens to have loaded.
		await expect(page.getByTestId('app-root')).toBeVisible()
	})
}
