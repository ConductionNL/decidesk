/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (not a fallback, not a console error), an entry whose `route` names
 * a page the app does not host renders a row that goes nowhere, and
 * `nav.includePersonalSettings: false` silently removed the entry that reaches
 * the user's notification preferences — in this very app.
 *
 * The three new reports are declarative `type: "dashboard"` pages over
 * decidiq's own register, which adds a fourth failure mode no manifest gate can
 * see: a widget whose `source` names a schema that does not exist renders its
 * card, its title and no value, silently. In THIS app that is a live risk,
 * because the schema slug is not the seed key — the seed keys are PascalCase
 * (Decision, VotingRound) and the slugs are kebab-case (decision,
 * voting-round). So the assertions below look for VALUES, not just for cards.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/apps/decidiq'

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * ⚠️ On a FRESH instance CnSetupWizard opens over the app and its modal
 * intercepts pointer events, so every nav click resolves its locator and then
 * times out after 30s — a failure that reads like the navigation is broken.
 * Tests that navigate by URL pass, which is what makes this so easy to miss:
 * only the click-through tests fail, and only on a clean install.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await dismissSetupWizard(page)
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers: ADR-114 fixes the sequence and
		// openregister runs its footer at 1/2 while pipelinq runs 160/200/230.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports lists all four reports', async ({ page }) => {
		const nav = page.locator('[data-testid="cn-nav"]')
		await nav
			.locator('[data-testid="cn-nav-entry-ReportsMenu"] a')
			.first()
			.click()
		await expect(page).toHaveURL(/\/apps\/decidiq\/reports(\?|$)/, {
			timeout: 15_000,
		})

		for (const label of [
			'Decisions',
			'Voting',
			'Meetings and attendance',
			'Engagement',
		]) {
			await expect(
				page.getByText(label, { exact: false }).first(),
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('Engagement is reachable from Reports, which it never was from the menu', async ({
		page,
	}) => {
		// Engagement already existed as an index over engagement-record and had
		// NO menu entry at all — reachable only by someone who already knew the
		// URL. Carding it GIVES an entry point rather than moving one, so this
		// asserts the arrival rather than a move.
		await page.goto(`${APP_BASE}/engagement`)
		await expect(page).toHaveURL(/\/engagement(\?|$)/, { timeout: 15_000 })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('the decisions report renders real numbers, not empty cards', async ({
		page,
	}) => {
		// The point of this test. Every widget is declarative over the decidiq
		// register, so naming the seed key `Decision` instead of the slug
		// `decision` yields a card that renders its chrome and no value.
		await page.goto(`${APP_BASE}/reports/decisions`)
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page
				.locator('main, .app-content')
				.first()
				.getByText('Adopted', { exact: false })
				.first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('main, .app-content').first()).toContainText(
			/\d/,
			{ timeout: 30_000 },
		)
	})

	test('the voting report reads the kebab-case slug, not the PascalCase key', async ({
		page,
	}) => {
		// voting-round and vote, NOT VotingRound and Vote. If a later edit
		// "tidies" those back to the seed keys the cards go blank in place.
		await page.goto(`${APP_BASE}/reports/voting`)
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText('Rounds held', { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('main, .app-content').first()).toContainText(
			/\d/,
			{ timeout: 30_000 },
		)
	})

	test('the meetings report is reachable and titled', async ({ page }) => {
		await page.goto(`${APP_BASE}/reports/meetings`)
		await expect(page).toHaveURL(/\/reports\/meetings(\?|$)/, {
			timeout: 15_000,
		})
		await expect(
			page.getByText('Recorded absences', { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 })
	})

	test('Store opens the hosted store surface, which this app writes no backend for', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Store$/ })
			.first()
			.click()

		await expect(page).toHaveURL(/\/apps\/decidiq\/store(\?|$)/, {
			timeout: 15_000,
		})

		// The page is declarative: openregister hosts the store plane, so this
		// app ships NO store controller (ADR-080, ADR-114 Decision 4). With no
		// registry configured it renders the app's own items and makes NO
		// network call, so this must pass on a plain instance.
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})

		// This app set `nav.includePersonalSettings: false` with no replacement,
		// which put the user's notification preferences out of reach entirely.
		// The flag is gone; this is the test that notices if it comes back.
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/decidiq$/,
		)
	})
})
