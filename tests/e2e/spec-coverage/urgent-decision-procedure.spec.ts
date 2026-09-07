/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — urgent-decision-procedure spec
 *
 * @e2e openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-005-quick-filter-chipdropdown-labels-render-intact
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

// @e2e openspec/changes/ux-debt-rendering/specs/index-page-rendering-quality/spec.md#requirement-req-005-quick-filter-chipdropdown-labels-render-intact
//
// Regression guard, not a fix — see design.md Decision 5. The "All urgent"
// symptom reported by the live audit ("All u rgent", split mid-word) did not
// reproduce on the current build at any tested viewport: getComputedStyle()
// showed word-break/overflow-wrap: normal and ample container width. This
// test asserts the rendered dropdown label's textContent matches the
// manifest-declared quick-filter label EXACTLY, with no embedded line break,
// at three viewport widths — so a future regression (a CSS rule forcing a
// mid-word break, a container narrowed below the label's width) is caught
// even though there is no defect to fix today.
test('quick-filter dropdown label "All urgent" renders intact at every tested viewport', async ({
	page,
}) => {
	// 🔴 THIS TEST'S OWN WAITS EXCEED THE DEFAULT BUDGET, so it could not pass
	// reliably no matter how healthy the app was.
	//
	// playwright.config.ts sets `timeout: 20_000` per test. This one loops THREE
	// viewports, and each iteration navigates, then allows
	// `waitForSelector(15_000)` + `expect(select).toBeVisible({ 10_000 })` — 25s
	// of permitted waiting in the FIRST iteration alone. Measured on development
	// 2026-08-31 it failed as a bare `Test timeout of 20000ms exceeded`, which
	// reads like a slow or broken dropdown rather than a budget that was never
	// large enough for the work the test does.
	//
	// 90s matches the sibling multi-step specs (voting-rules.spec.ts) and leaves
	// the per-step waits above untouched, so a genuinely stuck dropdown still
	// fails on its OWN 10s assertion with a message naming the element, rather
	// than on a whole-test timeout that names nothing.
	test.setTimeout(90_000)

	const viewports = [
		{ width: 375, height: 800 },
		{ width: 900, height: 800 },
		{ width: 1280, height: 800 },
	]

	for (const viewport of viewports) {
		await page.setViewportSize(viewport)
		await page.goto(`${BASE}/apps/decidiq/urgent-decisions`)
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

		// Dropdown-mode quick filter renders as an NcSelect (vue-select under the
		// hood); the selected label lives in `.vs__selected` inside the bar's own
		// `.cn-quick-filter-bar__select` wrapper (CnQuickFilterBar.vue).
		const select = page.locator('.cn-quick-filter-bar__select')
		await expect(select).toBeVisible({ timeout: 10_000 })

		const selected = select.locator('.vs__selected').first()
		await expect(selected).toBeVisible()

		const label = await selected.textContent()
		expect(
			label?.trim(),
			`quick-filter label should read "All urgent" intact at ${viewport.width}px, got ${JSON.stringify(label)}`,
		).toBe('All urgent')

		// No embedded line break / word-split artefact in the raw text.
		expect(label).not.toMatch(/\n/)
	}
})
