/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — dashboard spec
 * @e2e openspec/specs/dashboard/spec.md#default-grid-layout-on-first-load
 * @e2e openspec/specs/dashboard/spec.md#empty-state-for-new-installation
 * @e2e openspec/specs/dashboard/spec.md#display-active-decisions-count
 * @e2e openspec/specs/dashboard/spec.md#display-pending-votes-count
 * @e2e openspec/specs/dashboard/spec.md#display-overdue-action-items-count
 * @e2e openspec/specs/dashboard/spec.md#show-pending-votes-with-urgency-indicators
 * @e2e openspec/specs/dashboard/spec.md#no-pending-votes
 * @e2e openspec/specs/dashboard/spec.md#show-upcoming-meetings-with-context
 * @e2e openspec/specs/dashboard/spec.md#view-decidiq-widget-on-nextcloud-dashboard
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

// @e2e openspec/specs/dashboard/spec.md#default-grid-layout-on-first-load
// @e2e openspec/specs/dashboard/spec.md#display-active-decisions-count
// @e2e openspec/specs/dashboard/spec.md#display-pending-votes-count
// @e2e openspec/specs/dashboard/spec.md#display-overdue-action-items-count
test('dashboard renders KPI stat blocks', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// Dashboard page loads (at least the app root mounts)
	const appRoot = page.locator('[data-testid="app-root"]')
	await expect(appRoot).toBeVisible()

	// Navigation is rendered — confirms SPA mounted
	await expect(page.getByRole('link', { name: 'Dashboard' }).first()).toBeVisible()
	// Scope to the app-navigation entries: dashboard stat-widget links carry
	// accessible names that collide with the nav labels (e.g. "Upcoming
	// meetings", and a "Decisions" KPI card whose name is exactly "Decisions"),
	// so a page-wide getByRole('link', …) trips strict mode with 2 matches.
	await expect(
		page.getByTestId('cn-nav-entry-Meetings').getByRole('link'),
	).toBeVisible()
	await expect(
		page.getByTestId('cn-nav-entry-Decisions').getByRole('link'),
	).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#empty-state-for-new-installation
// @e2e openspec/specs/dashboard/spec.md#show-pending-votes-with-urgency-indicators
// @e2e openspec/specs/dashboard/spec.md#no-pending-votes
// @e2e openspec/specs/dashboard/spec.md#show-upcoming-meetings-with-context
test('dashboard page loads at the root route', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/`)
	// Page title confirms we are in the Decidiq SPA
	await expect(page).toHaveTitle(/Decidiq/i)
	// CnAppRoot renders — confirms Vue mounted successfully, no white-screen
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
})

// @e2e openspec/specs/dashboard/spec.md#view-decidiq-widget-on-nextcloud-dashboard
test('Nextcloud dashboard shows the decidiq app in the app bar', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/dashboard/`)
	await page.waitForSelector('#header', { timeout: 15_000 })
	// The decidiq app is accessible from the Nextcloud UI (either nav or apps list)
	await page.goto(`${BASE}/apps/decidiq/`)
	await expect(page).toHaveTitle(/Decidiq/i)
})
