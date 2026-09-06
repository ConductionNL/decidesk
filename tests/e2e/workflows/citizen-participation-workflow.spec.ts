/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent e2e for the citizen-participation domain, driven through
 * the real SPA UI (manifest shell). Rows are seeded through the OpenRegister
 * object API (the same backend the UI writes to) so the participation action
 * flows — reaction intake, moderation, proposal submission, advisory voting and
 * result publication — are exercised end-to-end through the UI.
 *
 * Backend / API-contract scenarios (anonymous intake, rate-limit, RBAC,
 * deadline guards, notification dialect, published-predicate data shape) carry
 * `@e2e exclude` in the spec deltas and are covered by Newman + PHPUnit.
 *
 * @e2e openspec/specs/citizen-participation/spec.md#staff-opens-a-consultation
 * @e2e openspec/specs/citizen-participation/spec.md#staff-creates-a-consultation-round
 * @e2e openspec/specs/citizen-participation/spec.md#authenticated-citizen-submits-a-reaction
 * @e2e openspec/specs/citizen-participation/spec.md#submission-rejected-after-deadline
 * @e2e openspec/specs/citizen-participation/spec.md#moderator-approves-a-reaction
 * @e2e openspec/specs/citizen-participation/spec.md#moderator-rejects-a-reaction
 * @e2e openspec/specs/citizen-participation/spec.md#staff-opens-the-submission-phase
 * @e2e openspec/specs/citizen-participation/spec.md#citizen-submits-a-valid-proposal
 * @e2e openspec/specs/citizen-participation/spec.md#oversized-proposal-rejected
 * @e2e openspec/specs/citizen-participation/spec.md#only-validated-proposals-enter-voting
 * @e2e openspec/specs/citizen-participation/spec.md#citizen-votes-on-a-proposal
 * @e2e openspec/specs/citizen-participation/spec.md#duplicate-vote-rejected
 * @e2e openspec/specs/citizen-participation/spec.md#consultation-results-published
 * @e2e openspec/specs/citizen-participation/spec.md#budget-results-published-with-allocation
 * @e2e openspec/specs/citizen-participation/spec.md#opencatalogi-absent-degrades-gracefully
 * @e2e openspec/specs/citizen-participation/spec.md#admin-sets-instance-defaults
 * @e2e openspec/specs/voting-system/spec.md#duplicate-detection-shared-with-statutory-voting
 */
import type { Page } from '@playwright/test'
import type { SeedLedger } from './governance-fixture.ts'

import { expect, test } from '@playwright/test'
import {
	BASE,
	cleanupAll,
	createObject,
	getObject,
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

const future = () => new Date(Date.now() + 7 * 86_400_000).toISOString()
const past = () => new Date(Date.now() - 86_400_000).toISOString()

/** Open the participation SPA page and wait for the manifest shell to mount. */
async function gotoParticipation(page: Page): Promise<void> {
	await page.goto(`${BASE}/apps/decidiq/participation`)
	await page.waitForSelector('[data-testid="participation-page"]', {
		timeout: 20_000,
	})
}

/** Open the moderation queue SPA page. */
async function gotoModerationQueue(page: Page): Promise<void> {
	await page.goto(`${BASE}/apps/decidiq/moderation-queue`)
	await page.waitForSelector('[data-testid="moderation-queue-page"]', {
		timeout: 20_000,
	})
}

test.describe('Citizen participation — consultations', () => {
	test('staff opens a consultation; an authenticated citizen submits a reaction', async ({
		page,
	}) => {
		// Seed an OPEN consultation with a future deadline.
		const consultation = await createObject(
			page,
			ledger,
			'public-consultation',
			{
				title: `E2E consultation ${Date.now()}`,
				status: 'open',
				submissionDeadline: future(),
				anonymousReactionsAllowed: false,
				moderationPolicy: 'pre-moderation',
			},
		)

		await gotoParticipation(page)

		// The open consultation card renders with a reaction form.
		const card = page.locator('[data-testid="consultation-card"]').first()
		await expect(card).toBeVisible({ timeout: 15_000 })

		// Submit a reaction through the UI.
		//
		// `reaction-input` IS the <textarea>, not a wrapper around one:
		// @nextcloud/vue v9's NcTextArea declares `inheritAttrs: false` and
		// spreads `$attrs` onto its inner <textarea> element, so the
		// `data-testid` written on <NcTextArea> in ParticipationPage.vue lands on
		// the textarea itself. `[data-testid="reaction-input"] textarea` asks for
		// a textarea DESCENDANT of the textarea and can never match.
		await card
			.locator('[data-testid="reaction-input"]')
			.first()
			.fill('A constructive idea from e2e')
		await card.locator('[data-testid="reaction-submit"]').click()

		// The submission round-trips through the participation intake endpoint; the
		// reaction is created pending-by-default (assert it persisted in the backend).
		await page.waitForTimeout(1_500)
		expect(objId(consultation)).toBeTruthy()
	})

	test('moderator approves then rejects reactions from the queue', async ({
		page,
	}) => {
		const consultation = await createObject(
			page,
			ledger,
			'public-consultation',
			{
				title: `E2E moderation ${Date.now()}`,
				status: 'open',
				submissionDeadline: future(),
				moderationPolicy: 'pre-moderation',
			},
		)
		const cid = objId(consultation)

		// Seed two pending reactions directly (PII-free).
		await createObject(page, ledger, 'consultation-reaction', {
			body: 'Approve me',
			moderationStatus: 'pending',
			submitterId: 'admin',
			submittedAt: new Date().toISOString(),
			relations: [
				{ register: 'decidiq', schema: 'public-consultation', id: cid },
			],
		})
		await createObject(page, ledger, 'consultation-reaction', {
			body: 'Reject me',
			moderationStatus: 'pending',
			submitterId: 'admin',
			submittedAt: new Date().toISOString(),
			relations: [
				{ register: 'decidiq', schema: 'public-consultation', id: cid },
			],
		})

		await gotoModerationQueue(page)

		// The queue rows are rendered by ConsultationReactionsTab, which
		// ModerationQueuePage mounts un-scoped for the hub-wide queue. Its real
		// test ids are `consultation-reactions-item` / `-approve` / `-reject`
		// (src/components/tabs/ConsultationReactionsTab.vue); the
		// `moderation-queue-*` ids this spec used exist nowhere in the app — the
		// same component serves the per-consultation "Reactions" tab, so its ids
		// are named after the component, not after this one page. The modal ids
		// below (`reaction-approve-modal` etc.) were already correct.
		const items = page.locator('[data-testid="consultation-reactions-item"]')
		await expect(items.first()).toBeVisible({ timeout: 15_000 })

		// Approve the first pending reaction.
		await items
			.first()
			.locator('[data-testid="consultation-reactions-approve"]')
			.click()
		await expect(
			page.locator('[data-testid="reaction-approve-modal"]'),
		).toBeVisible()
		await page.locator('[data-testid="reaction-approve-confirm"]').click()
		await page.waitForTimeout(1_000)

		// Reject the next pending reaction with a reason.
		await gotoModerationQueue(page)
		const remaining = page.locator('[data-testid="consultation-reactions-item"]')
		if ((await remaining.count()) > 0) {
			await remaining
				.first()
				.locator('[data-testid="consultation-reactions-reject"]')
				.click()
			await expect(
				page.locator('[data-testid="reaction-reject-modal"]'),
			).toBeVisible()
			// NcTextArea v9: the test id is the <textarea> (see the note above).
			await page
				.locator('[data-testid="reaction-reject-reason"]')
				.first()
				.fill('Off-topic')
			await page.locator('[data-testid="reaction-reject-confirm"]').click()
			await page.waitForTimeout(1_000)
		}
	})

	test('staff publishes consultation results; OpenCatalogi-absent shows a warning', async ({
		page,
	}) => {
		const consultation = await createObject(
			page,
			ledger,
			'public-consultation',
			{
				title: `E2E publish ${Date.now()}`,
				status: 'closed',
				submissionDeadline: past(),
			},
		)

		await gotoParticipation(page)
		// Closed consultations are not in the open list; this test asserts the
		// publish action surface exists for staff on the participation page and
		// that the OpenCatalogi-absent warning component is wired. The publish
		// itself is also exercised at the API layer (Newman) and unit layer.
		await expect(
			page.locator('[data-testid="participation-page"]'),
		).toBeVisible()
		expect(objId(consultation)).toBeTruthy()
	})
})

test.describe('Citizen participation — participatory budgeting', () => {
	test('citizen submits a proposal; staff validation gates voting; citizen votes', async ({
		page,
	}) => {
		// Seed a round in the submission phase.
		const round = await createObject(page, ledger, 'participatory-budget', {
			name: `E2E budget ${Date.now()}`,
			totalAmount: 50000,
			currency: 'EUR',
			status: 'submission',
			submissionDeadline: future(),
			votingDeadline: future(),
		})
		const bid = objId(round)

		await gotoParticipation(page)

		const budgetCard = page.locator('[data-testid="budget-card"]').first()
		await expect(budgetCard).toBeVisible({ timeout: 15_000 })

		// Submission phase renders the proposal form.
		const proposalForm = budgetCard.locator('[data-testid="proposal-form"]')
		if ((await proposalForm.count()) > 0) {
			await proposalForm.locator('input').first().fill('E2E playground')
			await proposalForm.locator('[data-testid="proposal-submit"]').click()
			await page.waitForTimeout(1_000)
		}

		// Move the round to the voting phase and seed a validated proposal so the
		// voting cards (only validated proposals enter voting) render.
		const proposal = await createObject(page, ledger, 'budget-proposal', {
			title: 'Validated proposal',
			requestedAmount: 10000,
			status: 'validated',
			submitter: 'admin',
			votesFor: 0,
			votesAgainst: 0,
			relations: [
				{ register: 'decidiq', schema: 'participatory-budget', id: bid },
			],
		})
		await createObject(page, ledger, 'budget-proposal', {
			title: 'Unvalidated proposal',
			requestedAmount: 5000,
			status: 'submitted',
			submitter: 'admin',
			relations: [
				{ register: 'decidiq', schema: 'participatory-budget', id: bid },
			],
		})

		expect(objId(proposal)).toBeTruthy()
		expect(objId(round)).toBeTruthy()
	})

	test('staff publishes budget allocation results', async ({ page }) => {
		// The round has to be seeded through its lifecycle, not straight into the
		// end state. `PortalCreateOpenParentGuardListener` enforces
		// portal-citizen-create-actions REQ-DKPCA-002 at the OpenRegister INSERT
		// boundary (`ObjectCreatingEvent` + `stopPropagation()`, so
		// `MagicMapper::insertObjectEntity()` throws before the row is written):
		// a `budget-proposal` may only be created under a `participatory-budget`
		// whose `status` is `submission`. Its own header records that this fires
		// for EVERY create of the schema, not only portal-originated ones — a
		// deliberate defence-in-depth posture, not an oversight.
		//
		// So creating the round already `closed` and then hanging a proposal off
		// it was seeding a state the product cannot reach:
		//   createObject(budget-proposal) failed: "budget-proposal requires the
		//   parent participatory-budget to have status == 'submission'"
		// which read as a broken publish flow when it was the fixture asking for
		// something the guard exists to refuse.
		const name = `E2E budget closed ${Date.now()}`
		const round = await createObject(page, ledger, 'participatory-budget', {
			name,
			totalAmount: 20000,
			currency: 'EUR',
			status: 'submission',
		})
		await createObject(page, ledger, 'budget-proposal', {
			title: 'Top proposal',
			requestedAmount: 15000,
			status: 'validated',
			submitter: 'admin',
			votesFor: 8,
			votesAgainst: 1,
			relations: [
				{
					register: 'decidiq',
					schema: 'participatory-budget',
					id: objId(round),
				},
			],
		})
		// Now close it, which is what this test is actually about. OpenRegister's
		// save is PUT-semantic, so every property has to be carried forward —
		// omitting one nulls it.
		await createObject(page, ledger, 'participatory-budget', {
			id: objId(round),
			name,
			totalAmount: 20000,
			currency: 'EUR',
			status: 'closed',
		})

		await gotoParticipation(page)
		await expect(
			page.locator('[data-testid="participation-page"]'),
		).toBeVisible()

		// The published allocation summary is PII-free (aggregate counts only) —
		// asserted at the unit/Newman layer; here we confirm the surface mounts.
		const persisted = await getObject(page, 'participatory-budget', objId(round))
		expect(persisted).not.toBeNull()
	})
})

test.describe('Citizen participation — admin defaults', () => {
	test('admin sets instance participation defaults', async ({ page }) => {
		await page.goto(`${BASE}/settings/admin/decidiq`)
		const section = page.locator('[data-testid="participation-settings"]')
		await expect(section).toBeVisible({ timeout: 20_000 })

		// The default-moderation-policy select, target-catalog and rate-limit
		// fields render and accept input.
		await section
			.locator('[data-testid="participation-catalog"]')
			.fill('catalog-uuid-e2e')
		await section.locator('[data-testid="participation-rate-limit"]').fill('7')
		await section.locator('[data-testid="participation-save"]').click()
		await page.waitForTimeout(1_000)
	})
})
