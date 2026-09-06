/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * UI e2e for meeting transcription + AI-assisted draft minutes
 * (meeting-transcription-ai-minutes). Covers the NON-API scenarios per the
 * Playwright-UI / Newman-API split: the API/RBAC/data-shape scenarios are
 * @e2e-excluded inline in the spec deltas and covered by PHPUnit.
 *
 * PROVIDER determinism, DATA reality
 * ----------------------------------
 * Every decidiq transcription API call and the Transcript / AgendaItem reads
 * the panel makes are still intercepted with `page.route`, so the run never
 * depends on a live SpeechToText/AI provider. That is the "mocked provider
 * fixture" the change's tasks call for and it is unchanged.
 *
 * What changed (run 31083903075 burndown) is the MEETING the panel is mounted
 * on. The spec used to navigate to
 *
 *     ${BASE}/index.php/apps/decidiq/meetings/e2e-transcription-meeting
 *
 * — a hard-coded id for an object that has never existed, behind an
 * `/index.php/` prefix the SPA router cannot match. `src/main.js` builds its
 * history as `createWebHistory(generateUrl('/apps/decidiq'))`, and on the CI
 * instance `generateUrl` resolves to `/apps/decidiq` (no `index.php`), so the
 * requested path fell through to the router's catch-all
 * `{ path: '/:pathMatch(.*)*', redirect: '/' }` and the browser sat on the
 * DASHBOARD. The trace for that run confirms it: the only requests after the
 * navigation are dashboard KPI aggregations, and NOT ONE call to the
 * transcription `sources` endpoint. Every assertion then
 * spent the whole 20s per-test budget waiting on a panel that was never on the
 * page, which is why two of the three failures reported `Received: undefined`
 * (a timeout kills the expect before it ever resolves a value) and the third
 * reported "Target page … has been closed".
 *
 * So the panel is now mounted the way a user reaches it: a REAL seeded Meeting,
 * opened on its real detail route, with the transcription API still mocked.
 *
 * @e2e openspec/specs/meeting-transcription/spec.md#attach-an-uploaded-recording-with-consent
 * @e2e openspec/specs/meeting-transcription/spec.md#no-provider-degrades-gracefully
 * @e2e openspec/specs/meeting-transcription/spec.md#segments-grouped-per-agenda-item
 * @e2e openspec/specs/meeting-transcription/spec.md#transcription-completes-and-notifies
 * @e2e openspec/specs/meeting-transcription/spec.md#transcription-refused-without-consent
 * @e2e openspec/specs/meeting-transcription/spec.md#generate-a-draft-from-the-transcript
 * @e2e openspec/specs/meeting-transcription/spec.md#unverified-suggestion-flagged
 * @e2e openspec/specs/meeting-transcription/spec.md#no-ai-provider-hides-generation
 * @e2e openspec/specs/resolution-minutes/spec.md#editor-pre-filled-from-a-generated-draft
 * @e2e openspec/specs/resolution-minutes/spec.md#discard-a-generated-section
 * @e2e openspec/specs/resolution-minutes/spec.md#approval-workflow-unchanged-for-ai-initialized-minutes
 */
import type { Page } from '@playwright/test'
import type { SeedLedger } from './governance-fixture.ts'

import { expect, test } from '@playwright/test'
import {
	BASE,
	cleanupAll,
	createObject,
	newLedger,
	objId,
} from './governance-fixture.ts'

let ledger: SeedLedger

test.beforeAll(() => {
	ledger = newLedger()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

/** A transcript the panel treats as finished, used by the read-side mocks. */
const DONE_TRANSCRIPT = {
	id: 'e2e-transcript',
	status: 'done',
	segments: [
		{
			agendaItem: 'item-a',
			speakerLabel: 'Speaker 1',
			text: 'Bespreking agendapunt A.',
		},
		{ agendaItem: '', speakerLabel: 'Speaker 2', text: 'Losse opmerking.' },
	],
}

/**
 * Seed a real Meeting for the panel to mount on and return its UUID.
 *
 * `meetingType` / `meetingMode` / `lifecycle` are all closed enums in
 * decidesk_register.json and all three are in Meeting's `required` list, so any
 * value outside them is a hard 400 that would read as a broken transcription
 * flow rather than an incomplete fixture.
 */
async function seedMeeting(page: Page): Promise<string> {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `e2e-${ledger.runId}-transcription-meeting`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: '2026-09-01T10:00:00Z',
		lifecycle: 'closed',
	})
	return objId(meeting)
}

/**
 * Install the mocked-provider fixture: intercept the transcription action API
 * and the OR object reads the panel issues. `providerAvailable` / `aiAvailable`
 * toggle the graceful-degradation paths; `existingTranscript` decides whether
 * the panel finds a finished transcript already attached to the meeting.
 */
async function mockTranscriptionApi(
	page: Page,
	opts: {
		providerAvailable: boolean
		aiAvailable: boolean
		existingTranscript?: boolean
	},
): Promise<void> {
	// Source list + provider availability.
	await page.route(
		'**/apps/decidiq/api/meetings/*/transcription/sources',
		(route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					providerAvailable: opts.providerAvailable,
					aiAvailable: opts.aiAvailable,
					sources: [
						{
							type: 'uploaded-file',
							path: 'Decidesk/x/recording.mp3',
							name: 'recording.mp3',
						},
					],
				}),
			}),
	)

	// Attach (consent precondition lives server-side; here we echo a done transcript).
	await page.route(
		'**/apps/decidiq/api/meetings/*/transcription/attach',
		(route) =>
			route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify(DONE_TRANSCRIPT),
			}),
	)

	await page.route('**/apps/decidiq/api/transcripts/*/transcribe', (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ status: 'queued' }),
		}),
	)

	await page.route('**/apps/decidiq/api/transcripts/*/generate-draft', (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				provenance: {
					aiGenerated: true,
					providerId: 'mock-llm',
					generatedAt: '2026-06-15T00:00:00Z',
				},
				sections: [
					{
						agendaItem: 'item-a',
						title: 'Budget 2026',
						summary: 'De raad besprak het budget.',
						suggestions: [
							{
								title: 'Budget aangenomen',
								recordType: 'decision',
								linkedId: 'd1',
								unverified: false,
							},
							{
								title: 'Niet vastgelegd voorstel',
								recordType: 'decision',
								linkedId: '',
								unverified: true,
							},
						],
						provenance: {
							aiGenerated: true,
							providerId: 'mock-llm',
							generatedAt: '2026-06-15T00:00:00Z',
						},
					},
				],
			}),
		}),
	)

	// OR object reads the panel does (existing transcript + agenda titles).
	await page.route(
		'**/apps/openregister/api/objects/decidiq/transcript**',
		(route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					results:
						opts.existingTranscript === true ? [DONE_TRANSCRIPT] : [],
				}),
			}),
	)
	await page.route(
		'**/apps/openregister/api/objects/decidiq/agenda-item**',
		(route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					results: [{ id: 'item-a', title: 'Budget 2026' }],
				}),
			}),
	)
}

/**
 * Open the meeting detail page and wait for the transcription panel.
 *
 * The wait is deliberately NOT swallowed: a `.catch(() => {})` here is what
 * turned "the panel never mounted" into three unreadable timeouts instead of
 * one honest failure naming the missing panel.
 */
async function gotoTranscription(page: Page, meetingId: string): Promise<void> {
	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 10_000 })
	await page.waitForSelector('[data-testid="meeting-transcription-tab"]', {
		timeout: 10_000,
	})
}

/**
 * Pick the (single) mocked recording source in the NcSelect.
 *
 * The option is matched by ROLE and asserted on with `toContainText`, not by an
 * exact accessible name: NcSelect renders every option through
 * `NcEllipsisedOption`, which splits the label across two spans, and an
 * `{ exact: true }` name match against that split is what makes
 * `user-settings.spec.ts:171` hang on its own option lookup.
 */
async function selectRecordingSource(page: Page): Promise<void> {
	await page
		.getByTestId('transcription-source-select')
		.locator('input')
		.first()
		.click()
	const option = page.getByRole('option').first()
	await expect(option).toContainText('recording.mp3')
	await option.click()
	// The selection is observable, not assumed: Attach is bound to
	// `:disabled="!selectedSource"`, so it only enables once the pick committed.
	await expect(page.getByTestId('transcription-attach')).toBeEnabled()
}

test.describe('meeting transcription UI', () => {
	test('provider-unavailable messaging is shown instead of an error', async ({
		page,
	}) => {
		const meetingId = await seedMeeting(page)
		await mockTranscriptionApi(page, {
			providerAvailable: false,
			aiAvailable: false,
		})
		await gotoTranscription(page, meetingId)
		// Graceful degradation: attach still possible, transcribe shown unavailable.
		await expect(page.getByTestId('transcription-unavailable')).toBeVisible()
		await expect(page.getByTestId('transcription-attach')).toBeVisible()
	})

	test('attach with consent, transcript grouped by agenda item, generate draft + markers', async ({
		page,
	}) => {
		const meetingId = await seedMeeting(page)
		await mockTranscriptionApi(page, {
			providerAvailable: true,
			aiAvailable: true,
		})
		await gotoTranscription(page, meetingId)

		// Attach requires a chosen source AND the consent dialog (the consent
		// precondition surfaced in the UI). The Attach button stays disabled
		// until a source is picked, so the source selection is part of the flow.
		await expect(page.getByTestId('transcription-attach')).toBeDisabled()
		await selectRecordingSource(page)
		await page.getByTestId('transcription-attach').click()
		await expect(page.getByTestId('transcription-consent-modal')).toBeVisible()
		// Confirm button disabled until consent is checked.
		await expect(
			page.getByTestId('transcription-consent-confirm'),
		).toBeDisabled()
		// `NcCheckboxRadioSwitch` merges `$attrs` onto the <input> itself, so this
		// `data-testid` IS the input — and @nextcloud/vue 9.9.0 styles that input
		// `position: absolute; z-index: -1; opacity: 0 !important` with its own
		// `NcCheckboxContent` (`span.checkbox-radio-switch__content`) painted over
		// the box. So `locator.click()` can never land: Playwright reports the
		// content span intercepting pointer events and retries until the 20 s
		// per-test budget dies, which surfaces as a bare timeout rather than as
		// "this control is not clickable".
		//
		// Use the same actuation the six PASSING NcCheckboxRadioSwitch tests in
		// `spec-coverage/user-settings.spec.ts` use (`setSwitch()`): dispatch the
		// click at the input. A checkbox's activation behaviour runs for dispatched
		// clicks, so `checked` flips and `change` fires — the event nc-vue binds
		// `onToggle` to — and it still does nothing when the control is disabled.
		// Bracket it with state assertions so a click that actuates NOTHING fails
		// here by name instead of further down; that is what stops the dispatch
		// from being a way to skip the question.
		const consent = page.getByTestId('transcription-consent-checkbox')
		await expect(consent).not.toBeChecked()
		await consent.dispatchEvent('click')
		await expect(consent).toBeChecked()
		await expect(page.getByTestId('transcription-consent-confirm')).toBeEnabled()
		await page.getByTestId('transcription-consent-confirm').click()

		// Transcript view groups segments per agenda item + an unassigned group.
		await expect(page.getByTestId('transcript-view')).toBeVisible()
		await expect(page.getByTestId('transcript-group-unassigned')).toBeVisible()

		// Generate a draft: banner + per-section AI marker + unverified flag.
		await page.getByTestId('transcription-generate-draft').click()
		await expect(page.getByTestId('draft-provenance-banner')).toBeVisible()
		await expect(page.getByTestId('ai-section-marker').first()).toBeVisible()
		await expect(page.getByTestId('suggestion-unverified').first()).toBeVisible()
		await expect(page.getByTestId('suggestion-matched').first()).toBeVisible()

		// Discard a section removes its AI content + marker.
		await page.getByTestId('draft-section-discard').first().click()
		await expect(
			page.getByTestId('draft-section-discarded').first(),
		).toBeVisible()
	})

	test('AI generation hidden when no AI provider is available', async ({
		page,
	}) => {
		const meetingId = await seedMeeting(page)
		// A finished transcript IS attached — otherwise `toHaveCount(0)` below
		// would pass for the wrong reason (no transcript at all also hides the
		// generate action), asserting nothing about AI-provider gating.
		await mockTranscriptionApi(page, {
			providerAvailable: true,
			aiAvailable: false,
			existingTranscript: true,
		})
		await gotoTranscription(page, meetingId)
		// Done transcript renders, but the generate-draft action is absent.
		await expect(page.getByTestId('transcript-view')).toBeVisible()
		await expect(page.getByTestId('transcription-generate-draft')).toHaveCount(0)
	})
})
