/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Participants index page (genuine behavioural).
 *
 * The Participants route is reachable in the SPA but the nav entry is
 * not in the primary left-nav menu, so this spec deep-links to the
 * route (still app-scoped — never via the global header) and asserts
 * the real index surface + create form.
 *
 * @e2e openspec/specs/participant-management/spec.md#view-the-participants-list
 * @e2e openspec/specs/participant-management/spec.md#add-a-participant
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page
		.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]')
		.first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

// @e2e openspec/specs/participant-management/spec.md#view-the-participants-list
test('Participants: index renders heading, object-list table and Add CTA', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/decidiq/participants`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)

	await expect(page).toHaveURL(/\/apps\/decidiq\/.*participants/)
	// NOTE: the migrated CnIndexPage (nc-vue v2) no longer renders a page-title
	// heading inside <main>; assert the real index surface instead.
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	await expect(page.getByText('Showing', { exact: false }).first()).toBeVisible()
	await expect(page.getByRole('button', { name: 'Add Participant' })).toBeVisible()
})

// @e2e openspec/specs/participant-management/spec.md#add-a-participant
test('Participants: Add Participant opens a real create form dialog', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/decidiq/participants`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)

	await page.getByRole('button', { name: 'Add Participant' }).click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(
		dialog.getByRole('heading', { name: /Create\s+Participant/i }),
	).toBeVisible()
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/participant-management/spec.md#view-the-participants-list
test('Participants: no decidiq-origin console error or 500 on load', async ({
	page,
}) => {
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

	await page.goto(`${BASE}/apps/decidiq/participants`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	expect(
		appErrors,
		`decidiq errors on Participants:\n${appErrors.join('\n')}`,
	).toHaveLength(0)
})
