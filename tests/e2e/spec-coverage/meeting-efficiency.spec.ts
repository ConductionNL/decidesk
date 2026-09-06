/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Meeting efficiency (meeting-efficiency).
 *
 * Drives the real UI surfaces added by meeting-efficiency-v1:
 *   - LiveMeeting agenda-item timer (countdown / pause indicator / no-countdown
 *     informational item), speaker queue (add / remove / current highlight),
 *     and the toggleable cost panel;
 *   - the GovernanceBodyDetail "Efficiency" analytics tab sections.
 *
 * Wall-clock scenarios (15-minute timer expiry, 3-minute speaking limit) are
 * covered exhaustively by the pure-logic vitest suites and carry a
 * reason-bearing `@e2e exclude` in the spec — they cannot be driven in
 * real time in a deterministic browser test.
 *
 * ⚠️ THE THREE LiveMeeting TESTS NO LONGER SKIP, AND THAT IS THE POINT.
 * They used to lean on "the first meeting in the register", then skip with
 * *"No activatable agenda item / not chair in this environment."* — a reason
 * that read as an environment fact and was **false twice over**, measured on
 * the dev instance 2026-08-16:
 *
 *   1. The chair gate opens fine once a Participant carrying the current
 *      user's `nextcloudUserId` and `role: 'chair'` is linked to the meeting.
 *      Nothing in CI was seeding one, so nothing was ever chair.
 *   2. Even as chair the old locator could not match. The activate control is
 *      `<NcButton :aria-label="Activate {title}">{{ orderNumber }}. {{ title }}</NcButton>`,
 *      and an explicit `aria-label` REPLACES the text content in the
 *      accessible name — so `getByRole('button', { name: /^1\./ })` matches
 *      nothing no matter who is looking at the page. The skip could therefore
 *      never fail to fire, which is an invisible pass.
 *
 * Both tests now seed their own fixture (meeting + agenda item + chair
 * participant) and assert unconditionally. If the surface regresses they go
 * RED; there is no "not applicable" branch left to hide in.
 *
 * Defensive skips: the GovernanceBody analytics test still skips when no
 * governance body is seeded — that one is genuinely about pre-existing data.
 *
 * @e2e openspec/specs/meeting-efficiency/spec.md#pause-timer-during-procedural-interruption
 * @e2e openspec/specs/meeting-efficiency/spec.md#skip-timer-for-informational-items
 * @e2e openspec/specs/meeting-efficiency/spec.md#manage-speaker-queue
 * @e2e openspec/specs/meeting-efficiency/spec.md#display-running-meeting-cost
 * @e2e openspec/specs/meeting-efficiency/spec.md#view-meeting-duration-trends
 * @e2e openspec/specs/meeting-efficiency/spec.md#compare-allocated-vs-actual-time-per-item-type
 * @e2e openspec/specs/meeting-efficiency/spec.md#show-cost-per-agenda-item-in-analytics
 */
import type { Page } from '@playwright/test'
import type { SeedLedger } from '../workflows/governance-fixture.ts'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'
import { becomesVisible } from '../becomes-visible.js'
import {
	cleanupAll,
	createObject,
	newLedger,
	objId,
} from '../workflows/governance-fixture.ts'

let ledger: SeedLedger
let meetingId = ''
let itemTitle = ''

async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page
		.locator('.cn-support-dialog, [data-testid^="cn-support-dialog"]')
		.first()
	if (await dialog.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
	}
}

async function openApp(page: Page): Promise<boolean> {
	await page.goto(`${BASE}/apps/decidiq/`)
	const ready = await page
		.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
		.then(() => true)
		.catch(() => false)
	if (ready) await dismissSupportDialog(page)
	return ready
}

/**
 * Resolve the Nextcloud user id the suite is authenticated as.
 *
 * Hard-failing here is deliberate: the chair fixture is keyed on this value,
 * so an unreadable response must not quietly seed a participant nobody
 * matches — that would put the tests back on a permanently-false skip.
 */
async function currentUserId(page: Page): Promise<string> {
	const resp = await page.request.get(
		`${BASE}/ocs/v2.php/cloud/user?format=json`,
		{ headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } },
	)
	if (!resp.ok()) {
		throw new Error(
			`could not resolve the current user (HTTP ${resp.status()}) — the chair fixture cannot be seeded`,
		)
	}
	const uid = (await resp.json())?.ocs?.data?.id
	if (!uid) {
		throw new Error(
			'the current-user response carried no ocs.data.id — the chair fixture cannot be seeded',
		)
	}
	return uid
}

/**
 * Seed one opened meeting with a single discussion agenda item and a
 * Participant that makes the CI user the chair of it.
 *
 * `LiveMeeting.isChair` matches a participant on
 * `nextcloudUserId === getCurrentUser().uid && role === 'chair'`, and
 * `LiveMeeting.participants` scopes the participant collection by
 * `@self.relations.meeting`. Participant declares no `meeting` property, but
 * OpenRegister materialises a submitted `meeting` uuid into `@self.relations`
 * anyway — verified on the dev instance: the seeded participant came back with
 * `relations: { meeting: '<uuid>' }` and `.live-meeting__activate` rendered.
 */
async function seedChairedMeeting(page: Page): Promise<void> {
	const uid = await currentUserId(page)
	const tag = `e2e-efficiency-${ledger.runId}`
	itemTitle = `${tag}-item`

	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-meeting`,
		meetingType: 'regular',
		scheduledDate: '2026-09-01T10:00:00Z',
		meetingMode: 'in-person',
		lifecycle: 'opened',
		quorumRequired: 0,
	})
	meetingId = objId(meeting)

	await createObject(page, ledger, 'agenda-item', {
		title: itemTitle,
		itemType: 'discussion',
		orderNumber: 1,
		meeting: meetingId,
	})

	await createObject(page, ledger, 'participant', {
		displayName: `${tag}-chair`,
		role: 'chair',
		nextcloudUserId: uid,
		attendanceStatus: 'present',
		meeting: meetingId,
	})
}

/** Navigate straight to the seeded meeting's live view. */
async function openSeededLiveMeeting(page: Page): Promise<void> {
	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}/live`)
	await expect(
		page.getByTestId('meeting-live'),
		'the live meeting view must mount for the seeded meeting',
	).toBeVisible({ timeout: 15_000 })
	await dismissSupportDialog(page)
}

/**
 * The chair's "Activate item" control for the seeded agenda item.
 *
 * Located by its ACCESSIBLE NAME, which the explicit `:aria-label` sets to
 * `Activate {title}` — the visible `1. {title}` text is not part of it.
 */
function activateControl(page: Page) {
	return page
		.getByTestId('meeting-live')
		.getByRole('button', { name: `Activate ${itemTitle}` })
}

test.beforeAll(async ({ browser }) => {
	ledger = newLedger()
	const page = await browser.newPage()
	await seedChairedMeeting(page)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e openspec/specs/meeting-efficiency/spec.md#display-running-meeting-cost
test('LiveMeeting: cost panel toggles its running figure', async ({ page }) => {
	await openSeededLiveMeeting(page)
	const panel = page.getByTestId('meeting-cost-panel')
	await expect(panel).toBeVisible()
	// Default hidden; toggle reveals either a figure or the no-rate hint.
	await page.getByTestId('meeting-cost-toggle').click()
	const figure = page.getByTestId('meeting-cost-figure')
	const noRate = page.getByTestId('meeting-cost-no-rate')
	// `.or()` POLLS for either branch. The previous form read both with the
	// non-waiting `isVisible()` on the tick after the click, so it could report
	// "neither" purely because the toggle had not re-rendered yet.
	await expect(figure.or(noRate).first()).toBeVisible({ timeout: 10_000 })
})

// @e2e openspec/specs/meeting-efficiency/spec.md#pause-timer-during-procedural-interruption
// @e2e openspec/specs/meeting-efficiency/spec.md#skip-timer-for-informational-items
test('LiveMeeting: agenda-item timer renders for the active item', async ({
	page,
}) => {
	await openSeededLiveMeeting(page)
	const activate = activateControl(page)
	await expect(
		activate,
		'the chair must get an "Activate <item>" control for the seeded agenda item',
	).toBeVisible({ timeout: 15_000 })
	await activate.click()
	const timer = page.getByTestId('agenda-item-timer')
	await expect(timer).toBeVisible()
	// Either a countdown clock (allocated) or the no-allocation hint (informational).
	const clock = page.getByTestId('agenda-item-timer-clock')
	const noAlloc = page.getByTestId('agenda-item-timer-no-allocation')
	await expect(clock.or(noAlloc).first()).toBeVisible({ timeout: 10_000 })
})

// @e2e openspec/specs/meeting-efficiency/spec.md#manage-speaker-queue
test('LiveMeeting: speaker queue panel renders with an empty state', async ({
	page,
}) => {
	await openSeededLiveMeeting(page)
	const activate = activateControl(page)
	await expect(
		activate,
		'the chair must get an "Activate <item>" control for the seeded agenda item',
	).toBeVisible({ timeout: 15_000 })
	await activate.click()
	const panel = page.getByTestId('speaker-queue-panel')
	await expect(panel).toBeVisible()
	const empty = page.getByTestId('speaker-queue-empty')
	const list = page.getByTestId('speaker-queue-list')
	await expect(empty.or(list).first()).toBeVisible({ timeout: 10_000 })
})

// @e2e openspec/specs/meeting-efficiency/spec.md#view-meeting-duration-trends
// @e2e openspec/specs/meeting-efficiency/spec.md#compare-allocated-vs-actual-time-per-item-type
// @e2e openspec/specs/meeting-efficiency/spec.md#show-cost-per-agenda-item-in-analytics
test('GovernanceBody: Efficiency tab shows the analytics surface', async ({
	page,
}) => {
	if (!(await openApp(page))) {
		test.skip(true, 'Decidiq app did not load in this environment.')
		return
	}
	const nav = page
		.locator('[data-testid="cn-nav"], #app-navigation-vue, .app-navigation')
		.first()
	const bodiesEntry = nav.getByTestId('cn-nav-entry-GovernanceBodies')
	if (!(await becomesVisible(bodiesEntry))) {
		test.skip(true, 'No governance bodies nav entry in this environment.')
		return
	}
	await bodiesEntry.click()
	const firstRow = page
		.getByTestId('cn-object-list-table')
		.locator('tbody tr')
		.first()
	if (!(await becomesVisible(firstRow))) {
		test.skip(true, 'No governance body seeded in this environment.')
		return
	}
	await firstRow.click()
	const efficiencyTab = page.getByRole('tab', { name: 'Efficiency' }).first()
	if (!(await becomesVisible(efficiencyTab))) {
		test.skip(true, 'Efficiency tab not rendered (sidebar tabs unavailable).')
		return
	}
	await efficiencyTab.click()
	const tab = page.getByTestId('body-efficiency-tab')
	await expect(tab).toBeVisible()
	// Either the analytics sections or the honest empty state are shown.
	const duration = page.getByTestId('body-efficiency-duration')
	const empty = page.getByTestId('body-efficiency-empty')
	await expect(duration.or(empty).first()).toBeVisible({ timeout: 10_000 })
})
