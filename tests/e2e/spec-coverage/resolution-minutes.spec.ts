/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — resolution-minutes spec (minutes-ui-v1).
 *
 * Drives the real UI surfaces shipped by minutes-ui-v1: the live
 * minute-taking panel in the LiveMeeting view (per-agenda-item notes with
 * debounced autosave + the action-item capture shortcut), the approval
 * workflow widget on the Minutes detail page (submit / approve /
 * reject-with-comment / correction suggestions), and the Documents widget
 * (document generation + notarial proof package).
 *
 * Both are WIDGETS, not sidebar tabs: MinutesDetail declares them in its
 * `config.widgets` and wires them through its `slots` map, so they render
 * inline. See openMinutesPanel() for why that distinction cost five tests.
 *
 * Fixtures are seeded through the OpenRegister object API (setup only —
 * all assertions go through the UI) and torn down per spec run. No test here
 * skips: every surface it drives is declared in the shipped manifest, so an
 * absent one is a regression to report rather than a deployment to excuse.
 *
 * @e2e openspec/specs/resolution-minutes/spec.md#take-structured-minutes-during-a-meeting
 * @e2e openspec/specs/resolution-minutes/spec.md#record-action-items-during-minute-taking
 * @e2e openspec/specs/resolution-minutes/spec.md#distribute-draft-minutes-for-review
 * @e2e openspec/specs/resolution-minutes/spec.md#approve-board-minutes-digitally
 * @e2e openspec/specs/resolution-minutes/spec.md#reject-minutes-back-to-draft-with-a-comment
 * @e2e openspec/specs/resolution-minutes/spec.md#generate-minutes-document-from-meeting-data
 * @e2e openspec/specs/resolution-minutes/spec.md#provide-proof-of-proper-adoption-for-notarial-deed
 */
import type { Locator, Page } from '@playwright/test'
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
let agendaItemId = ''
let minutesId = ''

/** Seed one opened meeting with a discussion agenda item + draft minutes. */
async function seedFixture(page: Page): Promise<void> {
	const tag = `e2e-minutes-${ledger.runId}`

	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-meeting`,
		meetingType: 'regular',
		scheduledDate: '2026-09-01T10:00:00Z',
		meetingMode: 'in-person',
		lifecycle: 'opened',
		quorumRequired: 0,
	})
	meetingId = objId(meeting)

	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag}-agenda-item`,
		itemType: 'discussion',
		orderNumber: 1,
		meeting: meetingId,
	})
	agendaItemId = objId(item)

	const minutes = await createObject(page, ledger, 'minutes', {
		title: `${tag}-minutes`,
		lifecycle: 'draft',
		version: 1,
		content: '# Notulen\n\nSeeded by the resolution-minutes e2e spec.',
		meeting: meetingId,
	})
	minutesId = objId(minutes)
}

/**
 * Open the Minutes detail page and return one of its widget panels.
 *
 * ⚠️ MinutesDetail HAS NO TABS. `src/manifest.json`'s MinutesDetail page
 * declares `minutes-approval` and `minutes-documents` as `type: "custom"`
 * WIDGETS wired through the page's own `slots` map, so both render inline in
 * the page layout. Read off the live page, `getByRole('tab')` returns an EMPTY
 * list — there is no tab to click, and there never was.
 *
 * The helper this replaces looked for `getByRole('tab', { name })` with a
 * `getByRole('button', { name, exact: true })` fallback, and skipped the test
 * when neither appeared, blaming "the deployed decidiq predates minutes-ui-v1".
 * That reason was never true: the widgets ship in the build under test, and the
 * page renders `minutes-approval-tab` and `minutes-document-tab` on load.
 * Because the button fallback matched on some runs and not others, the five
 * tests using it skipped NONDETERMINISTICALLY — CI skipped all five while a
 * local run of the same commit skipped four and FAILED the fifth.
 *
 * 🔑 A skip whose stated reason is untrue is an invisible pass. So the gate is
 * gone rather than merely retimed: the panel is asserted, and its absence now
 * fails the test instead of quietly excusing it.
 */
async function openMinutesPanel(page: Page, testid: string): Promise<Locator> {
	await page.goto(`${BASE}/apps/decidiq/minutes/${minutesId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	const panel = page.getByTestId(testid)
	await expect(panel).toBeVisible({ timeout: 15_000 })
	return panel
}

test.beforeAll(async ({ browser }) => {
	ledger = newLedger()
	const page = await browser.newPage()
	await seedFixture(page)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e openspec/specs/resolution-minutes/spec.md#take-structured-minutes-during-a-meeting
test('live meeting: minutes panel offers per-agenda-item notes with autosave', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}/live`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// LiveMeeting.vue renders <MinutesPanel> unconditionally, so an absent
	// panel is a regression, not deploy drift. Asserted rather than skipped.
	const panel = page.getByTestId('minutes-panel')
	await expect(panel).toBeVisible({ timeout: 15_000 })

	// A draft Minutes record exists for this meeting → the editor renders
	// the per-agenda-item fields straight away (pre-populated template).
	const itemBlock = page.getByTestId(`minutes-panel-item-${agendaItemId}`)
	await expect(itemBlock).toBeVisible({ timeout: 10_000 })
	await expect(
		itemBlock.getByText(`e2e-minutes-${ledger.runId}-agenda-item`),
	).toBeVisible()

	// Type discussion notes → the debounced autosave reports success.
	await itemBlock
		.getByLabel('Discussion notes')
		.fill('Long discussion about the e2e budget.')
	await expect(page.getByTestId('minutes-panel-save-state')).toHaveText(
		'All changes saved',
		{ timeout: 15_000 },
	)

	// Decisions field persists through the same path.
	await itemBlock.getByLabel('Decisions').fill('Adopted unanimously (e2e).')
	await expect(page.getByTestId('minutes-panel-save-state')).toHaveText(
		'All changes saved',
		{ timeout: 15_000 },
	)
})

// @e2e openspec/specs/resolution-minutes/spec.md#record-action-items-during-minute-taking
test('live meeting: action-item capture shortcut creates a tracked action item', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}/live`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// LiveMeeting.vue renders <MinutesPanel> unconditionally, so an absent
	// panel is a regression, not deploy drift. Asserted rather than skipped.
	const panel = page.getByTestId('minutes-panel')
	await expect(panel).toBeVisible({ timeout: 15_000 })

	const itemBlock = page.getByTestId(`minutes-panel-item-${agendaItemId}`)
	await expect(itemBlock).toBeVisible({ timeout: 10_000 })
	await itemBlock.getByRole('button', { name: '+ Action item' }).click()

	const modal = page.getByTestId('minutes-action-item-modal')
	await expect(modal).toBeVisible()
	const actionTitle = `e2e-minutes-${ledger.runId} prepare budget proposal`
	// @nextcloud/vue v9: NcInputField (NcTextField's root) declares
	// `inheritAttrs: false` and spreads `$attrs` onto the inner <input>, so a
	// `data-testid` written on <NcTextField> lands ON the input element — there is
	// no input DESCENDANT of the test id to target.
	await modal.getByTestId('minutes-action-item-title').fill(actionTitle)
	await modal.getByTestId('minutes-action-item-save').click()
	await expect(modal).not.toBeVisible({ timeout: 10_000 })

	// The action item is now a real OR object linked into action tracking
	// (fixture-style verification through the object API; UI created it).
	const resp = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidiq/action-item?_limit=200`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.ok()).toBeTruthy()
	const body = await resp.json()
	const items = body.results ?? body.items ?? []
	const created = items.find((i: any) => (i.title ?? '') === actionTitle)
	expect(
		created,
		'captured action item exists in the action tracking store',
	).toBeTruthy()
	const createdId = created.id ?? created['@self']?.id
	if (createdId) {
		;(ledger.created['action-item'] ??= []).push(createdId)
	}
})

// @e2e openspec/specs/resolution-minutes/spec.md#distribute-draft-minutes-for-review
// @e2e openspec/specs/resolution-minutes/spec.md#reject-minutes-back-to-draft-with-a-comment
test('approval tab: submit for review, then reject back to draft with a mandatory comment', async ({
	page,
}) => {
	// Navigates, runs a lifecycle action, waits for the record to refetch, then
	// drives a modal — more than CI's 20s per-test budget allows. Measured: at
	// 20s it fails on whichever click is in flight when the budget expires, and
	// passes with room to spare at 90s. test.slow() triples this test's budget
	// instead of raising it for all 252.
	test.slow()

	const tab = await openMinutesPanel(page, 'minutes-approval-tab')

	// Draft → submit for review.
	await tab.getByTestId('minutes-action-submit').click()
	await expect(tab.getByTestId('minutes-action-approve')).toBeVisible({
		timeout: 10_000,
	})

	// Reject requires a comment: the confirm stays disabled until one is typed.
	await tab.getByTestId('minutes-action-reject').click()
	const rejectModal = page.getByTestId('minutes-reject-modal')
	await expect(rejectModal).toBeVisible()
	await expect(rejectModal.getByTestId('minutes-reject-confirm')).toBeDisabled()
	// NcTextArea (v9) also spreads $attrs onto the inner <textarea>, so the test
	// id IS the textarea — see the NcTextField note above.
	await rejectModal
		.getByTestId('minutes-reject-comment')
		.fill('Attendance list incomplete')
	await rejectModal.getByTestId('minutes-reject-confirm').click()

	// Back in draft: the rejection comment is surfaced and submit is offered again.
	await expect(tab.getByTestId('minutes-last-rejection')).toContainText(
		'Attendance list incomplete',
		{ timeout: 10_000 },
	)
	await expect(tab.getByTestId('minutes-action-submit')).toBeVisible()
})

// @e2e openspec/specs/resolution-minutes/spec.md#distribute-draft-minutes-for-review
test('approval tab: participants can suggest corrections and the chair resolves them', async ({
	page,
}) => {
	// Navigates, runs a lifecycle action, waits for the record to refetch, then
	// drives a modal — more than CI's 20s per-test budget allows. Measured: at
	// 20s it fails on whichever click is in flight when the budget expires, and
	// passes with room to spare at 90s. test.slow() triples this test's budget
	// instead of raising it for all 252.
	test.slow()

	const tab = await openMinutesPanel(page, 'minutes-approval-tab')

	await tab.getByTestId('minutes-correction-add').click()
	const modal = page.getByTestId('minutes-correction-modal')
	await expect(modal).toBeVisible()
	await expect(modal.getByTestId('minutes-correction-confirm')).toBeDisabled()
	await modal
		.getByTestId('minutes-correction-text')
		.fill('The vote count for item 5 should read 12 in favour')
	await modal.getByTestId('minutes-correction-confirm').click()

	// The suggestion lists as Proposed and offers chair resolution actions.
	await expect(
		tab.getByText('The vote count for item 5 should read 12 in favour'),
	).toBeVisible({ timeout: 10_000 })
	// ⚠️ The accessible name is "Accept correction", NOT "Accept": the button
	// carries `:aria-label="t('decidiq', 'Accept correction')"`, and an aria-label
	// REPLACES the text content when the accessible name is computed. Asking for
	// `{ name: 'Accept', exact: true }` could therefore never match — which no
	// run ever reported, because this test only ever reached here on the rare
	// occasions the old tab-hunting guard let it past its skip.
	await tab
		.getByRole('button', { name: 'Accept correction', exact: true })
		.first()
		.click()
	await expect(tab.getByText('Accepted', { exact: true }).first()).toBeVisible({
		timeout: 10_000,
	})
})

// @e2e openspec/specs/resolution-minutes/spec.md#approve-board-minutes-digitally
test('approval tab: approving review minutes locks editing and records the approval', async ({
	page,
}) => {
	// Navigates, runs a lifecycle action, waits for the record to refetch, then
	// drives a modal — more than CI's 20s per-test budget allows. Measured: at
	// 20s it fails on whichever click is in flight when the budget expires, and
	// passes with room to spare at 90s. test.slow() triples this test's budget
	// instead of raising it for all 252.
	test.slow()

	const tab = await openMinutesPanel(page, 'minutes-approval-tab')

	// Reach review (the earlier reject test returned the record to draft).
	if (await becomesVisible(tab.getByTestId('minutes-action-submit'), 5_000)) {
		await tab.getByTestId('minutes-action-submit').click()
	}
	await tab.getByTestId('minutes-action-approve').click()

	// Approved: the next forward action is sign, and the correction window
	// is closed (the suggest button disappears — approved minutes are locked).
	await expect(tab.getByTestId('minutes-action-sign')).toBeVisible({
		timeout: 10_000,
	})
	await expect(tab.getByTestId('minutes-correction-add')).not.toBeVisible()
})

// @e2e openspec/specs/resolution-minutes/spec.md#generate-minutes-document-from-meeting-data
test('documents tab: generate document persists into the meeting folder and lists it', async ({
	page,
}) => {
	const tab = await openMinutesPanel(page, 'minutes-document-tab')

	await tab.getByTestId('minutes-document-generate').click()

	// Either the stored-path confirmation (markdown) or the honest
	// Docudesk-fallback note appears — never a silent nothing.
	const result = page
		.getByTestId('minutes-document-result')
		.or(page.getByTestId('minutes-document-note'))
	await expect(result.first()).toBeVisible({ timeout: 20_000 })

	// The generated document is recorded on the Minutes object and listed.
	await expect(tab.getByText('Generated documents')).toBeVisible()
	await expect(tab.locator('.decidiq-tab__document').first()).toBeVisible({
		timeout: 10_000,
	})
})

// @e2e openspec/specs/resolution-minutes/spec.md#provide-proof-of-proper-adoption-for-notarial-deed
test('documents tab: notarial proof package is assembled and hash-sealed', async ({
	page,
}) => {
	const tab = await openMinutesPanel(page, 'minutes-document-tab')

	await tab.getByTestId('minutes-proof-package').click()
	await expect(page.getByTestId('minutes-proof-result')).toContainText('SHA-256', {
		timeout: 20_000,
	})
})
