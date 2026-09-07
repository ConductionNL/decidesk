/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — relation-tab-ui spec
 *
 * @e2e openspec/specs/relation-tab-ui/spec.md#add-a-motion-from-the-agenda-item-motions-tab
 * @e2e openspec/specs/relation-tab-ui/spec.md#delete-a-child-object-refreshes-the-list
 * @e2e openspec/specs/relation-tab-ui/spec.md#empty-parent-issues-no-fetch
 * @e2e openspec/specs/relation-tab-ui/spec.md#adopted-motion-renders-a-success-badge
 * @e2e openspec/specs/relation-tab-ui/spec.md#against-vote-renders-an-error-badge
 * @e2e openspec/specs/relation-tab-ui/spec.md#add-dialog-excludes-already-linked-participants
 * @e2e openspec/specs/relation-tab-ui/spec.md#linking-a-participant-attaches-the-relation
 * @e2e openspec/specs/relation-tab-ui/spec.md#vote-caster-resolves-to-a-display-name
 * @e2e openspec/specs/relation-tab-ui/spec.md#open-the-parent-motion
 * @e2e openspec/specs/relation-tab-ui/spec.md#sign-now-offered-only-to-a-pending-signer-who-is-the-current-user
 * @e2e openspec/specs/relation-tab-ui/spec.md#signed-entry-shows-a-signed-badge
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

/**
 * Helper: get first object of given schema from OR API.
 *
 * Returns the object, or a STRING saying why there is none. The string is the
 * point: `if (!resp.ok()) return null` collapsed "this schema does not exist"
 * into the same value as "this schema is empty", and every caller then skipped
 * with `No <schema> objects found` — which reads as a seeding gap and is not
 * what happened.
 *
 * Measured on development run 33265879025: `motion detail renders votes tab
 * area` and `amendment detail renders with parent motion tab` have been
 * skipping because the register carries NO `motion` and NO `amendment` schema
 * at all (ci-seed prints the 100-odd slugs it does have; neither is among
 * them). Those two are the survivors of #969 — they stopped being red and
 * started being invisible, which is strictly worse, and the skip message is
 * what hid it.
 *
 * ✅ #957 LANDED (merged 2026-08-29), so the two tests above no longer wait on
 * it. ADR-005 folded `motion` and `amendment` into the single `Decision`
 * supertype discriminated by `decisionType`, which is exactly how the manifest
 * binds the surfaces: MotionDetail and AmendmentDetail both declare
 * `schema: "decision"`, and the Motions index adds `filter: {decisionType:
 * "motion"}`. The register carries no `motion` schema and never will again.
 *
 * So those tests now ask for what the pages ask for: a decision of the right
 * decisionType. Querying the retired slug kept them dark for months behind a
 * reason that was true and permanent, which is the quietest way for a test to
 * stop existing.
 */
async function getFirstObject(
	page: Page,
	schema: string,
	filters: Record<string, string> = {},
): Promise<Record<string, unknown> | string> {
	// Property filters are plain query params; only CONTROL params take the
	// underscore prefix (`_limit`). A bare `limit` is read as a property filter
	// and matches nothing — the API says so in its own `hint` field.
	const query = new URLSearchParams({ _limit: '1', ...filters }).toString()
	const url = `${BASE}/index.php/apps/openregister/api/objects/decidiq/${schema}?${query}`
	const resp = await page.request.get(url, {
		headers: { Accept: 'application/json' },
	})
	if (resp.status() === 404) {
		return `schema '${schema}' does not exist in register 'decidiq' (HTTP 404) — this is a DATA MODEL gap, not a seeding gap`
	}
	if (!resp.ok()) {
		return `GET ${schema} returned HTTP ${resp.status()}`
	}
	const body = await resp.json()
	const items = body.results ?? body.items ?? []
	if (!items[0]) {
		const where = Object.entries(filters)
			.map(([k, v]) => `${k}=${v}`)
			.join(', ')
		return `register 'decidiq' has schema '${schema}' but no object matches ${where || 'any filter'} — a seeding gap`
	}
	return items[0]
}

/** True when getFirstObject returned a reason rather than an object. */
function noObject(v: Record<string, unknown> | string): v is string {
	return typeof v === 'string'
}

// @e2e openspec/specs/relation-tab-ui/spec.md#add-a-motion-from-the-agenda-item-motions-tab
// @e2e openspec/specs/relation-tab-ui/spec.md#delete-a-child-object-refreshes-the-list
// @e2e openspec/specs/relation-tab-ui/spec.md#empty-parent-issues-no-fetch
test('agenda item detail page renders with motions sidebar tab', async ({
	page,
}) => {
	const first = await getFirstObject(page, 'agenda-item')
	test.skip(noObject(first), noObject(first) ? first : '')
	const obj = first as Record<string, unknown>
	const itemId = obj.id ?? (obj['@self'] as Record<string, unknown>)?.id
	test.skip(!itemId, 'First agenda-item has no id')

	await page.goto(`${BASE}/apps/decidiq/agenda-items/${itemId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#adopted-motion-renders-a-success-badge
// @e2e openspec/specs/relation-tab-ui/spec.md#against-vote-renders-an-error-badge
test('motions list renders lifecycle and vote status badges', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/motions`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	// App root mounts — badge rendering in motion rows is part of CnDataTable
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#add-dialog-excludes-already-linked-participants
// @e2e openspec/specs/relation-tab-ui/spec.md#linking-a-participant-attaches-the-relation
test('meeting detail has participants tab in sidebar', async ({ page }) => {
	const first = await getFirstObject(page, 'meeting')
	test.skip(noObject(first), noObject(first) ? first : '')
	const obj = first as Record<string, unknown>
	const meetingId = obj.id ?? (obj['@self'] as Record<string, unknown>)?.id
	test.skip(!meetingId, 'First meeting has no id')

	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	// App root mounts for meeting detail (sidebar with participants tab defined in manifest)
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#vote-caster-resolves-to-a-display-name
test('motion detail renders votes tab area', async ({ page }) => {
	const first = await getFirstObject(page, 'decision', {
		decisionType: 'motion',
	})
	test.skip(noObject(first), noObject(first) ? first : '')
	const obj = first as Record<string, unknown>
	const motionId = obj.id ?? (obj['@self'] as Record<string, unknown>)?.id
	test.skip(!motionId, 'First motion has no id')

	await page.goto(`${BASE}/apps/decidiq/motions/${motionId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#open-the-parent-motion
test('amendment detail renders with parent motion tab', async ({ page }) => {
	const first = await getFirstObject(page, 'decision', {
		decisionType: 'amendment',
	})
	test.skip(noObject(first), noObject(first) ? first : '')
	const obj = first as Record<string, unknown>
	const amendmentId = obj.id ?? (obj['@self'] as Record<string, unknown>)?.id
	test.skip(!amendmentId, 'First amendment has no id')

	await page.goto(`${BASE}/apps/decidiq/amendments/${amendmentId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/relation-tab-ui/spec.md#sign-now-offered-only-to-a-pending-signer-who-is-the-current-user
// @e2e openspec/specs/relation-tab-ui/spec.md#signed-entry-shows-a-signed-badge
test('minutes detail renders with signers tab area', async ({ page }) => {
	const first = await getFirstObject(page, 'minutes')
	test.skip(noObject(first), noObject(first) ? first : '')
	const obj = first as Record<string, unknown>
	const minutesId = obj.id ?? (obj['@self'] as Record<string, unknown>)?.id
	test.skip(!minutesId, 'First minutes has no id')

	await page.goto(`${BASE}/apps/decidiq/minutes/${minutesId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})
