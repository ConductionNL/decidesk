/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — MeetingDetail's five meeting-facet-composition
 * facets: typed agenda items, proxy authorizations,
 * the assoc-mode-gated kascommissie verklaringen facet, and the read-only
 * routed-incoming-documents facet. Before this file these five widgets
 * (src/manifest.json:474-478) had no e2e coverage at all — a green suite
 * proved nothing about whether they actually render.
 *
 * Fixtures (a meeting, an agenda-item-type and one agenda item) are seeded
 * through the OpenRegister object API with Basic auth (CSRF-exempt, mirrors
 * agenda-management.spec.ts's `newApiContext`) and deleted in a `finally`
 * block — explicitly, by id, rather than via the workflows/governance-fixture
 * TEARDOWN_ORDER list, because `agenda-item-type` is not a member of that list
 * and would leak silently if routed through `cleanupAll()` (see that file's
 * TEARDOWN_ORDER doc comment for the five schemas that already leaked this
 * way).
 *
 * @e2e openspec/specs/meeting-detail-view/spec.md#oral-questions-scoped-to-the-current-meeting
 *   (now served by the generic typed-agenda-item assertion — see the first test)
 * @e2e openspec/specs/meeting-detail-view/spec.md#interpellations-scheduled-at-the-current-meeting
 * @e2e openspec/specs/meeting-detail-view/spec.md#proxy-authorizations-scoped-to-the-current-meeting
 * @e2e openspec/specs/meeting-detail-view/spec.md#audit-statement-facet-hidden-outside-association-mode
 * @e2e openspec/specs/meeting-detail-view/spec.md#documents-routed-onto-the-meetings-agenda
 */
import type { APIRequestContext, PlaywrightWorkerArgs } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'

/** A placeholder Person/Decision reference for required-but-irrelevant refs, matching the nil-UUID convention already used throughout lib/Settings seed data for unresolved references. */

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

// @e2e openspec/specs/meeting-detail-view/spec.md#oral-questions-scoped-to-the-current-meeting
//
// 🔴 THIS TEST CHANGED SUBJECT, NOT SCOPE. It used to create a
// `mondelinge-vraag` and look for an "Oral questions" facet. Both are gone:
// questions-as-agenda-items retired that schema and removed the facet, because
// an oral question is an agenda item whose TYPE says what kind it is, and the
// app is not supposed to know that a council calls it a mondelinge vraag.
//
// So what is asserted now is the thing the collapse has to get right and the
// unit tests cannot see: an agenda item carrying a configured type renders on
// the meeting under THAT TYPE'S NAME. If the type never resolves, the Type
// column silently falls back to the coarse enum and every collapsed item reads
// as "discussion" — a regression that looks like a working page.
test('MeetingDetail: an agenda item renders under its configured type name', async ({
	page,
	playwright,
}) => {
	// Detail pages are widget-heavy: MeetingDetail declares 16 widgets, each
	// an object-list query costing ~1s on this instance, on top of the
	// pre-mount initializeStores() settings round trip every navigation
	// blocks on — the 30s global test timeout is nowhere near enough.
	test.setTimeout(120_000)
	const api = await newApiContext(playwright)
	let meetingId: string | null = null
	let typeId: string | null = null
	let itemId: string | null = null
	try {
		const meetingResp = await api.post(`${OR}/meeting`, {
			data: {
				title: `E2E facet meeting (typed agenda item) ${Date.now()}`,
				meetingType: 'regular',
				scheduledDate: '2027-02-01T10:00:00Z',
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

		// A run-unique name, so the assertion cannot pass on a type some example
		// set happens to have seeded.
		const typeName = `E2E oral question ${Date.now()}`
		const typeResp = await api.post(`${OR}/agenda-item-type`, {
			data: { name: typeName, isDraft: false, active: true },
		})
		test.skip(
			!typeResp.ok(),
			`Could not seed agenda-item-type (HTTP ${typeResp.status()})`,
		)
		typeId = objectId(await typeResp.json())
		test.skip(!typeId, 'Seeded agenda-item-type has no id')

		const subject = `E2E typed agenda item ${Date.now()}`
		const itemResp = await api.post(`${OR}/agenda-item`, {
			data: {
				title: subject,
				itemType: 'discussion',
				orderNumber: 1,
				meeting: meetingId,
				type: typeId,
				// The fields the retired schema declared as columns now live here,
				// which is what `typeFields` was added for.
				typeFields: {
					questionNumber: `MV-2026-${Date.now() % 100000}`,
					politicalGroup: 'E2E test fraction',
					lifecycle: 'submitted',
				},
			},
		})
		test.skip(
			!itemResp.ok(),
			`Could not seed agenda-item (HTTP ${itemResp.status()})`,
		)
		itemId = objectId(await itemResp.json())
		test.skip(!itemId, 'Seeded agenda-item has no id')

		await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
		// app-root appearing only proves the shell mounted, not that data has
		// arrived — mount itself blocks on initializeStores()'s settings round
		// trip, so 30s (double the old budget) before even the shell shows up.
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

		// Scoped to the agenda tab on purpose: "Agenda" is BOTH the widget's
		// page-level title and the tab's own heading, so an unscoped
		// getByRole('heading') matches two elements and fails strict mode
		// without telling you the page rendered perfectly well.
		const agenda = page.getByTestId('agenda-tab')
		await expect(agenda).toBeVisible({ timeout: 45_000 })
		await expect(agenda.getByText(subject, { exact: true })).toBeVisible({
			timeout: 45_000,
		})

		// 🔑 THE TYPE NAME IS THE ASSERTION. Finding the item proves the agenda
		// lists it; finding the type name proves the Type column resolved the
		// configured kind rather than falling back to "discussion".
		await expect(agenda.getByText(typeName, { exact: true })).toBeVisible({
			timeout: 45_000,
		})
	} finally {
		if (itemId) {
			await api.delete(`${OR}/agenda-item/${itemId}`).catch(() => null)
		}
		if (typeId) {
			await api.delete(`${OR}/agenda-item-type/${typeId}`).catch(() => null)
		}
		if (meetingId) {
			await api.delete(`${OR}/meeting/${meetingId}`).catch(() => null)
		}
		await api.dispose()
	}
})

// @e2e openspec/specs/meeting-detail-view/spec.md#interpellations-scheduled-at-the-current-meeting
// @e2e openspec/specs/meeting-detail-view/spec.md#proxy-authorizations-scoped-to-the-current-meeting
// @e2e openspec/specs/meeting-detail-view/spec.md#documents-routed-onto-the-meetings-agenda
// @e2e openspec/specs/meeting-detail-view/spec.md#audit-statement-facet-hidden-outside-association-mode
test('MeetingDetail: proxy-authorizations and routed-documents facets render their real empty states; interpellations and kascommissie are absent', async ({
	page,
	playwright,
}) => {
	// Detail pages are widget-heavy: MeetingDetail declares 16 widgets, each
	// an object-list query costing ~1s on this instance, on top of the
	// pre-mount initializeStores() settings round trip every navigation
	// blocks on — the 30s global test timeout is nowhere near enough.
	test.setTimeout(120_000)
	const api = await newApiContext(playwright)
	let meetingId: string | null = null
	try {
		const meetingResp = await api.post(`${OR}/meeting`, {
			data: {
				title: `E2E facet meeting (empty facets) ${Date.now()}`,
				meetingType: 'regular',
				scheduledDate: '2027-02-02T10:00:00Z',
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

		await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
		// app-root appearing only proves the shell mounted, not that data has
		// arrived — mount itself blocks on initializeStores()'s settings round
		// trip, so 30s (double the old budget) before even the shell shows up.
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

		// MeetingDetail issues ~18 widget queries at ~1s each after mount;
		// give content assertions real headroom instead of the 10s expect default.
		// 🔴 THE INTERPELLATIONS FACET IS ASSERTED ABSENT, NOT EMPTY.
		// questions-as-agenda-items removed it: an interpellation request is an
		// agenda item carrying a type that requires support before it is
		// admitted, so it belongs in the Agenda widget. Asserting its heading is
		// gone is what keeps a revert from passing this test quietly.
		await expect(
			page.getByRole('heading', { name: 'Interpellations', exact: true }),
		).toHaveCount(0)

		// Proxy authorizations facet (object-list widget) — real declared empty state.
		await expect(
			page.getByRole('heading', { name: 'Proxy authorizations', exact: true }),
		).toBeVisible({ timeout: 45_000 })
		await expect(
			page.getByText(
				'No proxy authorizations registered for this meeting yet.',
				{ exact: true },
			),
		).toBeVisible({ timeout: 45_000 })

		// Routed incoming documents facet — custom two-hop-join widget
		// (MeetingRoutedDocumentsTab), not an object-list widget.
		// The heading text "Incoming documents" is also a substring of the
		// table's own empty-state cell ("No incoming documents routed to this
		// meeting yet."), so a plain getByText('Incoming documents') matches
		// both the h3 and the td and trips Playwright's strict-mode check.
		// Scope to the heading role to disambiguate — the empty-state cell is
		// still verified on its own two lines below.
		const routedDocs = page.getByTestId('meeting-routed-documents-tab')
		await expect(routedDocs).toBeVisible({ timeout: 45_000 })
		await expect(
			routedDocs.getByRole('heading', {
				name: 'Incoming documents',
				exact: false,
			}),
		).toBeVisible({ timeout: 45_000 })
		// This is the sibling-render proof the kascommissie absence check below
		// depends on: its empty-state text is the LAST content assertion before
		// the absence check, so reaching it (with a generous wait, given 18
		// widgets at ~1s/query) confirms Vue has finished deciding the whole
		// widget grid — including whether to mount kascommissie — before we
		// treat "not found" as "gated off" rather than "not loaded yet".
		await expect(
			page.getByText('No incoming documents routed to this meeting yet.', {
				exact: true,
			}),
		).toBeVisible({ timeout: 45_000 })

		// Kascommissie is assoc-mode-gated: the widget declaration stays on the
		// page (a grid cell is still allocated) but renders NOTHING at all in
		// the default 'gov' organisatie_modus — assert its data-testid is
		// absent rather than forcing a mode switch. Checked last, after the
		// routed-documents facet (which sits below it in gridY order) has been
		// proven rendered above, so by this point Vue has already decided
		// whether to mount it — an absence check with nothing rendered yet
		// would pass for the wrong reason (not-loaded looks identical to gated-off).
		await expect(page.getByTestId('meeting-audit-statements-tab')).toHaveCount(0)
	} finally {
		if (meetingId) {
			await api.delete(`${OR}/meeting/${meetingId}`).catch(() => null)
		}
		await api.dispose()
	}
})
