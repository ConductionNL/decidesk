/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent e2e — the board self-evaluation flow
 * (board-self-evaluation) driven through the real SPA UI: a body opens an
 * evaluation cycle, invited members submit anonymous responses, the chair
 * closes the cycle (scoring runs, small-body breakdown suppression applies),
 * the results tab renders the score, publishing exposes only the aggregate,
 * and a non-chair/secretary is denied the lifecycle write by OpenRegister
 * RBAC (no app-local authorization service).
 *
 * Covers the NON-excluded UI scenarios from the spec delta:
 *   - a body opens an evaluation cycle
 *   - a response cannot be traced to its author
 *   - completion is tracked without de-anonymising
 *   - scores are computed on close
 *   - small-body breakdown is suppressed
 *   - results render via the Analytics leaf (the results tab)
 *   - publishing exposes only the aggregate
 *   - lifecycle gating is OR RBAC, not app-local
 *
 * Structural / seed-presence / data-model-only scenarios are excluded inline
 * in the spec delta (`@e2e exclude ...`) per gate-19 and are NOT duplicated
 * here: past-cycle coexistence, template question shape, default-template
 * seed presence, and the one-mode-adaptive-entity claim.
 *
 * @e2e openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#a-body-opens-an-evaluation-cycle
 * @e2e openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#a-response-cannot-be-traced-to-its-author
 * @e2e openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#completion-is-tracked-without-de-anonymising
 * @e2e openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#scores-are-computed-on-close
 * @e2e openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#small-body-breakdown-is-suppressed
 * @e2e openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#results-render-via-the-analytics-leaf
 * @e2e openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#publishing-exposes-only-the-aggregate
 * @e2e openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#lifecycle-gating-is-or-rbac-not-app-local
 */
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

/** Seed a default EvaluationTemplate with two dimensions (one likert question each). */
async function seedTemplate(page) {
	return createObject(page, ledger, 'evaluation-template', {
		title: 'E2E effectiveness template',
		dimensions: ['strategy-and-oversight', 'chair-effectiveness'],
		questions: [
			{
				id: 'q-strategy-likert',
				dimension: 'strategy-and-oversight',
				prompt: 'Strategy question',
				type: 'likert',
				scaleMin: 1,
				scaleMax: 5,
			},
			{
				id: 'q-chair-likert',
				dimension: 'chair-effectiveness',
				prompt: 'Chair question',
				type: 'likert',
				scaleMin: 1,
				scaleMax: 5,
			},
		],
	})
}

/** Open the "Self-evaluation" sidebar tab on a GovernanceBody detail page. */
async function openEvaluationsTab(page, bodyId: string) {
	await page.goto(`${BASE}/apps/decidiq/governance-bodies/${bodyId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	const tab = page.getByRole('tab', { name: /Self-evaluation/i }).first()
	if (await tab.count()) {
		await tab.click()
	}
	await page.waitForSelector('[data-testid="body-evaluations-tab"]', {
		timeout: 15_000,
	})
}

test.describe('board self-evaluation flow', () => {
	test('a body opens an evaluation cycle and members submit anonymously; completion tracks without de-anonymising', async ({
		page,
	}) => {
		// `domain` is in GovernanceBody's `required` list — omitting it is a hard
		// 400 from OpenRegister, which reads as a broken evaluation flow rather
		// than an incomplete fixture.
		const body = await createObject(page, ledger, 'governance-body', {
			name: 'E2E Evaluation Board',
			bodyType: 'supervisory-board',
			domain: 'corporate',
		})
		const chair = await createObject(page, ledger, 'participant', {
			displayName: 'E2E Chair',
			role: 'chair',
			nextcloudUserId: 'admin',
			governanceBody: objId(body),
		})
		const memberTwo = await createObject(page, ledger, 'participant', {
			displayName: 'E2E Member Two',
			role: 'member',
			governanceBody: objId(body),
		})
		const memberThree = await createObject(page, ledger, 'participant', {
			displayName: 'E2E Member Three',
			role: 'member',
			governanceBody: objId(body),
		})
		const template = await seedTemplate(page)

		// Chair creates + opens a cycle (draft -> open). invitedParticipantIds
		// carries the completion roster; chairUserId drives the lifecycle RBAC.
		const evaluation = await createObject(page, ledger, 'board-evaluation', {
			cycleLabel: 'E2E-2026',
			lifecycle: 'open',
			openedAt: new Date().toISOString(),
			invitedMemberCount: 3,
			respondedCount: 0,
			invitedParticipantIds: [
				objId(chair),
				objId(memberTwo),
				objId(memberThree),
			],
			respondedParticipantIds: [],
			chairUserId: 'admin',
			governanceBody: objId(body),
			template: objId(template),
		})

		await openEvaluationsTab(page, objId(body))
		await expect(
			page.getByTestId(`evaluation-card-${objId(evaluation)}`),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByTestId(`evaluation-card-${objId(evaluation)}`),
		).toContainText('E2E-2026')

		// Respond anonymously as the logged-in admin (mapped to the chair participant).
		await page.getByTestId('evaluation-respond').click()
		await page.waitForSelector('[data-testid="evaluation-respond-modal"]', {
			timeout: 10_000,
		})
		await page
			.getByTestId('evaluation-respond-likert-q-strategy-likert-4')
			.click()
		await page.getByTestId('evaluation-respond-likert-q-chair-likert-3').click()
		await page.getByTestId('evaluation-respond-submit').click()

		// Completion advances (respondedCount) WITHOUT the response content ever
		// carrying the responding member's identity — verified server-side.
		await expect(
			page.getByTestId(`evaluation-card-${objId(evaluation)}`),
		).toContainText('1 of 3 responded', { timeout: 15_000 })

		// A response cannot be traced to its author: the completion roster
		// (respondedParticipantIds) proves the chair responded, while the
		// response content itself (asserted server-side by
		// BoardEvaluationScoreServiceTest::testAnonymityNoMemberIdentityRecoverable)
		// never carries that identity.
		const updatedEvaluation = await getObject(
			page,
			'board-evaluation',
			objId(evaluation),
		)
		expect(updatedEvaluation?.respondedParticipantIds).toContain(objId(chair))
	})

	test('closing a cycle computes scores; small-body breakdown is suppressed below threshold', async ({
		page,
	}) => {
		const body = await createObject(page, ledger, 'governance-body', {
			name: 'E2E Suppression Board',
			bodyType: 'supervisory-board',
			domain: 'corporate',
		})
		const chair = await createObject(page, ledger, 'participant', {
			displayName: 'E2E Chair',
			role: 'chair',
			nextcloudUserId: 'admin',
			governanceBody: objId(body),
		})
		const template = await seedTemplate(page)

		// Only 1 respondent, threshold default 3: closing must suppress breakdowns.
		const evaluation = await createObject(page, ledger, 'board-evaluation', {
			cycleLabel: 'E2E-Suppressed',
			lifecycle: 'open',
			openedAt: new Date().toISOString(),
			invitedMemberCount: 3,
			respondedCount: 0,
			minRespondentThreshold: 3,
			invitedParticipantIds: [objId(chair)],
			respondedParticipantIds: [],
			chairUserId: 'admin',
			governanceBody: objId(body),
			template: objId(template),
		})

		await openEvaluationsTab(page, objId(body))
		await page.getByTestId('evaluation-respond').click()
		await page.waitForSelector('[data-testid="evaluation-respond-modal"]', {
			timeout: 10_000,
		})
		await page
			.getByTestId('evaluation-respond-likert-q-strategy-likert-4')
			.click()
		await page.getByTestId('evaluation-respond-likert-q-chair-likert-2').click()
		await page.getByTestId('evaluation-respond-submit').click()
		await expect(
			page.getByTestId(`evaluation-card-${objId(evaluation)}`),
		).toContainText('1 of 3 responded', { timeout: 15_000 })

		// Chair closes the cycle -> scoring runs (Scores are computed on close).
		await page.getByTestId('evaluation-close').click()
		await expect(
			page.getByTestId(`evaluation-results-${objId(evaluation)}`),
		).toBeVisible({ timeout: 15_000 })
		// Small-body breakdown is suppressed: only the aggregate note shows.
		await expect(page.getByTestId('evaluation-suppressed-note')).toBeVisible()
	})

	test('results render on the GovernanceBody results tab; publishing exposes only the aggregate', async ({
		page,
	}) => {
		const body = await createObject(page, ledger, 'governance-body', {
			name: 'E2E Publish Board',
			bodyType: 'supervisory-board',
			domain: 'corporate',
		})
		const chair = await createObject(page, ledger, 'participant', {
			displayName: 'E2E Chair',
			role: 'chair',
			nextcloudUserId: 'admin',
			governanceBody: objId(body),
		})
		const memberTwo = await createObject(page, ledger, 'participant', {
			displayName: 'E2E Member Two',
			role: 'member',
			governanceBody: objId(body),
		})
		const memberThree = await createObject(page, ledger, 'participant', {
			displayName: 'E2E Member Three',
			role: 'member',
			governanceBody: objId(body),
		})
		const template = await seedTemplate(page)

		// Pre-closed with a materialised (above-threshold) scoreSummary so the
		// results tab has something to render without a 3-user live respond loop.
		const evaluation = await createObject(page, ledger, 'board-evaluation', {
			// NOT "E2E-Published": the card renders `cycleLabel` and `lifecycle` in one
			// text run, so a label containing "Published" satisfies the
			// `toContainText(/published/i)` assertion below WHATEVER the lifecycle is.
			// That is exactly what happened — the assertion passed on a run where the
			// stored object was still `lifecycle: "closed"` and had never been
			// published at all, and the test only failed four lines later. A fixture
			// whose own name satisfies the assertion under test is not a fixture.
			cycleLabel: 'E2E-Aggregate',
			lifecycle: 'closed',
			openedAt: new Date(Date.now() - 86400000).toISOString(),
			closedAt: new Date().toISOString(),
			invitedMemberCount: 3,
			respondedCount: 3,
			minRespondentThreshold: 3,
			invitedParticipantIds: [
				objId(chair),
				objId(memberTwo),
				objId(memberThree),
			],
			respondedParticipantIds: [
				objId(chair),
				objId(memberTwo),
				objId(memberThree),
			],
			chairUserId: 'admin',
			scoreSummary: JSON.stringify({
				overallScore: 4.0,
				respondentCount: 3,
				invitedMemberCount: 3,
				minRespondentThreshold: 3,
				thresholdMet: true,
				suppressed: false,
				dimensionScores: {
					'strategy-and-oversight': 4.0,
					'chair-effectiveness': 4.0,
				},
				themes: {},
				computedAt: new Date().toISOString(),
			}),
			governanceBody: objId(body),
			template: objId(template),
		})

		await openEvaluationsTab(page, objId(body))
		// Results render via the Analytics leaf (this app's established
		// client-side CSS-bar rendering convention for leaf-attributed tabs,
		// mirroring GovernanceBodyEfficiencyTab).
		await expect(
			page.getByTestId(`evaluation-results-${objId(evaluation)}`),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByTestId(`evaluation-results-${objId(evaluation)}`),
		).toContainText('4')

		// Publish: only the aggregate summary enters the public window.
		await page.getByTestId('evaluation-publish').click()
		await expect(
			page.getByTestId(`evaluation-card-${objId(evaluation)}`),
		).toContainText(/published/i, { timeout: 15_000 })

		const published = await getObject(
			page,
			'board-evaluation',
			objId(evaluation),
		)
		expect(published?.publicationDate).toBeTruthy()
		// No raw EvaluationResponse ever appears on the published object.
		expect(JSON.stringify(published)).not.toContain('freeText')
	})

	test('lifecycle gating is OpenRegister RBAC, not app-local: a non-chair/secretary is denied', async ({
		page,
	}) => {
		const body = await createObject(page, ledger, 'governance-body', {
			name: 'E2E RBAC Board',
			bodyType: 'supervisory-board',
			domain: 'corporate',
		})
		const template = await seedTemplate(page)

		// chairUserId deliberately set to someone other than the logged-in admin
		// session, so opening the cycle through the normal object-save path is
		// denied by OpenRegister's property-RBAC rule on `lifecycle` — no
		// app-local authorization service is consulted.
		const evaluation = await createObject(page, ledger, 'board-evaluation', {
			cycleLabel: 'E2E-RBAC',
			lifecycle: 'draft',
			invitedMemberCount: 0,
			respondedCount: 0,
			invitedParticipantIds: [],
			respondedParticipantIds: [],
			chairUserId: 'someone-else',
			secretaryUserId: 'someone-else-too',
			governanceBody: objId(body),
			template: objId(template),
		})

		await openEvaluationsTab(page, objId(body))
		await page.getByTestId('evaluation-open').click()
		// The write is denied server-side; the cycle stays in `draft`.
		await expect(
			page.getByTestId(`evaluation-card-${objId(evaluation)}`),
		).toContainText('draft', { timeout: 15_000 })
	})
})
