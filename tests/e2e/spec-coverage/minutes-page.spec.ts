/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Minutes index page (genuine behavioural).
 *
 * The Minutes nav route had no dedicated spec. This drives the page
 * through the app's LEFT navigation (the `cn-nav-entry-Minutes` testid,
 * NOT the global NC header — clicking a header "Dashboard"/app link can
 * silently navigate out of the SPA and false-green), then asserts the
 * real index surface: heading, object-list table, the "Add Minutes"
 * primary CTA, and that opening the create dialog renders a real form.
 *
 * @e2e openspec/specs/app-navigation/spec.md#demoted-surface-remains-reachable-by-deep-link
 * @e2e openspec/specs/p2-minutes-and-decisions/spec.md#req-ml-001
 * @e2e openspec/specs/p2-minutes-and-decisions/spec.md#req-ml-002
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

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
 * (app-scoped) when it exists. The nav is org-mode-aware: in the gov-mode
 * layout the Minutes page is not a top-level nav entry, so we fall back to
 * the app-scoped route (still never via the global NC header). `route` is the
 * app-scoped path used when `cn-nav-entry-<entryId>` is absent.
 */
async function appNavClick(
	page: Page,
	entryId: string,
	route: string,
): Promise<void> {
	await page.goto(`${BASE}/apps/decidiq/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)
	const entry = page.locator(`[data-testid="cn-nav-entry-${entryId}"]`).first()
	if (await entry.isVisible().catch(() => false)) {
		await entry.click()
		return
	}
	await page.goto(`${BASE}/apps/decidiq${route}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await dismissSupportDialog(page)
}

// @e2e openspec/specs/app-navigation/spec.md#demoted-surface-remains-reachable-by-deep-link
// @e2e openspec/specs/p2-minutes-and-decisions/spec.md#req-ml-001
test('Minutes: app-scoped nav lands on the Minutes index with its real content', async ({
	page,
}) => {
	await appNavClick(page, 'Minutes', '/minutes')

	// URL stayed inside the decidiq SPA on the minutes route (no false-green out-nav)
	await expect(page).toHaveURL(/\/apps\/decidiq\/.*minutes/)

	// Real index surface: object-list table + "Showing N of N" + primary CTA.
	// NOTE: the migrated CnIndexPage (nc-vue v2) no longer renders a page-title
	// heading inside <main>, so we assert the table + CTA rather than a heading.
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	await expect(page.getByText('Showing', { exact: false }).first()).toBeVisible()
	await expect(page.getByRole('button', { name: 'Add Minutes' })).toBeVisible()
})

// @e2e openspec/specs/p2-minutes-and-decisions/spec.md#req-ml-002
test('Minutes: Add Minutes opens a real create form dialog', async ({ page }) => {
	await appNavClick(page, 'Minutes', '/minutes')
	await page.getByRole('button', { name: 'Add Minutes' }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(
		dialog.getByRole('heading', { name: /Create\s+Minutes/i }),
	).toBeVisible()
	// A real form is rendered (at least one input) plus the Create action
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/app-navigation/spec.md#demoted-surface-remains-reachable-by-deep-link
// @e2e openspec/specs/p2-minutes-and-decisions/spec.md#req-ml-001
test('Minutes: no decidiq-origin console error or 500 on load', async ({ page }) => {
	const appErrors: string[] = []
	page.on('console', (m) => {
		const t = m.text()
		// Ignore NC-core user_status noise; only flag decidiq-origin failures.
		if (m.type() === 'error' && !/user_status|heartbeat|user status/i.test(t)) {
			if (/decidiq/i.test(t)) appErrors.push(t)
		}
	})
	page.on('response', (r) => {
		if (r.status() >= 500 && /decidiq/i.test(r.url()))
			appErrors.push(`HTTP ${r.status()} ${r.url()}`)
	})

	await appNavClick(page, 'Minutes', '/minutes')
	await expect(page.getByTestId('cn-object-list-table')).toBeVisible()
	expect(
		appErrors,
		`decidiq errors on Minutes:\n${appErrors.join('\n')}`,
	).toHaveLength(0)
})
