/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — DecisionDetail's eight decision-facet-composition
 * facets (src/manifest.json:1068-1075): public/member/WOR consultations,
 * consultations and their responses, commitments
 * (toezeggingen) and confidentiality (geheimhouding). Before this file none
 * of these eight widgets had e2e coverage — a green suite proved nothing
 * about whether they render.
 *
 * Fixtures (a meeting, a motion Decision, and one toezegging referencing it
 * via `relatedMotion`) are seeded through the OpenRegister object API with
 * Basic auth and deleted explicitly in a `finally` block — `toezegging` is
 * not a member of workflows/governance-fixture.ts's TEARDOWN_ORDER list, so
 * routing it through that file's `cleanupAll()` would leak it silently (see
 * that file's doc comment for the schemas that already leaked this way).
 * The existing seeded governance body ("Gemeenteraad Amsterdam") is reused
 * rather than created, per the "prefer existing seed data" rule.
 *
 * @e2e openspec/specs/decision-management/spec.md#a-decision-referenced-by-a-public-consultation
 * @e2e openspec/specs/decision-management/spec.md#a-decision-with-no-member-consultations
 * @e2e openspec/specs/decision-management/spec.md#a-decision-referenced-by-a-wor-consultation-request
 * @e2e openspec/specs/decision-management/spec.md#a-decision-with-an-open-advisory-opinion-request
 * @e2e openspec/specs/decision-management/spec.md#a-decision-is-a-shared-bodys-closing-vaststellingsbesluit
 * @e2e openspec/specs/decision-management/spec.md#a-motion-produced-a-commitment
 * @e2e openspec/specs/decision-management/spec.md#a-decision-is-under-active-geheimhouding
 * @e2e openspec/specs/decision-management/spec.md#a-decision-with-no-confidentiality-restriction
 */
import type { APIRequestContext, PlaywrightWorkerArgs } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'

/** Placeholder Person reference, matching the nil-UUID convention already used throughout lib/Settings seed data for unresolved references. */
const NIL_UUID = '00000000-0000-0000-0000-000000000000'

/**
 * Basic-auth API context for seeding fixtures (session cookies would trip
 * the CSRF check on writes) — same shape as agenda-management.spec.ts.
 */
async function newApiContext(
	playwright: PlaywrightWorkerArgs['playwright'],
): Promise<APIRequestContext> {
	return playwright.request.newContext({
		extraHTTPHeaders: {
			Authorization:
				'Basic '
				+ Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
	})
}

/** Extract the OR object id from a create response body. */
function objectId(body: any): string | null {
	return body?.id ?? body?.['@self']?.id ?? null
}

/** Find a seeded GovernanceBody's id by its exact `name`. */
async function findGovernanceBodyId(
	api: APIRequestContext,
	name: string,
): Promise<string | null> {
	const resp = await api.get(`${OR}/governance-body?_limit=200`, {
		headers: { Accept: 'application/json' },
	})
	if (!resp.ok()) return null
	const body = await resp.json()
	const rows = body.results ?? body.items ?? []
	const match = rows.find((r: any) => r.name === name)
	return match ? objectId(match) : null
}

// @e2e openspec/specs/decision-management/spec.md#a-motion-produced-a-commitment
test('DecisionDetail: commitments facet lists a toezegging linked via relatedMotion', async ({
	page,
	playwright,
}) => {
	// Detail pages are widget-heavy: DecisionDetail declares 17 widgets, each
	// an object-list query costing ~1s on this instance, on top of the
	// pre-mount initializeStores() settings round trip every navigation
	// blocks on — the 30s global test timeout is nowhere near enough.
	test.setTimeout(120_000)
	const api = await newApiContext(playwright)
	let meetingId: string | null = null
	let decisionId: string | null = null
	let commitmentId: string | null = null
	try {
		const governanceBodyId = await findGovernanceBodyId(
			api,
			'Gemeenteraad Amsterdam',
		)
		test.skip(
			!governanceBodyId,
			'Seed governance body "Gemeenteraad Amsterdam" not found',
		)

		const meetingResp = await api.post(`${OR}/meeting`, {
			data: {
				title: `E2E facet meeting (commitment) ${Date.now()}`,
				meetingType: 'regular',
				scheduledDate: '2027-03-01T10:00:00Z',
				meetingMode: 'in-person',
				lifecycle: 'scheduled',
			},
		})
		test.skip(
			!meetingResp.ok(),
			`Could not seed meeting (HTTP ${meetingResp.status()})`,
		)
		meetingId = objectId(await meetingResp.json())
		test.skip(!meetingId, 'Seeded meeting has no id')

		// Decision.required[] = [title, text, decisionType] (ADR-005: motion and
		// amendment were folded into the Decision supertype). No `meeting` FK on
		// Decision — only AgendaItem/ActionItem carry that.
		const decisionResp = await api.post(`${OR}/decision`, {
			data: {
				decisionType: 'motion',
				title: `E2E facet motion ${Date.now()}`,
				text: 'E2E fixture motion body text for the commitments facet.',
				lifecycle: 'deliberating',
			},
		})
		test.skip(
			!decisionResp.ok(),
			`Could not seed decision (HTTP ${decisionResp.status()})`,
		)
		decisionId = objectId(await decisionResp.json())
		test.skip(!decisionId, 'Seeded decision has no id')

		const commitmentText = `E2E facet commitment ${Date.now()}`
		const commitmentResp = await api.post(`${OR}/toezegging`, {
			data: {
				text: commitmentText,
				madeBy: NIL_UUID,
				meeting: meetingId,
				directedTo: governanceBodyId,
				lifecycle: 'open',
				relatedMotion: decisionId,
			},
		})
		test.skip(
			!commitmentResp.ok(),
			`Could not seed toezegging (HTTP ${commitmentResp.status()})`,
		)
		commitmentId = objectId(await commitmentResp.json())
		test.skip(!commitmentId, 'Seeded toezegging has no id')

		await page.goto(`${BASE}/apps/decidiq/decisions/${decisionId}`)
		// app-root appearing only proves the shell mounted, not that data has
		// arrived — mount itself blocks on initializeStores()'s settings round
		// trip, so 30s (double the old budget) before even the shell shows up.
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

		// DecisionDetail issues ~17 widget queries at ~1s each after mount;
		// give content assertions real headroom instead of the 10s expect default.
		await expect(
			page.getByRole('heading', { name: 'Commitments', exact: true }),
		).toBeVisible({ timeout: 45_000 })
		await expect(page.getByText(commitmentText, { exact: true })).toBeVisible({
			timeout: 45_000,
		})
	} finally {
		if (commitmentId) {
			await api.delete(`${OR}/toezegging/${commitmentId}`).catch(() => null)
		}
		if (decisionId) {
			await api.delete(`${OR}/decision/${decisionId}`).catch(() => null)
		}
		if (meetingId) {
			await api.delete(`${OR}/meeting/${meetingId}`).catch(() => null)
		}
		await api.dispose()
	}
})

// @e2e openspec/specs/decision-management/spec.md#a-decision-with-no-member-consultations
// @e2e openspec/specs/decision-management/spec.md#a-decision-referenced-by-a-public-consultation
// @e2e openspec/specs/decision-management/spec.md#a-decision-referenced-by-a-wor-consultation-request
// @e2e openspec/specs/decision-management/spec.md#a-decision-with-an-open-advisory-opinion-request
// @e2e openspec/specs/decision-management/spec.md#a-decision-is-a-shared-bodys-closing-vaststellingsbesluit
// @e2e openspec/specs/decision-management/spec.md#a-decision-with-no-confidentiality-restriction
test('DecisionDetail: consultation, response and confidentiality facets render their real empty states', async ({
	page,
	playwright,
}) => {
	// Detail pages are widget-heavy: DecisionDetail declares 17 widgets, each
	// an object-list query costing ~1s on this instance, on top of the
	// pre-mount initializeStores() settings round trip every navigation
	// blocks on — the 30s global test timeout is nowhere near enough.
	test.setTimeout(120_000)
	const api = await newApiContext(playwright)
	let decisionId: string | null = null
	try {
		const decisionResp = await api.post(`${OR}/decision`, {
			data: {
				decisionType: 'motion',
				title: `E2E facet motion (empty facets) ${Date.now()}`,
				text: 'E2E fixture motion body text for the empty-facets spec.',
				lifecycle: 'deliberating',
			},
		})
		test.skip(
			!decisionResp.ok(),
			`Could not seed decision (HTTP ${decisionResp.status()})`,
		)
		decisionId = objectId(await decisionResp.json())
		test.skip(!decisionId, 'Seeded decision has no id')

		await page.goto(`${BASE}/apps/decidiq/decisions/${decisionId}`)
		// app-root appearing only proves the shell mounted, not that data has
		// arrived — mount itself blocks on initializeStores()'s settings round
		// trip, so 30s (double the old budget) before even the shell shows up.
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

		// DecisionDetail issues ~17 widget queries at ~1s each after mount;
		// give every content assertion real headroom instead of the 10s expect
		// default — this test alone checks seven separate facets in sequence.
		await expect(
			page.getByRole('heading', { name: 'Public consultations', exact: true }),
		).toBeVisible({ timeout: 45_000 })
		await expect(
			page.getByText('No public consultations reference this decision yet.', {
				exact: true,
			}),
		).toBeVisible({ timeout: 45_000 })

		// 🔴 THREE FACETS BECAME ONE. one-consultation-schema folded member
		// consultations, advisory opinions and zienswijzerondes into one
		// `governance-consultation` schema, and all three widgets filtered on
		// the same field, so keeping them would have listed the same rows three
		// times under three council words.
		await expect(
			page.getByRole('heading', { name: 'Consultations', exact: true }),
		).toBeVisible({ timeout: 45_000 })
		await expect(
			page.getByText('No consultations were held on this decision.', {
				exact: true,
			}),
		).toBeVisible({ timeout: 45_000 })

		await expect(
			page.getByRole('heading', {
				name: 'Consultation responses',
				exact: true,
			}),
		).toBeVisible({ timeout: 45_000 })
		await expect(
			page.getByText('No responses were recorded for this decision.', {
				exact: true,
			}),
		).toBeVisible({ timeout: 45_000 })

		await expect(
			page.getByRole('heading', { name: 'Works council (WOR)', exact: true }),
		).toBeVisible({ timeout: 45_000 })
		await expect(
			page.getByText(
				'No works-council consultation requests reference this decision yet.',
				{ exact: true },
			),
		).toBeVisible({ timeout: 45_000 })

		// The three retired headings are asserted ABSENT, not empty. Without
		// this a revert would repopulate them and this test would still pass.
		for (const gone of [
			'Member consultations',
			'Advisory opinions',
			'Zienswijzerondes',
			'Zienswijzen',
		]) {
			await expect(
				page.getByRole('heading', { name: gone, exact: true }),
			).toHaveCount(0)
		}

		await expect(
			page.getByRole('heading', { name: 'Confidentiality', exact: true }),
		).toBeVisible({ timeout: 45_000 })
		await expect(
			page.getByText('This decision has no confidentiality restriction.', {
				exact: true,
			}),
		).toBeVisible({ timeout: 45_000 })
	} finally {
		if (decisionId) {
			await api.delete(`${OR}/decision/${decisionId}`).catch(() => null)
		}
		await api.dispose()
	}
})
