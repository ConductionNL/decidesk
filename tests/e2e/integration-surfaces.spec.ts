/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The per-object INTEGRATION SURFACE for a motion, driven in a browser.
 *
 * It is a routed, manifest-declared `type: "custom"` page whose whole job is to
 * mount `CnDetailPage` with `sidebar.useRegistry: true` for one object type
 * (ADR-019). Until this spec existed, nothing in the suite asserted it.
 *
 * `integration-registry.spec.ts` navigates to one of these routes, but every
 * assertion it makes is about the shared registry sidebar — the counts and tab
 * ids that CnObjectSidebar renders. Not one of them touches the page component
 * itself, so the view could render an empty shell and that spec would still be
 * green. This one asserts the page's OWN markup: the body block the view
 * declares and the back-link it wires to its detail route.
 *
 * ⚠️ THE OTHER THREE INTEGRATION ROUTES ARE NOT COVERED HERE, AND THEIR
 * COMPONENT NAMES ARE DELIBERATELY NOT WRITTEN ANYWHERE IN THIS FILE. gate-26
 * matches a page component to its coverage on the bare PascalCase token, so an
 * earlier draft of this header — which listed all four paths purely as
 * documentation — was enough to turn the gate GREEN for three screens no test
 * touches. Measured: with this file present the gate reported PASS; with it
 * moved aside, `FAIL — 4`. A comment must never be able to close a coverage
 * finding, so the names stay out.
 *
 * READ-ONLY BY CONSTRUCTION
 * -------------------------
 * This spec issues GETs and navigations only — it creates and deletes nothing.
 * The objects it drives are the ones `tests/e2e/ci-seed.sh` provisions and
 * verifies (≥2 meetings, ≥3 agenda-items, ≥3 decisions), so on CI the data is
 * guaranteed to be there. When it is not, the resolver fails LOUDLY rather than
 * skipping: a surface that was never opened must not report as a pass.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`

/** Pull the UUID out of an OR object response (covers both id shapes). */
function objId(o: Record<string, unknown>): string {
	const self = o?.['@self'] as Record<string, unknown> | undefined
	return String(o?.id ?? self?.id ?? o?.uuid ?? '')
}

/**
 * Resolve one object of `schema` to drive a surface against.
 *
 * `prefer` picks the most representative object when the schema is
 * polymorphic — a "motion" and a "decision" are both rows of the `decision`
 * schema, discriminated by `decisionType` (ADR-005). When nothing matches the
 * preference we fall back to the first object, because the surface under test
 * renders from the route param and does not itself branch on decisionType.
 *
 * @param page Playwright Page (carries the authenticated session).
 * @param schema OpenRegister schema slug.
 * @param prefer Optional predicate naming the preferred object.
 * @return The object's UUID.
 */
async function resolveObjectId(
	page: Page,
	schema: string,
	prefer?: (o: Record<string, unknown>) => boolean,
): Promise<string> {
	const resp = await page.request.get(`${OR}/${schema}?_limit=50`, {
		headers: { Accept: 'application/json' },
	})
	expect(
		resp.ok(),
		`GET ${OR}/${schema} must answer 2xx (got ${resp.status()})`,
	).toBe(true)
	const body = await resp.json()
	const results: Record<string, unknown>[] = body.results ?? body.items ?? []
	const picked = (prefer ? results.find(prefer) : undefined) ?? results[0]
	const id = picked ? objId(picked) : ''
	expect(
		id,
		`no '${schema}' object to drive the integrations surface with — `
			+ `tests/e2e/ci-seed.sh seeds this schema, so an empty listing means the `
			+ `seed did not run, not that the surface is untestable`,
	).toBeTruthy()
	return id
}

interface Surface {
	/** Page component under test — the file this spec exists to cover. */
	component: string
	/** Its exported component name / manifest page id. */
	name: string
	/** OR schema slug the route's `:id` addresses. */
	schema: string
	/** Preferred object within a polymorphic schema. */
	prefer?: (o: Record<string, unknown>) => boolean
	/** Route template; `{id}` is substituted. */
	route: string
	/** `data-testid` the view puts on its own body block. */
	testId: string
}

// ONE SURFACE, BECAUSE ONE SURFACE IS WHAT I CAN SHOW WORKS.
//
// This spec started with all four integration routes. I was wrong about the
// other three, twice, spending a CI run on each guess:
//
//   1. "those views never render" -> flipped three manifest pages from
//      `type: "detail"` to `type: "custom"` and registered a missing import.
//      CI: byte-identical failures. Reverted.
//   2. "`config.widgets` is the discriminator, so assert the widget grid
//      instead" -> CI: still failed, AND a page-heading assertion added along
//      the way broke the one test that had been passing. Reverted.
//
// What CI has actually PROVEN, twice, is narrow: the motion route mounts its own
// component, and its body block and back-link testids are present. The meeting,
// decision and agenda-item routes do not produce their testids, and I do not yet
// know what they render instead. `config.widgets` correlates — but correlation
// is precisely what misled me in round 2, and the local dev container serves a
// bundle weeks older than development, so it cannot settle it either.
//
// The other three are therefore LEFT UNCOVERED ON PURPOSE, and gate-26 must go
// on reporting them. Note how easily that could have been faked: this gate
// matches a component to its coverage on the bare PascalCase token, so merely
// NAMING those three files in a comment — as an earlier draft of this note did —
// turned the gate green with no test behind it. The names are spelled out
// nowhere in this file for that reason. Three view files that nothing appears to
// mount need a decision (delete as dead code, or establish what serves those
// routes), not a fourth guess dressed up as a test.
const SURFACES: Surface[] = [
	{
		component: 'src/views/MotionIntegrations.vue',
		name: 'MotionIntegrations',
		schema: 'decision',
		prefer: (o) => o.decisionType === 'motion',
		route: '/apps/decidiq/motions/{id}/integrations',
		testId: 'motion-integrations',
	},
]

test.describe('Integration surfaces — per-object integration pages render', () => {
	for (const surface of SURFACES) {
		test(`${surface.name} (${surface.component}) renders its body and back-link`, async ({
			page,
		}) => {
			const id = await resolveObjectId(page, surface.schema, surface.prefer)

			await page.goto(surface.route.replace('{id}', id))

			// The view's OWN body block — declared by the component under test,
			// not by CnDetailPage or the registry sidebar.
			const body = page.locator(`[data-testid="${surface.testId}"]`)
			await expect(body, `${surface.name} body block must mount`).toBeVisible({
				timeout: 15_000,
			})

			// It carries the explanatory copy every one of these surfaces ships;
			// an empty shell is a regression this spec must catch.
			await expect(body).not.toBeEmpty()

			// The back-link the view wires to its detail route.
			const back = page.locator(`[data-testid="${surface.testId}-back"]`)
			await expect(back, `${surface.name} back-link must render`).toBeVisible()
		})
	}
})
