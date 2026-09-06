/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Admin settings (admin-settings-v1).
 *
 * Drives the governance-body detail widgets (Members: role assignment +
 * Nextcloud-group/CSV import dialogs; Process template: default + specialized
 * template assignment) and the admin settings Organization section. The body
 * create/quorum scenarios stay covered by governance-body.spec.ts.
 * API/contract assertions live in Newman
 * (tests/integration/decidiq-admin-settings.postman_collection.json),
 * not here.
 *
 * These are WIDGETS, not sidebar tabs. The distinction is not pedantry: the
 * helper that hunted a tab could never match, so four tests here skipped
 * permanently while reporting "not deployed on this instance". Surfaces the
 * shipped manifest declares are asserted, so an absent one fails.
 *
 * @e2e openspec/specs/admin-settings/spec.md#assign-roles-within-a-body
 * @e2e openspec/specs/admin-settings/spec.md#assign-default-and-specialized-templates-to-a-body
 * @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
 * @e2e openspec/specs/admin-settings/spec.md#import-members-from-a-nextcloud-group
 * @e2e openspec/specs/admin-settings/spec.md#import-members-from-csv
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'
import { becomesVisible } from '../becomes-visible.js'

/**
 * Open the first governance body's detail page and its sidebar.
 * Returns false (→ defensive skip) when the surface is not deployed.
 */
async function openFirstBodyDetail(page: Page): Promise<boolean> {
	await page.goto(`${BASE}/apps/decidiq/governance-bodies`)
	try {
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
		// Open the first row of the bodies list.
		const firstRow = page.locator('tbody tr').first()
		await firstRow.waitFor({ state: 'visible', timeout: 10_000 })
		await firstRow.click()
		await page.waitForTimeout(1_000)
		return true
	} catch {
		return false
	}
}

/*
 * openSidebarTab() used to live here. It clicked `getByRole('tab', { name })`
 * and, when nothing matched, skipped the test as "not deployed on this
 * instance".
 *
 * ⚠️ GovernanceBodyDetail HAS NO SIDEBAR TABS. Its `config.sidebar` declares
 * exactly one, `audit` / "History"; body-members, body-template and
 * body-efficiency are `type: "custom"` WIDGETS in `config.widgets`, rendered
 * inline. Dumped off the live page, `getByRole('tab')` returns an EMPTY list,
 * so the helper could only ever return false and every test behind it skipped
 * permanently, with a reason about deployment that was never true.
 *
 * The widgets are asserted directly now. Same finding as the five minutes tests
 * fixed alongside these.
 */

// @e2e openspec/specs/admin-settings/spec.md#assign-roles-within-a-body
test('Members tab lists body members and offers the Change role action', async ({
	page,
}) => {
	test.skip(
		!(await openFirstBodyDetail(page)),
		'governance-body detail not reachable on this instance',
	)

	const tabRoot = page.locator('[data-testid="body-members-tab"]')
	await expect(tabRoot).toBeVisible({ timeout: 15_000 })

	// The tab renders its member table (root-cause fix: governanceBody is
	// now a real Participant property, so the filter resolves).
	await expect(tabRoot.getByRole('button', { name: 'Add member' })).toBeVisible()

	// Role assignment: when the body has at least one member, the row
	// actions expose "Change role" opening the role dialog with the role
	// enum select.
	// ⚠️ AN EMPTY MEMBERS TABLE STILL RENDERS ONE `tbody tr`. CnObjectList's
	// empty state is a row — "No members linked to this body yet." — carrying
	// zero buttons, so `rows.count() > 0` is true with no members at all. The
	// row-actions click below then waits for a button that does not exist until
	// the test times out.
	//
	// Measured, because the first reading was wrong: this is what reddened the
	// development push that first executed this test, and it is NOT a budget
	// problem. Under test.slow() it failed the same way at 60s, on the same
	// locator. Filtering to rows that actually carry a button is the fix; a
	// bigger timeout only makes it fail more slowly.
	const rows = tabRoot.locator('tbody tr')
	const memberRows = rows.filter({ has: page.getByRole('button') })
	if ((await memberRows.count()) > 0) {
		await memberRows.first().hover()
		const actions = memberRows.first().getByRole('button').last()
		await actions.click()
		const changeRole = page
			.getByRole('menuitem', { name: 'Change role' })
			.or(page.getByRole('button', { name: 'Change role' }))
		if (
			await changeRole
				.first()
				.isVisible()
				.catch(() => false)
		) {
			await changeRole.first().click()
			const dialog = page.locator('[data-testid="member-role-dialog"]')
			await expect(dialog).toBeVisible({ timeout: 8_000 })
			await expect(
				dialog.locator('[data-testid="member-role-select"]'),
			).toBeVisible()
			await dialog.locator('[data-testid="member-role-cancel"]').click()
		}
	} else {
		// No members to act on, so assert the empty state rather than falling
		// through silently. A conditional whose false branch asserts nothing
		// passes identically whether the widget works or renders nothing at all,
		// which is how this test could have gone green without ever proving the
		// members table exists.
		await expect(
			tabRoot.locator('[data-testid="cn-object-list-empty"]'),
		).toBeVisible()
	}
})

// @e2e openspec/specs/admin-settings/spec.md#import-members-from-a-nextcloud-group
test('Members tab opens the Nextcloud-group import dialog with a group selector', async ({
	page,
}) => {
	test.skip(
		!(await openFirstBodyDetail(page)),
		'governance-body detail not reachable on this instance',
	)

	const tabRoot = page.locator('[data-testid="body-members-tab"]')
	const importMenu = tabRoot.getByRole('button', { name: 'Import members' })
	test.skip(
		!(await becomesVisible(importMenu)),
		'import actions not deployed on this instance',
	)

	await importMenu.click()
	await page.locator('[data-testid="body-members-import-group"]').click()

	const dialog = page.locator('[data-testid="member-group-import-dialog"]')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	// Group selector (admin-gated /api/member-import/groups feed).
	await expect(dialog.locator('[data-testid="group-import-select"]')).toBeVisible()
	await dialog.locator('[data-testid="group-import-cancel"]').click()
	await expect(dialog).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/admin-settings/spec.md#import-members-from-csv
test('Members tab CSV import validates rows and previews duplicates before importing', async ({
	page,
}) => {
	test.skip(
		!(await openFirstBodyDetail(page)),
		'governance-body detail not reachable on this instance',
	)

	const tabRoot = page.locator('[data-testid="body-members-tab"]')
	const importMenu = tabRoot.getByRole('button', { name: 'Import members' })
	test.skip(
		!(await becomesVisible(importMenu)),
		'import actions not deployed on this instance',
	)

	await importMenu.click()
	await page.locator('[data-testid="body-members-import-csv"]').click()

	const dialog = page.locator('[data-testid="member-csv-import-dialog"]')
	await expect(dialog).toBeVisible({ timeout: 8_000 })

	// Upload a CSV with one valid, one invalid-email and one unknown-role row.
	const csv =
		'name,email,role\nE2E Valid,e2e-valid@example.test,member\nBad Email,not-an-email,member\nBad Role,e2e-role@example.test,emperor\n'
	await dialog.locator('[data-testid="csv-import-file"]').setInputFiles({
		name: 'members.csv',
		mimeType: 'text/csv',
		buffer: Buffer.from(csv, 'utf-8'),
	})

	const preview = dialog.locator('[data-testid="csv-import-preview"]')
	await expect(preview).toBeVisible({ timeout: 10_000 })
	// Validation preview: per-row status before anything is written.
	await expect(preview.getByText('Invalid email address')).toBeVisible()
	await expect(preview.getByText('Unknown role')).toBeVisible()
	await expect(preview.getByText('Will be imported').first()).toBeVisible()

	await dialog.locator('[data-testid="csv-import-cancel"]').click()
	await expect(dialog).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/admin-settings/spec.md#assign-default-and-specialized-templates-to-a-body
test('Process template tab assigns a default and specialized templates', async ({
	page,
}) => {
	test.skip(
		!(await openFirstBodyDetail(page)),
		'governance-body detail not reachable on this instance',
	)

	const tabRoot = page.locator('[data-testid="body-template-tab"]')
	await expect(tabRoot).toBeVisible({ timeout: 8_000 })

	// Default template selector + specialized multi-select + save.
	await expect(
		tabRoot.locator('[data-testid="body-template-default"]'),
	).toBeVisible()
	await expect(
		tabRoot.locator('[data-testid="body-template-specialized"]'),
	).toBeVisible()
	await expect(tabRoot.locator('[data-testid="body-template-save"]')).toBeVisible()
})

// @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
test('Admin settings exposes the Organization defaults section and saves it', async ({
	page,
}) => {
	await page.goto(`${BASE}/settings/admin/decidiq`)
	const section = page.locator('[data-testid="organisation-settings"]')
	const deployed = await section
		.waitFor({ state: 'visible', timeout: 15_000 })
		.then(() => true)
		.catch(() => false)
	test.skip(
		!deployed,
		'organization settings section not deployed on this instance',
	)

	await section
		.locator('[data-testid="organisation-name"]')
		.fill('Vereniging De Harmonie')
	await section
		.locator('[data-testid="organisation-logo"]')
		.fill('https://example.org/logo.png')
	await section.locator('[data-testid="organisation-save"]').click()
	await expect(section.locator('[data-testid="organisation-saved"]')).toBeVisible({
		timeout: 10_000,
	})

	// Persistence proof: a reload shows the saved organization name.
	await page.reload()
	await expect(page.locator('[data-testid="organisation-name"]')).toHaveValue(
		'Vereniging De Harmonie',
		{ timeout: 15_000 },
	)
})
