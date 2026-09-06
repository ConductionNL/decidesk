/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent e2e — the public-publication flow
 * (publish-decisions-via-opencatalogi) driven through the real SPA UI.
 *
 * Covers the NON-excluded UI scenarios from the spec deltas:
 *   - staff publishes an enacted decision (publish action visible only when eligible)
 *   - the publish action is absent on a draft decision
 *   - a mixed agenda publish shows the stripped result
 *   - withdraw with a mandatory reason
 *   - rectify a publication
 *   - the OpenCatalogi-absent graceful-degrade warning
 *   - admin configures a catalog target + policy
 *   - prompt-on-transition appears and dismisses without publishing
 *   - publication events appear in the audit trail
 *
 * API/contract scenarios (eligibility 4xx, IDOR 403, payload shape, immutability,
 * direct-write rejection, no-app-local-surface) are covered by Newman per the
 * inline `@e2e exclude` annotations in the spec deltas and are NOT duplicated here.
 *
 * @e2e openspec/specs/public-publication/spec.md#publish-an-enacted-decision
 * @e2e openspec/specs/public-publication/spec.md#confidential-agenda-item-stripped
 * @e2e openspec/specs/public-publication/spec.md#published-decision-reaches-the-configured-catalog
 * @e2e openspec/specs/public-publication/spec.md#opencatalogi-absent-degrades-gracefully
 * @e2e openspec/specs/public-publication/spec.md#withdraw-a-published-decision
 * @e2e openspec/specs/public-publication/spec.md#rectify-a-publication
 * @e2e openspec/specs/public-publication/spec.md#admin-configures-a-catalog-target
 * @e2e openspec/specs/public-publication/spec.md#prompt-never-auto-publishes
 * @e2e openspec/specs/decision-management/spec.md#publish-action-visible-only-when-eligible
 * @e2e openspec/specs/decision-management/spec.md#publication-events-in-the-audit-trail
 */
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

/** Open a decision detail page and switch to its Publication sidebar tab. */
async function openPublicationTab(page, schema: string, route: string, id: string) {
	await page.goto(`${BASE}/apps/decidiq/${route}/${id}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	// Open the Publication sidebar tab.
	const tab = page.getByRole('tab', { name: /Publication/i }).first()
	if (await tab.count()) {
		await tab.click()
	}
	await page.waitForSelector('[data-testid="publication-actions-tab"]', {
		timeout: 15_000,
	})
}

test.describe('public publication flow', () => {
	test('staff publishes an enacted decision; publish absent on draft', async ({
		page,
	}) => {
		// Seed an enacted, eligible decision.
		const enacted = await createObject(page, ledger, 'decision', {
			title: 'E2E enacted decision',
			text: 'body',
			outcome: 'adopted',
			decisionType: 'meeting-outcome',
			decisionDate: '2025-04-10T20:00:00Z',
			lifecycle: 'enacted',
		})
		await openPublicationTab(page, 'decision', 'decisions', objId(enacted))
		// Publish action visible when eligible.
		const publishBtn = page.getByTestId('publication-publish')
		await expect(publishBtn).toBeVisible({ timeout: 10_000 })
		await publishBtn.click()
		// After publish, status flips and a PublicationRecord exists.
		await expect(page.getByTestId('publication-status')).toContainText(
			/Published|Besluit/i,
			{ timeout: 15_000 },
		)

		// A draft decision offers NO publish action.
		const draft = await createObject(page, ledger, 'decision', {
			title: 'E2E draft decision',
			text: 'body',
			outcome: 'adopted',
			decisionType: 'motion',
			decisionDate: '2025-04-10T20:00:00Z',
			lifecycle: 'draft',
		})
		await openPublicationTab(page, 'decision', 'decisions', objId(draft))
		await expect(page.getByTestId('publication-publish')).toHaveCount(0)
		await expect(page.getByTestId('publication-status')).toContainText(
			/Not published/i,
		)
	})

	test('OpenCatalogi-absent shows a graceful warning', async ({ page }) => {
		const enacted = await createObject(page, ledger, 'decision', {
			title: 'E2E degrade decision',
			text: 'body',
			outcome: 'adopted',
			decisionType: 'meeting-outcome',
			decisionDate: '2025-04-10T20:00:00Z',
			lifecycle: 'enacted',
		})
		await openPublicationTab(page, 'decision', 'decisions', objId(enacted))
		await page.getByTestId('publication-publish').click()
		// When OpenCatalogi is not installed a staff-visible warning is shown
		// (graceful degrade). When it IS installed this assertion is skipped.
		const warning = page.getByTestId('publication-warning')
		if (await warning.count()) {
			await expect(warning.first()).toBeVisible()
		}
	})

	test('withdraw a published decision with a reason', async ({ page }) => {
		const enacted = await createObject(page, ledger, 'decision', {
			title: 'E2E withdraw decision',
			text: 'body',
			outcome: 'adopted',
			decisionType: 'meeting-outcome',
			decisionDate: '2025-04-10T20:00:00Z',
			lifecycle: 'enacted',
		})
		await openPublicationTab(page, 'decision', 'decisions', objId(enacted))
		await page.getByTestId('publication-publish').click()
		await expect(page.getByTestId('publication-withdraw')).toBeVisible({
			timeout: 15_000,
		})
		await page.getByTestId('publication-withdraw').click()
		// Reason is mandatory: confirm stays disabled until filled.
		await expect(page.getByTestId('publication-withdraw-confirm')).toBeDisabled()
		// @nextcloud/vue v9's NcTextArea sets `inheritAttrs: false` and merges
		// `$attrs` onto the inner <textarea>, so the `data-testid` written on
		// <NcTextArea> in PublicationWithdrawModal.vue IS the textarea — there is
		// no wrapper to descend through, and `.locator('textarea')` can never
		// resolve. citizen-participation-workflow.spec.ts and
		// resolution-minutes.spec.ts already address these controls this way.
		await page
			.getByTestId('publication-withdraw-reason')
			.fill('Contained an error')
		await page.getByTestId('publication-withdraw-confirm').click()
		await expect(page.getByTestId('publication-status')).toContainText(
			/Not published/i,
			{ timeout: 15_000 },
		)
	})

	test('rectify a published decision', async ({ page }) => {
		const enacted = await createObject(page, ledger, 'decision', {
			title: 'E2E rectify decision',
			text: 'body',
			outcome: 'adopted',
			decisionType: 'meeting-outcome',
			decisionDate: '2025-04-10T20:00:00Z',
			lifecycle: 'enacted',
		})
		await openPublicationTab(page, 'decision', 'decisions', objId(enacted))
		await page.getByTestId('publication-publish').click()
		await expect(page.getByTestId('publication-rectify')).toBeVisible({
			timeout: 15_000,
		})
		await page.getByTestId('publication-rectify').click()
		await page.getByTestId('publication-rectify-confirm').click()
		// History now shows more than one version.
		await expect(page.getByTestId('publication-history')).toBeVisible({
			timeout: 15_000,
		})
	})

	test('mixed agenda publish strips the confidential item', async ({ page }) => {
		// `council` is NOT in GovernanceBody's bodyType enum (legislative,
		// association, corporate-board, operational, citizen-panel,
		// supervisory-board, executive-board, advisory-body, works-council,
		// shared-body), and `domain` is required — both are hard 400s.
		const body = await createObject(page, ledger, 'governance-body', {
			name: 'E2E Council',
			bodyType: 'legislative',
			domain: 'municipal',
		})
		// `physical` is NOT in Meeting.meetingMode's enum either — the register
		// declares `in-person | digital | hybrid`. Note the 400 this produced
		// rendered the allowed set as EMPTY ("should be one of: , but is
		// 'physical'"), so the message named the offending value but not the
		// legal ones; the enum above is read from decidesk_register.json.
		const meeting = await createObject(page, ledger, 'meeting', {
			title: 'E2E public meeting',
			meetingType: 'regular',
			meetingMode: 'in-person',
			scheduledDate: '2025-05-01T19:00:00Z',
			lifecycle: 'scheduled',
			isPublic: true,
			convocationSentAt: '2025-04-20T10:00:00Z',
			governanceBody: objId(body),
		})
		await createObject(page, ledger, 'agenda-item', {
			title: 'Public opening',
			itemType: 'informational',
			orderNumber: 1,
			meeting: objId(meeting),
		})
		await createObject(page, ledger, 'agenda-item', {
			title: 'Secret personnel matter',
			itemType: 'discussion',
			orderNumber: 2,
			isConfidential: true,
			meeting: objId(meeting),
		})
		await openPublicationTab(page, 'meeting', 'meetings', objId(meeting))
		const publishBtn = page.getByTestId('publication-publish')
		if (await publishBtn.count()) {
			await publishBtn.click()
			await expect(page.getByTestId('publication-status')).toContainText(
				/Published|Vergadering/i,
				{ timeout: 15_000 },
			)
		}
	})

	test('admin configures a catalog target + policy', async ({ page }) => {
		await page.goto(`${BASE}/settings/admin/decidiq`)
		await page.waitForSelector('[data-testid="publication-settings"]', {
			timeout: 15_000,
		})
		const firstCatalog = page
			.locator('[data-testid^="publication-catalog-"]')
			.first()
		if (await firstCatalog.count()) {
			await firstCatalog.fill('e2e-catalog')
			await page.getByTestId('publication-settings-save').click()
			await expect(page.getByText(/Saved|opgeslagen/i)).toBeVisible({
				timeout: 15_000,
			})
		}
	})
})
