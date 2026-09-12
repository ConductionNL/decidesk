/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Engagement index page (genuine behavioural).
 *
 * The Engagement nav route had no dedicated spec. Drives the page via
 * the app's LEFT navigation (cn-nav-entry-Engagement, not the global
 * NC header), then asserts the real cn-index-page surface (heading,
 * Cards/Table view toggle, primary CTA) and the create modal.
 *
 * @e2e openspec/specs/app-navigation/spec.md#demoted-surface-remains-reachable-by-deep-link
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

/**
 * Navigate to a decidiq page, preferring the APP's left navigation entry
 * (app-scoped) when it exists. The nav is org-mode-aware: in the gov-mode
 * layout the Engagement page is not a top-level nav entry, so we fall back to
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
test('Engagement: app-scoped nav lands on the index with its real content', async ({
	page,
}) => {
	await appNavClick(page, 'Engagement', '/engagement')

	await expect(page).toHaveURL(/\/apps\/decidiq\/.*engagement/)
	// NOTE: the migrated CnIndexPage (nc-vue v2) no longer renders a page-title
	// heading inside <main>; assert the index-page container + actions bar + CTA.
	const indexPage = page.getByTestId('cn-index-page').first()
	await expect(indexPage).toBeVisible()
	const actionsBar = page.getByTestId('cn-actions-bar').first()
	await expect(actionsBar).toBeVisible()
	await expect(page.getByTestId('cn-cta-primary').first()).toBeVisible()
})

// No e2e citation on purpose. No spec describes creating an EngagementRecord
// from this index: p4-collaboration REQ-PE-001 captures speech through the
// meeting's SpeechCaptureDialog. The gap is tracked in decidiq#1277.
test('Engagement: primary CTA opens a real create form dialog', async ({ page }) => {
	await appNavClick(page, 'Engagement', '/engagement')
	await page.getByTestId('cn-cta-primary').first().click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	// The create dialog heading is schema-derived: engagement-record → "Create EngagementRecord".
	await expect(
		dialog.getByRole('heading', { name: /Create\s+EngagementRecord/i }),
	).toBeVisible()
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/app-navigation/spec.md#demoted-surface-remains-reachable-by-deep-link
test('Engagement: no decidiq-origin console error or 500 on load', async ({
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

	await appNavClick(page, 'Engagement', '/engagement')
	await expect(page.getByTestId('cn-index-page').first()).toBeVisible()
	expect(
		appErrors,
		`decidiq errors on Engagement:\n${appErrors.join('\n')}`,
	).toHaveLength(0)
})
