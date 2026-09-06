/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — the three register-detail-optimisation catalog
 * widgets: `version-timeline` (RegisterVersionTimelineWidget, on
 * GoverningDocumentDetail), `delegation-chain` (DelegationChainWidget, on
 * BevoegdheidstoedelingDetail) and `confidentiality-status-timeline`
 * (ConfidentialityStatusTimelineWidget, on ConfidentialityRestrictionDetail). None of the
 * three had e2e coverage — a green suite proved nothing about whether they
 * render.
 *
 * Read-only throughout: every case uses an existing seed object (found via
 * the OpenRegister object API, read through the authenticated page session)
 * rather than a created fixture, per the "prefer existing seed data" rule —
 * lib/Settings/register.d/53-verordeningenregister.json,
 * 54-delegatie-mandaatregister.json and 65-embargo-geheimhouding.json all
 * ship `x-openregister.seedData` that already demonstrates each widget's
 * populated state. No fixtures are created, so no cleanup is needed.
 *
 * @e2e openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
 * @e2e openspec/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
 * @e2e openspec/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
 * @e2e openspec/specs/embargo-geheimhouding/spec.md#req-emb-011-confidentiality-ground-resolves-with-legacy-citation-on-geheimhoudingdetail
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`

/** GET a collection through the authenticated page session (read-only). */
async function listObjects(page: Page, schema: string): Promise<any[]> {
	const resp = await page.request.get(`${OR}/${schema}?_limit=200`, {
		headers: { Accept: 'application/json' },
	})
	if (!resp.ok()) return []
	const body = await resp.json()
	return body.results ?? body.items ?? []
}

/** Extract the OR object id (covers both id shapes). */
function objId(o: any): string {
	return o?.id ?? o?.['@self']?.id ?? ''
}

// @e2e openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
//
// 🔴 THIS MOVED SCHEMA, NOT SUBJECT. fold-regulations-into-governing-documents
// retired `regeling`, which modelled the same thing as `governing-document` in
// a council's words, so the seeded Afvalstoffenverordening is now a governing
// document and its detail page is the generic one. The widget was ALREADY
// shared: RegisterVersionTimelineWidget served both detail pages, which is part
// of how the duplication was found.
test('GoverningDocumentDetail: version-timeline widget renders both seeded versions of Afvalstoffenverordening Amsterdam', async ({
	page,
}) => {
	// Detail pages are widget-heavy: the composed detail pages in this app
	// declare 16-18 widgets, each an object-list query costing ~1s on this
	// instance, on top of the pre-mount initializeStores() settings round
	// trip every navigation blocks on — the 30s global test timeout is
	// nowhere near enough.
	test.setTimeout(120_000)

	const documents = await listObjects(page, 'governing-document')
	const afvalstoffen = documents.find(
		(r) => r.citationTitle === 'Afvalstoffenverordening Amsterdam',
	)
	test.skip(
		!afvalstoffen,
		'Seed governing document "Afvalstoffenverordening Amsterdam" not found',
	)

	await page.goto(
		`${BASE}/apps/decidiq/governing-documents/${objId(afvalstoffen)}`,
	)
	// app-root appearing only proves the shell mounted, not that data has
	// arrived — mount itself blocks on initializeStores()'s settings round
	// trip, so 30s (double the old budget) before even the shell shows up.
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

	// Give content assertions real headroom instead of the 10s expect default.
	await expect(
		page.getByRole('heading', { name: 'Version timeline', exact: true }),
	).toBeVisible({ timeout: 45_000 })
	await expect(page.getByTestId('version-timeline-list')).toBeVisible({
		timeout: 45_000,
	})
	// afvalstoffen-v1 (vervangen/replaced, 2024-01-01) → afvalstoffen-v2
	// (in-werking/in force, 2025-06-01) — both seeded versions render.
	await expect(page.getByText('Version 1', { exact: false })).toBeVisible({
		timeout: 45_000,
	})
	await expect(page.getByText('Version 2', { exact: false })).toBeVisible({
		timeout: 45_000,
	})
	await expect(page.getByText('replaced', { exact: true })).toBeVisible({
		timeout: 45_000,
	})
	await expect(page.getByText('in force', { exact: true })).toBeVisible({
		timeout: 45_000,
	})
})

// @e2e openspec/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
test('BevoegdheidstoedelingDetail: delegation-chain widget shows the seeded ondermandaat under mandaat-subsidies-secretaris', async ({
	page,
}) => {
	// Detail pages are widget-heavy: the composed detail pages in this app
	// declare 16-18 widgets, each an object-list query costing ~1s on this
	// instance, on top of the pre-mount initializeStores() settings round
	// trip every navigation blocks on — the 30s global test timeout is
	// nowhere near enough.
	test.setTimeout(120_000)

	const toedelingen = await listObjects(page, 'bevoegdheidstoedeling')
	const parent = toedelingen.find(
		(t) =>
			t.subject === 'Beslissen op subsidieaanvragen tot het genoemde plafond',
	)
	test.skip(
		!parent,
		'Seed bevoegdheidstoedeling "mandaat-subsidies-secretaris" not found',
	)

	await page.goto(`${BASE}/apps/decidiq/bevoegdheidstoedelingen/${objId(parent)}`)
	// app-root appearing only proves the shell mounted, not that data has
	// arrived — mount itself blocks on initializeStores()'s settings round
	// trip, so 30s (double the old budget) before even the shell shows up.
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

	// Give content assertions real headroom instead of the 10s expect default.
	await expect(
		page.getByRole('heading', { name: 'Ondermandaat chain', exact: true }),
	).toBeVisible({ timeout: 45_000 })
	await expect(page.getByTestId('delegation-chain-children')).toBeVisible({
		timeout: 45_000,
	})
	await expect(
		page.getByText(
			'Beslissen op subsidieaanvragen binnen het programma Samenleving',
			{ exact: true },
		),
	).toBeVisible({ timeout: 45_000 })
})

// @e2e openspec/specs/embargo-geheimhouding/spec.md#req-emb-010-confidentiality-status-timeline-widget-on-geheimhoudingdetail
// @e2e openspec/specs/embargo-geheimhouding/spec.md#req-emb-011-confidentiality-ground-resolves-with-legacy-citation-on-geheimhoudingdetail
test('ConfidentialityRestrictionDetail: confidentiality-status-timeline widget renders the imposed stage and resolves its ground', async ({
	page,
}) => {
	// Detail pages are widget-heavy: the composed detail pages in this app
	// declare 16-18 widgets, each an object-list query costing ~1s on this
	// instance, on top of the pre-mount initializeStores() settings round
	// trip every navigation blocks on — the 30s global test timeout is
	// nowhere near enough.
	test.setTimeout(120_000)

	const restrictions = await listObjects(page, 'confidentiality-restriction')
	const raadsnota = restrictions.find(
		(g) => g.imposedAt === '2026-06-08T10:00:00Z',
	)
	test.skip(
		!raadsnota,
		'Seed confidentiality restriction "geheimhouding-raadsnota-grondexploitatie" not found',
	)

	await page.goto(
		`${BASE}/apps/decidiq/confidentiality-restrictions/${objId(raadsnota)}`,
	)
	// app-root appearing only proves the shell mounted, not that data has
	// arrived — mount itself blocks on initializeStores()'s settings round
	// trip, so 30s (double the old budget) before even the shell shows up.
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 30_000 })

	// Give content assertions real headroom instead of the 10s expect default.
	await expect(
		page.getByRole('heading', {
			name: 'Confidentiality status timeline',
			exact: true,
		}),
	).toBeVisible({ timeout: 45_000 })
	await expect(page.getByTestId('confidentiality-timeline-list')).toBeVisible({
		timeout: 45_000,
	})
	await expect(page.getByTestId('confidentiality-stage-imposed')).toBeVisible({
		timeout: 45_000,
	})
	await expect(page.getByTestId('confidentiality-ground')).toBeVisible({
		timeout: 45_000,
	})
	await expect(
		page.getByText('Geheimhouding raadsstukken', { exact: false }),
	).toBeVisible({ timeout: 45_000 })
})
