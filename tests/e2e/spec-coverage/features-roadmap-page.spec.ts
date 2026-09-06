/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Features & roadmap page (genuine behavioural).
 *
 * The FeaturesRoadmap nav route had no dedicated spec. ia-six-clusters
 * (openspec/specs/app-navigation/spec.md#req-nav-010) removes the
 * FeaturesRoadmapMenu row as duplicate/filter-chip navigation, but the
 * page itself stays routable for deep links and e2e specs — so this
 * drives the page via the app-scoped direct route, falling back from
 * the (now absent) cn-nav-entry-FeaturesRoadmapMenu left-nav entry the
 * same way engagement-page.spec.ts and minutes-page.spec.ts do for
 * their own org-mode-conditional nav rows. Then asserts the real
 * roadmap surface: the "Features" heading and the "Show roadmap" /
 * "Suggest feature" CTAs.
 *
 * @e2e openspec/specs/dashboard/spec.md#view-the-features-and-roadmap-page
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
 * (app-scoped) when it exists. The nav is org-mode-aware and, per
 * ia-six-clusters, the FeaturesRoadmapMenu row is removed as duplicate
 * navigation while its page stays routable — so we fall back to the
 * app-scoped route (still never via the global NC header). `route` is the
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

// @e2e openspec/specs/dashboard/spec.md#view-the-features-and-roadmap-page
test('Features & roadmap: app-scoped nav lands on the roadmap surface', async ({
	page,
}) => {
	await appNavClick(page, 'FeaturesRoadmapMenu', '/features-roadmap')

	await expect(page).toHaveURL(/\/apps\/decidiq\/.*features-roadmap/)
	await expect(
		page.getByRole('heading', { name: 'Features', exact: true }),
	).toBeVisible()
	await expect(
		page.getByRole('button', { name: /Show roadmap/i }).first(),
	).toBeVisible()
	// A LINK, not a button, and deliberately so: it navigates to
	// github.com/ConductionNL/decidiq/issues/new. CnFeaturesAndRoadmapPage
	// renders it as an `<a href>` wearing `button-vue` classes, with no
	// `role="button"`, so `getByRole('button')` could never match it and this
	// assertion had been failing on every run. Asserting `link` matches the
	// semantics the component actually has, and the href is the part worth
	// pinning: a styled anchor that stops navigating is the real regression.
	const suggest = page.getByRole('link', { name: /Suggest feature/i }).first()
	await expect(suggest).toBeVisible()
	await expect(suggest).toHaveAttribute('href', /github\.com\/.*\/issues\/new/)
})

// @e2e openspec/specs/dashboard/spec.md#view-the-features-and-roadmap-page
test('Features & roadmap: no decidiq-origin console error or 500 on load', async ({
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

	await appNavClick(page, 'FeaturesRoadmapMenu', '/features-roadmap')
	await expect(
		page.getByRole('heading', { name: 'Features', exact: true }),
	).toBeVisible()
	expect(
		appErrors,
		`decidiq errors on Features & roadmap:\n${appErrors.join('\n')}`,
	).toHaveLength(0)
})
