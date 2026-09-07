/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — admin-settings spec (governance body management)
 *
 * The governance-bodies list view + its "Add GovernanceBody" create dialog
 * are a real, working UI surface. These tests assert the live dialog fields
 * for the body-create and quorum-config scenarios. The remaining admin-settings
 * scenarios (role assignment, template assignment, org defaults, group/CSV
 * import) carry honest per-scenario @e2e exclude annotations in the spec — no
 * UI exists for them.
 *
 * @e2e openspec/specs/admin-settings/spec.md#create-a-governing-body-for-an-association-board
 * @e2e openspec/specs/admin-settings/spec.md#configure-quorum-rules-for-a-body
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

// @e2e openspec/specs/admin-settings/spec.md#create-a-governing-body-for-an-association-board
test('governance bodies list renders with Add GovernanceBody button', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/decidiq/governance-bodies`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// List loads with the "Showing N of N" indicator
	await expect(page.getByText('Showing')).toBeVisible()

	// The create CTA is present
	await expect(
		page.getByRole('button', { name: 'Add GovernanceBody' }),
	).toBeVisible()
})

// @e2e openspec/specs/admin-settings/spec.md#create-a-governing-body-for-an-association-board
test('Create GovernanceBody dialog opens with name, bodyType and domain required fields', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/decidiq/governance-bodies`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await page.getByRole('button', { name: 'Add GovernanceBody' }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(
		dialog.getByRole('heading', { name: 'Create GovernanceBody' }),
	).toBeVisible()

	// Required identity fields.
	//
	// Field labels come from the schema property `title`, not its key:
	// `fieldsFromSchema()` in @conduction/nextcloud-vue builds
	// `label: prop.title || key`, and CnFormDialog renders
	// `field.label + (field.required ? ' *' : '')`. GovernanceBody's
	// `name` / `domain` / `bodyType` carry the titles "Name" / "Domain" /
	// "Body type" in `lib/Settings/decidesk_register.json`.
	//
	// The two `getByRole` assertions below passed even while spelled with the
	// raw keys, because accessible-name matching is case-insensitive substring
	// matching ("name *" ⊂ "Name *"). The `bodyType *` assertion could not:
	// "bodytype *" is not a substring of "body type *". Spelling the real
	// labels removes that accident.
	await expect(dialog.getByRole('textbox', { name: 'Name *' })).toBeVisible()
	await expect(dialog.getByRole('textbox', { name: 'Domain *' })).toBeVisible()
	// bodyType is a required combobox (legislative / association /
	// corporate-board / operational / … per the register's enum). NcSelect
	// renders its `input-label` as a visible <label class="select__label">.
	await expect(dialog.getByText('Body type *', { exact: true })).toBeVisible()

	// Create is disabled until the required fields are filled
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeDisabled()

	// Cancel closes the dialog
	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/admin-settings/spec.md#configure-quorum-rules-for-a-body
test('Create GovernanceBody dialog exposes the quorumRule configuration field', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/decidiq/governance-bodies`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await page.getByRole('button', { name: 'Add GovernanceBody' }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })

	// quorumRule field — the quorum calculation method configured per body.
	// Rendered label = the schema property's `title` (see the sibling test
	// above): quorumRule → "Quorum rule", votingDefault → "Default voting
	// method", workflowTemplate → "Workflow template". None of the three is in
	// GovernanceBody's `required` array, so none carries the ' *' suffix.
	// Spelled as raw keys these could never match: accessible-name matching is
	// a case-insensitive SUBSTRING match, and "quorumrule" is not a substring
	// of "quorum rule" — the space is the difference.
	await expect(dialog.getByRole('textbox', { name: 'Quorum rule' })).toBeVisible()
	// votingDefault and workflowTemplate are also configurable on the body
	await expect(
		dialog.getByRole('textbox', { name: 'Default voting method' }),
	).toBeVisible()
	await expect(
		dialog.getByRole('textbox', { name: 'Workflow template' }),
	).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})
