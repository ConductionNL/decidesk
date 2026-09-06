/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Documentation screenshot capture suite — decidiq.
 *
 * This spec is *not* a regression test — it drives the Decidiq UI
 * through the flows documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default regression run via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot screenshots on every push.
 *
 * Authentication: `playwright.config.ts` wires `globalSetup` (a one-time
 * Nextcloud login → storage state) and `use.storageState`, so the
 * `page` fixture here arrives already signed in.
 *
 * Data dependency: Decidiq stores meetings / motions / votes / minutes
 * in OpenRegister. On an instance with no Decidiq data the list views
 * still render (empty state) and the *Add Item* dialog still opens, so
 * the structural screenshots below capture cleanly. The flow-detail
 * screenshots (a populated agenda, a closed voting round, signed
 * minutes) need real objects; until seed data lands those steps fall
 * back to the relevant list/empty-state view, and the markdown pages
 * that reference the as-yet-uncaptured PNGs warn under
 * `onBrokenMarkdownImages: 'warn'` rather than failing the docs build.
 *
 * Test-id additions live in decidiq's own Vue components (detail-page
 * sidebar tabs, the live-meeting view, the admin settings root). Most
 * of the capture-relevant chrome on the index/dashboard/settings pages
 * is rendered by `@conduction/nextcloud-vue` (CnAppRoot / CnPageRenderer
 * / CnObjectSidebar) and is targeted here by role/text — adding testids
 * to those would be a change in the shared library, out of scope for
 * this app.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { waitForContentReady } from './visual/_visual-helpers.ts'

const SHOT_ROOT = path.resolve(
	__dirname,
	'..',
	'..',
	'docs',
	'static',
	'screenshots',
	'tutorials',
)
const APP = '/apps/decidiq'

/**
 * Save a viewport screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(
	page: Page,
	track: 'user' | 'admin',
	file: string,
): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

/**
 * Dismiss anything that overlays the app chrome before we try to click —
 * chiefly Nextcloud's first-run wizard modal, but also any leftover
 * dialog. Best-effort: silently no-op when nothing's there.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard
			.getByRole('button', { name: /close|got it|finish|skip/i })
			.first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
	const stray = page.locator('[role="dialog"]:not(#firstrunwizard)')
	if (
		await stray
			.first()
			.isVisible()
			.catch(() => false)
	) {
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
	}
}

/** Navigate to a Decidiq (or absolute) route and settle. */
async function go(page: Page, route: string): Promise<void> {
	// `/settings/` joins `/apps/` as a server-absolute prefix: since ADR-079 D1
	// the app's configuration surface is a Nextcloud settings section at
	// /settings/admin/decidiq, not an in-app route, so it must not be prefixed
	// with APP. Anything else is still an app-relative route.
	const isAbsolute = route.startsWith('/apps/') || route.startsWith('/settings/')
	const url = isAbsolute ? route : `${APP}${route}`

	// ADR-074 rule 4: 'networkidle' NEVER settles on Nextcloud — long-polling
	// endpoints and the notification stream keep a request open indefinitely.
	// The old line was `waitForLoadState('networkidle').catch(() => {})`, whose
	// own comment admitted "idle never fires on some pages": it therefore
	// burned the FULL timeout on every navigation and then swallowed the
	// failure, so it bought a delay rather than a guarantee — and the
	// screenshot's real precondition (content painted) was never actually
	// asserted, only waited out.
	//
	// Replaced with the settle the rest of this repo already uses and trusts:
	// domcontentloaded on the navigation, then waitForContentReady(), which
	// asserts the header and content root are visible and polls spinners and
	// "Loading …" placeholders away. That is a positive signal about the page
	// rather than the absence of a signal about the network.
	await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => {
		/* tolerate a 404 — caller decides */
	})
	await waitForContentReady(page).catch(() => {
		/* a 404 has no app chrome */
	})
	await dismissOverlays(page)
	await page.waitForTimeout(900)
}

/**
 * Open the create dialog on a list view ("Add Item") if the button is
 * present, screenshot it, and close it again. Returns whether the dialog
 * appeared (it does on every list view; the dialog body is empty unless
 * the relevant schema is mapped — see the file header).
 */
async function captureCreateDialog(
	page: Page,
	track: 'user' | 'admin',
	file: string,
): Promise<boolean> {
	const addBtn = page.getByRole('button', { name: /Add Item/i }).first()
	if (!(await addBtn.isVisible().catch(() => false))) {
		return false
	}
	await addBtn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {
		/* no dialog */
	})
	await page.waitForTimeout(400)
	await shoot(page, track, file)
	const cancel = dialog.getByRole('button', { name: /Cancel/i }).first()
	if (await cancel.isVisible().catch(() => false)) {
		await cancel.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await page.waitForTimeout(300)
	return true
}

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '/')
		await shoot(page, 'user', '01-first-launch-01.png')
		await shoot(page, 'user', '01-first-launch-02.png')
		await shoot(page, 'user', '01-first-launch-03.png')
		await go(page, '/meetings')
		await shoot(page, 'user', '01-first-launch-04.png')
		expect(page.url()).toContain('/apps/decidiq')
	})

	test('UN schedule-meeting', async ({ page }) => {
		// docs/tutorials/user/02-schedule-meeting.md
		await go(page, '/meetings')
		const had = await captureCreateDialog(
			page,
			'user',
			'02-schedule-meeting-01.png',
		)
		if (had) {
			await captureCreateDialog(page, 'user', '02-schedule-meeting-02.png')
		}
		// Steps 3-5 (meeting detail / agenda builder / published agenda) need
		// a meeting object; the Meetings and Agenda-items lists stand in.
		await go(page, '/meetings')
		await shoot(page, 'user', '02-schedule-meeting-03.png')
		await go(page, '/agenda-items')
		await shoot(page, 'user', '02-schedule-meeting-04.png')
		await shoot(page, 'user', '02-schedule-meeting-05.png')
	})

	test('UN add-motion', async ({ page }) => {
		// docs/tutorials/user/03-add-motion.md
		await go(page, '/motions')
		const had = await captureCreateDialog(page, 'user', '03-add-motion-01.png')
		if (had) {
			await captureCreateDialog(page, 'user', '03-add-motion-02.png')
		}
		await go(page, '/motions')
		await shoot(page, 'user', '03-add-motion-03.png')
		await shoot(page, 'user', '03-add-motion-04.png')
		await shoot(page, 'user', '03-add-motion-05.png')
	})

	test('UN propose-amendment', async ({ page }) => {
		// docs/tutorials/user/04-propose-amendment.md — amendments hang off
		// motions; there is no standalone nav entry.
		await go(page, '/motions')
		await shoot(page, 'user', '04-propose-amendment-01.png')
		const had = await captureCreateDialog(
			page,
			'user',
			'04-propose-amendment-02.png',
		)
		if (!had) {
			await shoot(page, 'user', '04-propose-amendment-02.png')
		}
		await go(page, '/motions')
		await shoot(page, 'user', '04-propose-amendment-03.png')
		await shoot(page, 'user', '04-propose-amendment-04.png')
	})

	test('UN run-vote', async ({ page }) => {
		// docs/tutorials/user/05-run-vote.md — voting happens in the live
		// meeting view / a motion's Votes tab; both need objects. The
		// Meetings and Decisions lists stand in for steps 1-5.
		await go(page, '/meetings')
		await shoot(page, 'user', '05-run-vote-01.png')
		await shoot(page, 'user', '05-run-vote-02.png')
		await shoot(page, 'user', '05-run-vote-03.png')
		await go(page, '/decisions')
		await shoot(page, 'user', '05-run-vote-04.png')
		await shoot(page, 'user', '05-run-vote-05.png')
	})

	test('UN take-minutes', async ({ page }) => {
		// docs/tutorials/user/06-take-minutes.md
		await go(page, '/minutes')
		await shoot(page, 'user', '06-take-minutes-01.png')
		await shoot(page, 'user', '06-take-minutes-02.png')
		await shoot(page, 'user', '06-take-minutes-03.png')
		await shoot(page, 'user', '06-take-minutes-04.png')
		await go(page, '/action-items')
		await shoot(page, 'user', '06-take-minutes-05.png')
	})

	test('UN track-decisions', async ({ page }) => {
		// docs/tutorials/user/07-track-decisions.md
		await go(page, '/decisions')
		await shoot(page, 'user', '07-track-decisions-01.png')
		await shoot(page, 'user', '07-track-decisions-02.png')
		await go(page, '/action-items')
		await shoot(page, 'user', '07-track-decisions-03.png')
		await go(page, '/')
		await shoot(page, 'user', '07-track-decisions-04.png')
		await go(page, '/engagement')
		await shoot(page, 'user', '07-track-decisions-05.png')
	})

	test('UN ai-companion', async ({ page }) => {
		// docs/tutorials/user/08-ai-companion.md — the AI Chat Companion is a
		// separate Nextcloud surface (hydra ADR-034) and may not be present
		// on every instance. Capture the assistant route if it loads; the
		// Decidiq dashboard stands in otherwise.
		await go(page, '/apps/assistant/')
		if (!page.url().includes('/apps/assistant')) {
			await go(page, '/')
		}
		await shoot(page, 'user', '08-ai-companion-01.png')
		await shoot(page, 'user', '08-ai-companion-02.png')
		await shoot(page, 'user', '08-ai-companion-03.png')
		await shoot(page, 'user', '08-ai-companion-04.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('AN configure-workflow', async ({ page }) => {
		// docs/tutorials/admin/01-configure-workflow.md — governance bodies
		// have a manifest page but no nav entry; reach them by route.
		await go(page, '/governance-bodies')
		await shoot(page, 'admin', '01-configure-workflow-01.png')
		const had = await captureCreateDialog(
			page,
			'admin',
			'01-configure-workflow-02.png',
		)
		if (!had) {
			await shoot(page, 'admin', '01-configure-workflow-02.png')
		}
		await go(page, '/governance-bodies')
		await shoot(page, 'admin', '01-configure-workflow-03.png')
		await shoot(page, 'admin', '01-configure-workflow-04.png')
		await shoot(page, 'admin', '01-configure-workflow-05.png')
	})

	test('AN manage-members', async ({ page }) => {
		// docs/tutorials/admin/02-manage-members.md
		await go(page, '/governance-bodies')
		await shoot(page, 'admin', '02-manage-members-01.png')
		const had = await captureCreateDialog(
			page,
			'admin',
			'02-manage-members-02.png',
		)
		if (!had) {
			await shoot(page, 'admin', '02-manage-members-02.png')
		}
		await go(page, '/governance-bodies')
		await shoot(page, 'admin', '02-manage-members-03.png')
		await go(page, '/participants')
		await shoot(page, 'admin', '02-manage-members-04.png')
		await go(page, '/meetings')
		await shoot(page, 'admin', '02-manage-members-05.png')
	})

	test('AN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/03-admin-settings.md — Decidiq's app-level
		// configuration lives at /settings/admin/decidiq, in the Nextcloud
		// settings framework, and nowhere else (ADR-079 D1). The in-app
		// `/apps/decidiq/settings` twin this test used to shoot is deleted, so
		// screenshotting it would document a surface that no longer exists.
		// `/settings/...` is server-absolute — see the prefix rule in `go()`.
		await go(page, '/settings/admin/decidiq')
		await shoot(page, 'admin', '03-admin-settings-01.png')
		await page.evaluate(() => window.scrollTo(0, 0))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-02.png')
		const reimport = page
			.getByRole('button', { name: /Re-import configuration/i })
			.first()
		if (await reimport.isVisible().catch(() => false)) {
			await reimport.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-admin-settings-03.png')
		await shoot(page, 'admin', '03-admin-settings-04.png')
		const ori = page.getByText(/ORI[‑-]eindpunt|ORI endpoint/i).first()
		if (await ori.isVisible().catch(() => false)) {
			await ori.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		} else {
			await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-admin-settings-05.png')
		expect(page.url()).toContain('/settings/admin/decidiq')
	})
})
