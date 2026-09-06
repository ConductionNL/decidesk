/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Voting rules (voting-rules-v1).
 *
 * Drives the configurable voting rules through the real UI: the
 * "Voting round" sidebar tab on the motion detail page (MotionVotingRoundTab
 * hosting VotingRoundPanel), the open-round dialog's threshold / abstention /
 * tie-break selectors with their documented defaults, and the active-rules +
 * computed-base summary shown with the live tally and the closed-round
 * result. API/contract assertions (rule validation, tally math, casting-vote
 * and revote guards, the proxy cap) live in Newman
 * (tests/integration/decidiq-voting-rules.postman_collection.json), not here.
 *
 * Defensive skips: when the deployed instance does not serve this branch yet
 * (no "Voting round" tab on the motion detail page — deploy mismatch) or the
 * fixture seeding API is unavailable, the specs skip instead of failing —
 * same convention as the other spec-coverage suites.
 *
 * @e2e openspec/specs/voting-system/spec.md#configure-voting-rules-when-opening-a-round
 * @e2e openspec/specs/voting-system/spec.md#display-active-rules-and-computed-base
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'
import { becomesVisible } from '../becomes-visible.js'
import { MOTION_SCHEMA } from '../workflows/governance-fixture.ts'
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

/**
 * Cookie-less basic-auth API context for fixture seeding/teardown via the
 * OpenRegister object API (same auth model the Newman collections use).
 */
async function newApiContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		extraHTTPHeaders: {
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
		},
	})
}

/**
 * Create a decidiq object via the OR object API.
 *
 * ⚠️ This used to `return null` on any non-2xx, and every caller then did
 * `test.skip(!id, 'OpenRegister seeding API unavailable on this instance')`. That
 * turned a MALFORMED REQUEST into a skip that blamed the instance: the motion
 * seeds below addressed schema `motion`, which ADR-005 deleted, so the POST
 * answered 404 "Schema not found: 'motion'" on every run and three tests
 * reported SKIPPED — never once telling anyone the request was wrong.
 *
 * A seed that cannot succeed must fail loudly, with the status and the body, the
 * way tests/e2e/workflows/governance-fixture.ts already does.
 */
async function createObject(
	api: APIRequestContext,
	schema: string,
	body: object,
): Promise<string | null> {
	const resp = await api.post(
		`/index.php/apps/openregister/api/objects/decidiq/${schema}`,
		{ data: body },
	)
	if (!resp.ok()) {
		throw new Error(
			`createObject(${schema}) failed ${resp.status()}: ${await resp.text()}`,
		)
	}
	const json = await resp.json()
	return json?.['@self']?.id ?? json?.id ?? null
}

/** Best-effort fixture teardown. */
async function deleteObject(
	api: APIRequestContext,
	schema: string,
	id: string | null,
): Promise<void> {
	if (!id) return
	try {
		await api.delete(
			`/index.php/apps/openregister/api/objects/decidiq/${schema}/${id}`,
		)
	} catch {
		// Teardown is best-effort; leftover fixtures are namespaced ("E2E VR …").
	}
}

/**
 * Open the motion detail page and activate the "Voting round" sidebar tab.
 * Returns false when the tab is not served (deploy mismatch) so callers skip.
 */
async function openVotingRoundTab(page: Page, motionId: string): Promise<boolean> {
	await page.goto(`${BASE}/apps/decidiq/motions/${motionId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// The sidebar tab is rendered from the manifest's MotionDetail sidebarTabs.
	const tab = page
		.getByRole('tab', { name: 'Voting round' })
		.or(page.getByText('Voting round', { exact: true }))
	try {
		await tab.first().waitFor({ state: 'visible', timeout: 10_000 })
	} catch {
		return false
	}
	await tab.first().click()
	try {
		await page
			.locator('[data-testid="motion-voting-round-tab"]')
			.waitFor({ state: 'visible', timeout: 10_000 })
		return true
	} catch {
		return false
	}
}

// @e2e openspec/specs/voting-system/spec.md#configure-voting-rules-when-opening-a-round
test('open-round dialog offers threshold, abstention and tie-break selectors with the documented defaults', async ({
	page,
}) => {
	// Headroom: seeding (2 API creates) + navigation + the tab-detection waits
	// can exceed the default 30s under shared-instance load, which would hard-fail
	// the test before its defensive test.skip (deploy-mismatch) path completes.
	test.setTimeout(90_000)
	const api = await newApiContext()
	let meetingId: string | null = null
	let motionId: string | null = null
	try {
		meetingId = await createObject(api, 'meeting', {
			title: 'E2E VR Meeting',
			meetingType: 'regular',
			scheduledDate: '2026-06-20T10:00:00+00:00',
			meetingMode: 'hybrid',
			lifecycle: 'opened',
			quorumRequired: 1,
		})
		expect(meetingId, 'the seeded meeting must carry an id').toBeTruthy()

		// ADR-005: a motion IS a Decision (decisionType='motion'). The `motion`
		// schema no longer exists. `decisionDate`, `outcome` and `decisionType` are
		// all in Decision.required[]; `lifecycle` is
		// [draft, proposed, deliberating, voting, decided, enacted, archived,
		// withdrawn] — there is no 'debating'.
		//
		// `meeting` IS a Decision property. The note that used to stand here said
		// the opposite ("Decision has no `meeting` property … passing one invents
		// a field"), and that was true of the base register only:
		// register.d/67-model-debt-cleanup.json adds an optional
		// `meeting` $ref to Decision precisely so a decision taken in a meeting
		// can say so. Leaving it off is what made this test SKIP rather than run
		// — the panel's "Open voting round" button is disabled without a linked
		// meeting, so the round this test exists to configure could never be
		// opened, and the skip reason ("round already open or panel not
		// deployed") named neither cause. It is set here so the fixture builds
		// the motion the surrounding comment always claimed it did: in
		// deliberation, with a meeting.
		motionId = await createObject(api, MOTION_SCHEMA, {
			decisionType: 'motion',
			title: 'E2E VR Rules Motion',
			text: 'That voting rules be configurable.',
			decisionDate: '2026-06-20T10:00:00+00:00',
			outcome: 'adopted',
			motionType: 'motion',
			proposer: 'E2E VR',
			lifecycle: 'deliberating',
			submittedAt: '2026-06-19T09:00:00+00:00',
			meeting: meetingId,
		})
		expect(motionId, 'the seeded motion Decision must carry an id').toBeTruthy()

		test.skip(
			!(await openVotingRoundTab(page, motionId as string)),
			'Voting round tab not deployed on this instance (deploy mismatch)',
		)

		// The motion is in debating with a linked meeting → the chair can open a
		// round. Match both locales ('Stemronde openen' is the i18n key; the
		// English catalogue renders it as 'Open voting round').
		const openButton = page.getByRole('button', {
			name: /Stemronde openen|Open voting round/,
		})
		test.skip(
			!(await becomesVisible(openButton)),
			'Open-round button not available (round already open or panel not deployed)',
		)
		await openButton.click()

		const dialog = page.getByRole('dialog', {
			name: /Stemronde openen|Open voting round/,
		})
		await expect(dialog).toBeVisible({ timeout: 8_000 })

		// The three rule selectors render with the documented defaults:
		// simple majority, abstentions excluded, and motion fails on a tie.
		const threshold = page.locator('[data-testid="vote-threshold-select"]')
		const abstention = page.locator('[data-testid="abstention-handling-select"]')
		const tieBreak = page.locator('[data-testid="tie-break-rule-select"]')
		await expect(threshold).toBeVisible()
		await expect(abstention).toBeVisible()
		await expect(tieBreak).toBeVisible()
		await expect(threshold).toHaveValue('simple-majority')
		await expect(abstention).toHaveValue('exclude')
		await expect(tieBreak).toHaveValue('rejected')

		// All qualified-majority thresholds are selectable (two-thirds,
		// three-quarters, unanimous), all abstention modes, all tie-break rules.
		await expect(threshold.locator('option')).toHaveCount(4)
		await threshold.selectOption('qualified-majority-two-thirds')
		await expect(threshold).toHaveValue('qualified-majority-two-thirds')
		await expect(abstention.locator('option')).toHaveCount(2)
		await abstention.selectOption('count')
		await expect(abstention).toHaveValue('count')
		await expect(tieBreak.locator('option')).toHaveCount(3)
		await tieBreak.selectOption('chair-decides')
		await expect(tieBreak).toHaveValue('chair-decides')
	} finally {
		await deleteObject(api, MOTION_SCHEMA, motionId)
		await deleteObject(api, 'meeting', meetingId)
		await api.dispose()
	}
})

// @e2e openspec/specs/voting-system/spec.md#display-active-rules-and-computed-base
test('closed-round result shows the active rules and the computed base', async ({
	page,
}) => {
	test.setTimeout(90_000)
	const api = await newApiContext()
	let motionId: string | null = null
	let roundId: string | null = null
	try {
		// 'adopted' is an `outcome`, not a `lifecycle`; the decided state is 'decided'.
		motionId = await createObject(api, MOTION_SCHEMA, {
			decisionType: 'motion',
			title: 'E2E VR Result Motion',
			text: 'Closed-round rules display host motion.',
			decisionDate: '2026-06-20T10:00:00+00:00',
			outcome: 'adopted',
			motionType: 'motion',
			proposer: 'E2E VR',
			lifecycle: 'decided',
			submittedAt: '2026-06-19T09:00:00+00:00',
		})
		expect(motionId, 'the seeded motion Decision must carry an id').toBeTruthy()

		// Closed two-thirds round with the spec's worked example (14/5/1 → base 19).
		roundId = await createObject(api, 'voting-round', {
			votingMethod: 'for-against-abstain',
			isSecret: false,
			openedAt: '2026-06-20T10:05:00+00:00',
			closedAt: '2026-06-20T11:00:00+00:00',
			result: 'adopted',
			votesFor: 14,
			votesAgainst: 5,
			votesAbstain: 1,
			voteThreshold: 'qualified-majority-two-thirds',
			abstentionHandling: 'exclude',
			tieBreakRule: 'rejected',
			voteBase: 19,
			relations: [{ register: 'decidiq', schema: 'motion', id: motionId }],
		})
		test.skip(!roundId, 'OpenRegister seeding API unavailable on this instance')

		test.skip(
			!(await openVotingRoundTab(page, motionId as string)),
			'Voting round tab not deployed on this instance (deploy mismatch)',
		)

		// The closed-round result block shows the applied rules + computed base.
		const rules = page.locator('[data-testid="result-voting-rules"]')
		await expect(rules).toBeVisible({ timeout: 10_000 })
		await expect(rules).toContainText('Qualified majority (2/3)')
		await expect(rules).toContainText('Abstentions excluded from base')
		await expect(rules).toContainText('base: 19')
	} finally {
		await deleteObject(api, 'voting-round', roundId)
		await deleteObject(api, MOTION_SCHEMA, motionId)
		await api.dispose()
	}
})

// @e2e openspec/specs/voting-system/spec.md#display-active-rules-and-computed-base
test('live tally shows the active rules and the computed base while the round is open', async ({
	page,
}) => {
	test.setTimeout(90_000)
	const api = await newApiContext()
	let motionId: string | null = null
	let roundId: string | null = null
	try {
		motionId = await createObject(api, MOTION_SCHEMA, {
			decisionType: 'motion',
			title: 'E2E VR Live Motion',
			text: 'Live-tally rules display host motion.',
			decisionDate: '2026-06-20T10:00:00+00:00',
			outcome: 'adopted',
			motionType: 'motion',
			proposer: 'E2E VR',
			lifecycle: 'voting',
			submittedAt: '2026-06-19T09:00:00+00:00',
		})
		expect(motionId, 'the seeded motion Decision must carry an id').toBeTruthy()

		// OPEN round in count mode: base = for + against + abstain = 10.
		roundId = await createObject(api, 'voting-round', {
			votingMethod: 'for-against-abstain',
			isSecret: false,
			openedAt: '2026-06-20T10:05:00+00:00',
			votesFor: 4,
			votesAgainst: 3,
			votesAbstain: 3,
			voteThreshold: 'qualified-majority-three-quarters',
			abstentionHandling: 'count',
			tieBreakRule: 'revote',
			relations: [{ register: 'decidiq', schema: 'motion', id: motionId }],
		})
		test.skip(!roundId, 'OpenRegister seeding API unavailable on this instance')

		test.skip(
			!(await openVotingRoundTab(page, motionId as string)),
			'Voting round tab not deployed on this instance (deploy mismatch)',
		)

		const rules = page.locator('[data-testid="active-voting-rules"]')
		await expect(rules).toBeVisible({ timeout: 10_000 })
		await expect(rules).toContainText('Qualified majority (3/4)')
		await expect(rules).toContainText('Abstentions count toward base')
		await expect(rules).toContainText('Tie: revote (once)')
		await expect(rules).toContainText('base: 10')
	} finally {
		await deleteObject(api, 'voting-round', roundId)
		await deleteObject(api, MOTION_SCHEMA, motionId)
		await api.dispose()
	}
})
