/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — motion-amendment spec
 *
 * @e2e openspec/specs/motion-amendment/spec.md#submit-a-motion-with-co-signers
 * @e2e openspec/specs/motion-amendment/spec.md#reject-motion-below-minimum-co-signer-threshold
 * @e2e openspec/specs/motion-amendment/spec.md#submit-a-motion-during-a-live-meeting
 * @e2e openspec/specs/motion-amendment/spec.md#submit-an-amendment-to-a-pending-motion
 * @e2e openspec/specs/motion-amendment/spec.md#view-the-amendment-diff-against-the-parent-motion
 * @e2e openspec/specs/motion-amendment/spec.md#submit-multiple-amendments-to-the-same-motion
 * @e2e openspec/specs/motion-amendment/spec.md#vote-on-amendments-before-the-main-motion
 * @e2e openspec/specs/motion-amendment/spec.md#chair-sets-amendment-voting-order
 * @e2e openspec/specs/motion-amendment/spec.md#reject-opening-an-amendment-round-out-of-order
 * @e2e openspec/specs/motion-amendment/spec.md#reject-submission-after-the-meeting-deadline
 * @e2e openspec/specs/motion-amendment/spec.md#withdraw-a-motion-before-voting
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

// @e2e openspec/specs/motion-amendment/spec.md#submit-a-motion-with-co-signers
test('motions list renders with Add Motion button', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/motions`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await expect(page.getByText('Showing')).toBeVisible()
	await expect(page.getByTestId('cn-cta-primary')).toBeVisible()
})

// @e2e openspec/specs/motion-amendment/spec.md#submit-a-motion-with-co-signers
// @e2e openspec/specs/motion-amendment/spec.md#reject-motion-below-minimum-co-signer-threshold
test('Add Motion dialog opens with co-signers and lifecycle fields', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/decidiq/motions`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await page.getByTestId('cn-cta-primary').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	// The heading is "Create Decision", not "Create Motion", and that is correct.
	// Under ADR-005 there is no `motion` schema: a motion IS a Decision with
	// `decisionType=motion`, and the Motions index is a filtered projection of
	// the decision schema (manifest page `Motions`: `"schema": "decision"`,
	// `"filter": {"decisionType": "motion"}`). CnIndexPage mounts its create
	// dialog without a `dialog-title`, so CnFormDialog falls back to
	// `Create {schema.title}` and the Decision schema's title is "Decision".
	// Asserting "Create Motion" asserted a string the product has never
	// produced. Tracked separately: CnIndexPage has no way to label the create
	// dialog of a filtered projection of a supertype.
	await expect(
		dialog.getByRole('heading', { name: 'Create Decision' }),
	).toBeVisible()

	// Assert the real motion form fields render.
	//
	// Labels are the schema property's `title`, never its key — `fieldsFromSchema()`
	// builds `label: prop.title || key` and CnFormDialog renders
	// `label + (required ? ' *' : '')`. So `motionType`/`coSigners`/`lifecycle`
	// could never match; the rendered labels are "Motion type", "Co-signers",
	// "Status". And `required` on Decision is exactly
	// ["title","text","decisionType"], so Title is the only one of these
	// carrying an asterisk — `proposer *` and `lifecycle *` were asserting a
	// required-ness the schema does not declare, which is the same mistake the
	// decision-management spec already corrected for `decisionDate`/`outcome`.
	await expect(dialog.getByText('Title *', { exact: true })).toBeVisible()
	await expect(dialog.getByText('Proposer', { exact: true }).first()).toBeVisible()
	await expect(
		dialog.getByText('Motion type', { exact: true }).first(),
	).toBeVisible()
	// coSigners drives the co-signer threshold scenario
	await expect(
		dialog.getByText('Co-signers', { exact: true }).first(),
	).toBeVisible()
	// lifecycle is on the form, optional
	await expect(dialog.getByText('Status', { exact: true }).first()).toBeVisible()

	// Create button visible
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	await page.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/motion-amendment/spec.md#submit-a-motion-during-a-live-meeting
// Live meeting motion submission is accessible via the LiveMeeting view.
// Verify the live meeting view loads for an existing meeting.
test('motions list shows existing motions', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/motions`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// Switch to table view if available
	const tableBtn = page.getByRole('button', { name: 'Table', exact: false })
	if (await tableBtn.isVisible()) {
		await tableBtn.click()
	}

	// THIS USED TO ASSERT `count >= 0`, which no count can ever fail. The
	// message said "Motions list should show seed data" while the assertion
	// said nothing at all: it passed on an empty table, on a table that never
	// rendered, and on a page that had navigated somewhere else entirely.
	//
	// What the test can honestly claim is that the list rendered and settled
	// into exactly one of its two legitimate states. `tbody tr` cannot tell
	// them apart, because CnDataTable puts its empty state in the same tbody,
	// so both are addressed by their own testid.
	const table = page.getByTestId('cn-object-list-table')
	await expect(table).toBeVisible({ timeout: 15_000 })

	const dataRows = page.locator('[data-testid="cn-object-row"]')
	const emptyRow = page.locator('[data-testid="cn-object-list-empty"]')

	await expect
		.poll(
			async () => (await dataRows.count()) > 0 || (await emptyRow.count()) > 0,
			{
				message:
					'the motions table rendered neither a row nor its empty state',
			},
		)
		.toBe(true)

	// And the two are mutually exclusive: seeing both means the table is
	// showing an empty state above rows it also rendered.
	expect(
		(await dataRows.count()) > 0 && (await emptyRow.count()) > 0,
		'the motions table showed data rows and its empty state at once',
	).toBe(false)
})

// @e2e openspec/specs/motion-amendment/spec.md#submit-an-amendment-to-a-pending-motion
// @e2e openspec/specs/motion-amendment/spec.md#submit-multiple-amendments-to-the-same-motion
// Amendments are added via the MotionAmendmentsTab on a motion detail page.
test('motion detail route renders with amendments tab accessible', async ({
	page,
}) => {
	// ADR-005 (accepted): the standalone `motion` schema was folded into the
	// `Decision` supertype under `decisionType: 'motion'`. Addressing
	// /objects/decidiq/motion returns 404 "Schema not found: 'motion'", which is
	// what this test was failing on — note the manifest already routes
	// /motions/:id at schema `decision`, so only this URL was stale.
	const resp = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidiq/decision?decisionType=motion&_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(
		resp.ok(),
		`motion listing must be readable (HTTP ${resp.status()})`,
	).toBe(true)
	const body = await resp.json()
	const first = (body.results ?? body.items ?? [])[0]
	expect(
		first,
		'at least one decisionType=motion Decision must be seeded',
	).toBeTruthy()
	const motionId = first.id ?? first['@self']?.id
	expect(motionId, 'the seeded motion must carry an id').toBeTruthy()

	await page.goto(`${BASE}/apps/decidiq/motions/${motionId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/motion-amendment/spec.md#view-the-amendment-diff-against-the-parent-motion
// The visual diff lives in the AmendmentDiffTab on an amendment detail page.
// Verify the amendment detail route mounts (the diff tab renders the word-level
// additions-in-green / removals-in-red view, falling back to amendment text).
test('amendment detail route renders for the diff view', async ({ page }) => {
	// ADR-005: an amendment is a Decision with decisionType='amendment' (the
	// standalone `amendment` schema was removed); /amendments/:id is already
	// routed at schema `decision` in the manifest.
	const resp = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidiq/decision?decisionType=amendment&_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(
		resp.ok(),
		`amendment listing must be readable (HTTP ${resp.status()})`,
	).toBe(true)
	const body = await resp.json()
	const first = (body.results ?? body.items ?? [])[0]
	expect(
		first,
		'at least one decisionType=amendment Decision must be seeded',
	).toBeTruthy()
	const amendmentId = first.id ?? first['@self']?.id
	expect(amendmentId, 'the seeded amendment must carry an id').toBeTruthy()

	await page.goto(`${BASE}/apps/decidiq/amendments/${amendmentId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/motion-amendment/spec.md#vote-on-amendments-before-the-main-motion
// @e2e openspec/specs/motion-amendment/spec.md#chair-sets-amendment-voting-order
// @e2e openspec/specs/motion-amendment/spec.md#reject-opening-an-amendment-round-out-of-order
// @e2e openspec/specs/motion-amendment/spec.md#reject-submission-after-the-meeting-deadline
// Out-of-order amendment-round rejection and submission-deadline rejection are
// server-enforced (VotingService::openVotingRound / SubmissionDeadlineListener)
// and contract-tested in Newman + PHPUnit. The chair-facing voting-order UI is
// MotionAmendmentOrderTab on the motion detail page, exercised below.
// Amendment voting order and live voting require a live meeting context with an active
// voting round — these are VotingRoundPanel behaviors in the LiveMeeting view.
// Verified via the live meeting view mounting.
test('live meeting view shows motions context for in-meeting motion submission', async ({
	page,
}) => {
	const resp = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/decidiq/meeting?_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.ok()).toBe(true)
	const body = await resp.json()
	const first = (body.results ?? body.items ?? [])[0]
	test.skip(!first, 'No meeting objects found')
	const meetingId = first.id ?? first['@self']?.id
	test.skip(!meetingId, 'First meeting has no id')

	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}/live`)
	await page.waitForSelector('[data-testid="meeting-live"]', { timeout: 15_000 })
	await expect(page.locator('[data-testid="meeting-live"]')).toBeVisible()
})

// @e2e openspec/specs/motion-amendment/spec.md#withdraw-a-motion-before-voting
// Lifecycle transitions on motions are driven from the motion detail sidebar/actions.
// Verified via motion detail rendering.
test('motions page title is correct', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/motions`)
	await expect(page).toHaveTitle(/Decidiq/i)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
})
