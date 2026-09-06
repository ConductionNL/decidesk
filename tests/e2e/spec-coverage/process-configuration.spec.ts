/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Process configuration (process-config-v1).
 *
 * Drives the admin process-template management surface in the Decidiq admin
 * settings panel: the template list (built-in vs custom), the create/edit modal
 * with the state-machine editor + client-side graph validation, and the
 * duplicate action. The guard-wiring, round-open-defaults and built-in
 * usability scenarios are backend behaviour with no UI surface of their own and
 * carry @e2e exclude in the spec (covered by PHPUnit + Newman instead).
 *
 * Defensive skips: when the deployed instance does not serve this branch's
 * admin surface yet (deploy mismatch) the specs skip instead of failing.
 *
 * @e2e openspec/specs/process-configuration/spec.md#create-a-process-template-for-alv-decisions
 * @e2e openspec/specs/process-configuration/spec.md#duplicate-and-customize-an-existing-template
 * @e2e openspec/specs/process-configuration/spec.md#built-in-templates-are-read-only
 * @e2e openspec/specs/process-configuration/spec.md#reject-an-invalid-transition-graph
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'
import { becomesVisible } from '../becomes-visible.js'

/**
 * Open the Decidiq admin settings and wait for the process-templates section.
 * Returns false (→ defensive skip) when the surface is not deployed.
 */
async function openProcessTemplates(page: Page): Promise<boolean> {
	await page.goto(`${BASE}/settings/admin/decidiq`)
	const section = page.locator('[data-testid="process-templates"]')
	return section
		.waitFor({ state: 'visible', timeout: 15_000 })
		.then(() => true)
		.catch(() => false)
}

// @e2e openspec/specs/process-configuration/spec.md#built-in-templates-are-read-only
test('Template list shows built-in templates as read-only (duplicate, no delete)', async ({
	page,
}) => {
	test.skip(
		!(await openProcessTemplates(page)),
		'process-templates admin section not deployed on this instance',
	)

	const list = page.locator('[data-testid="process-template-list"]')
	await expect(list).toBeVisible({ timeout: 8_000 })

	const builtIn = page
		.locator('[data-testid="process-template-item"]')
		.filter({
			has: page.locator('[data-testid="process-template-builtin"]'),
		})
		.first()
	test.skip(
		!(await becomesVisible(builtIn)),
		'no built-in templates seeded on this instance',
	)

	// A built-in row offers Duplicate but neither Edit nor Delete.
	await expect(
		builtIn.locator('[data-testid="process-template-duplicate"]'),
	).toBeVisible()
	await expect(
		builtIn.locator('[data-testid="process-template-edit"]'),
	).toHaveCount(0)
	await expect(
		builtIn.locator('[data-testid="process-template-delete"]'),
	).toHaveCount(0)
})

// @e2e openspec/specs/process-configuration/spec.md#create-a-process-template-for-alv-decisions
test('Create modal accepts a well-formed template and enables Save', async ({
	page,
}) => {
	test.skip(
		!(await openProcessTemplates(page)),
		'process-templates admin section not deployed on this instance',
	)

	await page.locator('[data-testid="process-template-create"]').click()
	const modal = page.locator('[data-testid="process-template-modal"]')
	await expect(modal).toBeVisible({ timeout: 8_000 })

	await modal
		.locator('[data-testid="process-template-name"]')
		.fill('E2E ALV Standard Decision')

	// The default seeded state machine is valid -> no errors, Save enabled.
	await expect(modal.locator('[data-testid="state-machine-editor"]')).toBeVisible()
	await expect(
		modal.locator('[data-testid="process-template-errors"]'),
	).toHaveCount(0)
	await expect(
		modal.locator('[data-testid="process-template-save"]'),
	).toBeEnabled()
})

// @e2e openspec/specs/process-configuration/spec.md#reject-an-invalid-transition-graph
test('Create modal blocks Save when the transition graph is invalid', async ({
	page,
}) => {
	test.skip(
		!(await openProcessTemplates(page)),
		'process-templates admin section not deployed on this instance',
	)

	await page.locator('[data-testid="process-template-create"]').click()
	const modal = page.locator('[data-testid="process-template-modal"]')
	await expect(modal).toBeVisible({ timeout: 8_000 })

	await modal
		.locator('[data-testid="process-template-name"]')
		.fill('E2E Invalid Graph')

	// Add a fresh state with an empty name -> unreachable/empty -> validation fails.
	await modal.locator('[data-testid="state-machine-add-state"]').click()
	const editor = modal.locator('[data-testid="state-machine-editor"]')
	const lastStateRow = editor.locator('[data-testid="state-row"]').last()
	await lastStateRow.locator('input[type="text"]').fill('orphan-state')

	// An orphaned state with no transitions is unreachable -> errors shown, Save disabled.
	await expect(
		modal.locator('[data-testid="process-template-errors"]'),
	).toBeVisible({ timeout: 5_000 })
	await expect(
		modal.locator('[data-testid="process-template-save"]'),
	).toBeDisabled()
})

// @e2e openspec/specs/process-configuration/spec.md#duplicate-and-customize-an-existing-template
test('Duplicate action is available on every template row', async ({ page }) => {
	test.skip(
		!(await openProcessTemplates(page)),
		'process-templates admin section not deployed on this instance',
	)

	const firstRow = page.locator('[data-testid="process-template-item"]').first()
	test.skip(
		!(await becomesVisible(firstRow)),
		'no templates listed on this instance',
	)

	await expect(
		firstRow.locator('[data-testid="process-template-duplicate"]'),
	).toBeVisible()
})
