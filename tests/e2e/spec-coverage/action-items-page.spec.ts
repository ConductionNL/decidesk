/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Action items index page (genuine behavioural).
 *
 * The ActionItems nav route had no dedicated spec. Drives the page via
 * the app's LEFT navigation (cn-nav-entry-ActionItems, not the global
 * NC header), then asserts the real index surface and the create form.
 *
 * @e2e openspec/specs/action-item-management/spec.md#view-the-action-items-list
 * @e2e openspec/specs/action-item-management/spec.md#create-an-action-item
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'
import { writeHeaders } from '../workflows/governance-fixture.ts'

/**
 * The action-item index needs at least one row to be an index at all.
 *
 * `action-item` is NOT an ordinary OpenRegister object: its schema declares
 * `x-openregister-object-source: { provider: caldav-vtodo, readOnly: true }`
 * (lib/Settings/decidesk_register.json), so OpenRegister's GetObject::findAll
 * delegates the list to the CalDAV VTODO provider and never reads the magic
 * table. The eight `action-item` entries in the register's `seedData` are
 * therefore NEVER served, and `ObjectService::saveObject` is refused outright —
 * so neither the register import nor governance-fixture's `createObject()` can
 * put a row on this page. On a fresh CI instance the admin owns no action-item
 * VTODOs, the list is legitimately empty, and CnIndexPage renders
 * `NcEmptyContent` instead of `CnDataTable` — which is why
 * `cn-object-list-table` was absent.
 *
 * The only write path that can create one is the app's own
 * `POST /apps/decidiq/api/action-items` (ActionItemController::create →
 * ActionItemWriter → OpenRegister TaskService), which writes a VTODO into the
 * ACTING USER's calendar. Playwright's `page.request` shares the browser
 * context's session cookies, so this seeds for exactly the user the page runs
 * as.
 */
const ACTION_API = `${BASE}/index.php/apps/decidiq/api/action-items`
const seededUids: string[] = []

async function seedActionItem(page: Page, title: string): Promise<void> {
	const headers = await writeHeaders(page)
	const resp = await page.request.post(ACTION_API, {
		headers,
		data: {
			title,
			assignee: 'admin',
			taskStatus: 'open',
			description: 'Seeded by the action-items e2e spec.',
		},
	})
	// Assert the seed, so a provisioning failure names itself here instead of
	// resurfacing as a selector timeout that accuses the table component.
	expect(
		resp.status(),
		`seeding an action item failed: ${await resp.text()}`,
	).toBeLessThan(300)
	const body = await resp.json()
	const uid = body?.actionItem?.uid ?? body?.actionItem?.id ?? ''
	if (uid) seededUids.push(String(uid))
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	const headers = await writeHeaders(page)
	for (const uid of seededUids) {
		await page.request
			.delete(`${ACTION_API}/${encodeURIComponent(uid)}`, { headers })
			.catch(() => undefined)
	}
	await page.close()
})

async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page
		.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]')
		.first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

async function appNavClick(page: Page, entryId: string): Promise<void> {
	await page.goto(`${BASE}/apps/decidiq/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)
	const nav = page
		.locator('[data-testid="cn-nav"], #app-navigation-vue, .app-navigation')
		.first()
	await nav.getByTestId(`cn-nav-entry-${entryId}`).click()
}

// @e2e openspec/specs/action-item-management/spec.md#view-the-action-items-list
test('Action items: app-scoped nav lands on the index with its real content', async ({
	page,
}) => {
	await seedActionItem(page, `e2e action item ${Date.now()}`)
	await appNavClick(page, 'ActionItems')

	await expect(page).toHaveURL(/\/apps\/decidiq\/.*action-items/)
	// NOTE: the migrated CnIndexPage (nc-vue v2) no longer renders a page-title
	// heading inside <main>; the real index surface is the object-list table +
	// "Showing N of N" + primary CTA, which is what we assert below.
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	await expect(page.getByText('Showing', { exact: false }).first()).toBeVisible()
	await expect(page.getByRole('button', { name: 'Add ActionItem' })).toBeVisible()
})

// @e2e openspec/specs/action-item-management/spec.md#create-an-action-item
test('Action items: Add ActionItem opens a real create form dialog', async ({
	page,
}) => {
	await appNavClick(page, 'ActionItems')
	await page.getByRole('button', { name: 'Add ActionItem' }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(
		dialog.getByRole('heading', { name: /Create\s+ActionItem/i }),
	).toBeVisible()
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/action-item-management/spec.md#view-the-action-items-list
test('Action items: no decidiq-origin console error or 500 on load', async ({
	page,
}) => {
	await seedActionItem(page, `e2e action item ${Date.now()}`)
	const appErrors: string[] = []
	page.on('console', (m) => {
		const t = m.text()
		if (
			m.type() === 'error'
			&& !/user_status|heartbeat|user status/i.test(t)
			&& /decidiq/i.test(t)
		) {
			appErrors.push(t)
		}
	})
	page.on('response', (r) => {
		if (r.status() >= 500 && /decidiq/i.test(r.url()))
			appErrors.push(`HTTP ${r.status()} ${r.url()}`)
	})

	await appNavClick(page, 'ActionItems')
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	expect(
		appErrors,
		`decidiq errors on Action items:\n${appErrors.join('\n')}`,
	).toHaveLength(0)
})
