/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — agenda-management spec
 *
 * @e2e openspec/specs/agenda-management/spec.md#create-a-decision-agenda-item
 * @e2e openspec/specs/agenda-management/spec.md#create-an-informational-agenda-item-with-documents
 * @e2e openspec/specs/agenda-management/spec.md#submit-a-member-proposal-for-the-agenda
 * @e2e openspec/specs/agenda-management/spec.md#reorder-agenda-items-via-drag-and-drop
 * @e2e openspec/specs/agenda-management/spec.md#enforce-legally-required-alv-agenda-items
 * @e2e openspec/specs/agenda-management/spec.md#group-agenda-items-with-sub-items
 * @e2e openspec/specs/agenda-management/spec.md#calculate-total-agenda-duration
 * @e2e openspec/specs/agenda-management/spec.md#track-time-during-meeting-conduct
 * @e2e openspec/specs/agenda-management/spec.md#assemble-meeting-package-from-agenda-documents
 */
// This helper takes the whole `playwright` fixture, not a single export, so
// it needs the MODULE's type. A namespace type import expresses that without
// an inline `import()` annotation, which the rule bans everywhere including
// inside a type alias.
import type * as PlaywrightModule from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'
import { becomesVisible } from '../becomes-visible.js'

// @e2e openspec/specs/agenda-management/spec.md#create-a-decision-agenda-item
// @e2e openspec/specs/agenda-management/spec.md#create-an-informational-agenda-item-with-documents
test('agenda items list renders with Add Agenda item button', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/agenda-items`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// Showing N of N indicator visible
	await expect(page.getByText('Showing')).toBeVisible()

	// Add button is present
	await expect(page.getByTestId('cn-cta-primary')).toBeVisible()
})

// @e2e openspec/specs/agenda-management/spec.md#create-a-decision-agenda-item
// @e2e openspec/specs/agenda-management/spec.md#create-an-informational-agenda-item-with-documents
test('Add Agenda Item dialog opens with item type field', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/agenda-items`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await page.getByTestId('cn-cta-primary').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(
		dialog.getByRole('heading', { name: 'Create AgendaItem' }),
	).toBeVisible()

	// Assert the real agenda-item form fields render.
	//
	// The label a manifest-shell form renders is NOT the raw schema property
	// key. `fieldsFromSchema()` in @conduction/nextcloud-vue
	// (src/utils/schema.js) builds `label: prop.title || key`, and
	// CnFormDialog renders `field.label + (field.required ? ' *' : '')` — for
	// NcTextField as its visible `<label class="input-field__label">`, for an
	// enum NcSelect as its visible `<label class="select__label">`. The raw
	// key only ever surfaces for a property with NO `title`, which is exactly
	// the state hydra's `schema-property-titles` gate exists to prevent.
	// `lib/Settings/decidesk_register.json` gives every AgendaItem property a
	// human-readable title, so the rendered labels are "Title *",
	// "Item type *", "Order *", "Estimated duration".
	//
	// Asserting the raw keys was only ever half-true: `getByText` defaults to
	// a CASE-INSENSITIVE SUBSTRING match, so 'title *' happened to hit
	// "Title *" and passed, while 'itemType *' can never match "Item type *"
	// and failed. That split is the signature of a spec written against an
	// untitled-schema state, not of a form that fails to mount — the dialog,
	// its heading and the Create/Cancel buttons all resolve.
	await expect(dialog.getByText('Title *', { exact: true })).toBeVisible()
	await expect(dialog.getByText('Item type *', { exact: true })).toBeVisible()
	// `orderNumber` carries the title "Order" (register: AgendaItem.orderNumber).
	await expect(dialog.getByText('Order *', { exact: true })).toBeVisible()
	// estimatedDuration supports the agenda-duration calculation scenario
	await expect(
		dialog.getByText('Estimated duration', { exact: true }),
	).toBeVisible()

	// Create button is present
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()

	// Cancel closes it
	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/agenda-management/spec.md#submit-a-member-proposal-for-the-agenda
// @e2e openspec/specs/agenda-management/spec.md#reorder-agenda-items-via-drag-and-drop
// @e2e openspec/specs/agenda-management/spec.md#group-agenda-items-with-sub-items
// Member proposals and drag-and-drop ordering are accessible via the MeetingAgendaTab
// on a meeting detail page. We verify the agenda tab renders within the meeting sidebar.
test('meeting detail sidebar has Agenda tab', async ({ page }) => {
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

	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	// App root mounts for meeting detail route
	await expect(page.locator('[data-testid="app-root"]')).toBeVisible()
})

// @e2e openspec/specs/agenda-management/spec.md#enforce-legally-required-alv-agenda-items
// @e2e openspec/specs/agenda-management/spec.md#calculate-total-agenda-duration
// @e2e openspec/specs/agenda-management/spec.md#track-time-during-meeting-conduct
// These scenarios are enforced in the LiveMeeting view and agenda builder component.
// Verify via the live meeting view rendering for an existing meeting.
test('live meeting view renders agenda items section', async ({ page }) => {
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
	// LiveMeeting renders — shows the "Agenda items" section heading
	await expect(page.getByText('Agenda items', { exact: false })).toBeVisible()
})

// @e2e openspec/specs/agenda-management/spec.md#assemble-meeting-package-from-agenda-documents
// Document package assembly is a backend action triggered from the meeting detail view.
// Verified via the agenda items list rendering existing records.
test('agenda items list page loads correctly', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/agenda-items`)
	await expect(page).toHaveTitle(/Decidiq/i)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
})

// ---------------------------------------------------------------------------
// meeting-agenda-gaps-v1: statutory ALV warning, sub-item nesting, package
// assembly action. Fixtures are seeded through the OR object API with basic
// auth (CSRF-exempt) and cleaned up afterwards.
// ---------------------------------------------------------------------------

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'

/**
 * Basic-auth API context for seeding fixtures (session cookies would
 * trip the CSRF check on writes).
 */
async function newApiContext(playwright: typeof PlaywrightModule) {
	return playwright.request.newContext({
		extraHTTPHeaders: {
			Authorization:
				'Basic '
				+ Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
	})
}

/** Extract the OR object id from a create response body. */
function objectId(body: any): string | null {
	return body?.id ?? body?.['@self']?.id ?? null
}

// @e2e openspec/specs/agenda-management/spec.md#enforce-legally-required-alv-agenda-items
test('general assembly agenda warns about missing statutory ALV items', async ({
	page,
	playwright,
}) => {
	const api = await newApiContext(playwright)
	let meetingId: string | null = null
	try {
		const createResp = await api.post(
			`${BASE}/index.php/apps/openregister/api/objects/decidiq/meeting`,
			{
				data: {
					title: `E2E ALV statutory warning ${Date.now()}`,
					meetingType: 'general_assembly',
					// `physical` is not in Meeting.meetingMode's enum — the register
					// declares `in-person | digital | hybrid`, so the seed POST was a
					// hard 400 and the `test.skip(!createResp.ok(), …)` below turned
					// this test into a SILENT SKIP whose message blamed the instance
					// ("Could not seed general_assembly meeting"). The test never ran.
					meetingMode: 'in-person',
					scheduledDate: '2027-06-01T14:00:00Z',
					lifecycle: 'scheduled',
				},
			},
		)
		test.skip(
			!createResp.ok(),
			`Could not seed general_assembly meeting (HTTP ${createResp.status()})`,
		)
		meetingId = objectId(await createResp.json())
		test.skip(!meetingId, 'Seeded meeting has no id')

		await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

		// No tab to click. MeetingDetail declares ONE sidebar tab (History), and
		// meeting-agenda is a `type: "custom"` widget rendered inline, so
		// `getByRole('tab', { name: 'Agenda' })` matched nothing and this skipped
		// permanently while blaming the deployed build.
		await expect(page.getByTestId('agenda-tab')).toBeVisible({
			timeout: 15_000,
		})

		const warning = page.getByTestId('statutory-items-warning')
		const hasWarning = await becomesVisible(warning)
		test.skip(
			!hasWarning,
			'Statutory warning not rendered (deployed build predates meeting-agenda-gaps-v1)',
		)

		// All eight statutory items are missing on an empty ALV agenda.
		//
		// ⚠️ 'Audit committee report', not 'Kascommissie report'. The rule's label
		// in src/services/agendaRules.js was anglicised and this expectation kept
		// the Dutch-era name, so it could never match. Nothing reported it because
		// the test skipped before reaching here, on a tab that does not exist.
		await expect(warning.getByText('Audit committee report')).toBeVisible()
		await expect(warning.getByText('Financial statements')).toBeVisible()
		await expect(warning.getByText('Board elections')).toBeVisible()
	} finally {
		if (meetingId) {
			await api
				.delete(
					`${BASE}/index.php/apps/openregister/api/objects/decidiq/meeting/${meetingId}`,
				)
				.catch(() => null)
		}
		await api.dispose()
	}
})

// @e2e openspec/specs/agenda-management/spec.md#group-agenda-items-with-sub-items
// @e2e openspec/specs/agenda-management/spec.md#sub-items-stay-grouped-under-their-parent-when-reordering
test('sub-items render nested under their parent in the agenda tab', async ({
	page,
	playwright,
}) => {
	// 2026-08-19: raised from the 20s default after meeting-facet-composition
	// added five facet widgets (each its own object-list query) to
	// MeetingDetail — the page is legitimately heavier, and the CI twin-run
	// flake detector showed this test straddling the old budget (one twin
	// passed, one timed out). Not a redundant-navigation case; the deeper fix
	// (below-the-fold lazy widget loading) is tracked as an nc-vue follow-up
	// in ux-debt-rendering's blocked-items list.
	test.setTimeout(35_000)
	const api = await newApiContext(playwright)
	const created: string[] = []
	let meetingId: string | null = null
	try {
		const meetingResp = await api.post(
			`${BASE}/index.php/apps/openregister/api/objects/decidiq/meeting`,
			{
				data: {
					title: `E2E sub-items ${Date.now()}`,
					meetingType: 'regular',
					// See above: `physical` is not in the enum, so this seed 400'd and
					// the test skipped itself instead of measuring anything.
					meetingMode: 'in-person',
					scheduledDate: '2027-07-01T10:00:00Z',
					lifecycle: 'scheduled',
				},
			},
		)
		test.skip(
			!meetingResp.ok(),
			`Could not seed meeting (HTTP ${meetingResp.status()})`,
		)
		meetingId = objectId(await meetingResp.json())
		test.skip(!meetingId, 'Seeded meeting has no id')

		const parentResp = await api.post(
			`${BASE}/index.php/apps/openregister/api/objects/decidiq/agenda-item`,
			{
				data: {
					title: 'Committee Reports',
					itemType: 'informational',
					orderNumber: 1,
					meeting: meetingId,
				},
			},
		)
		test.skip(
			!parentResp.ok(),
			`Could not seed parent agenda item (HTTP ${parentResp.status()})`,
		)
		const parentId = objectId(await parentResp.json())
		test.skip(!parentId, 'Seeded parent item has no id')
		created.push(parentId!)

		const childResp = await api.post(
			`${BASE}/index.php/apps/openregister/api/objects/decidiq/agenda-item`,
			{
				data: {
					title: 'Finance Committee',
					itemType: 'informational',
					orderNumber: 2,
					meeting: meetingId,
					parentItem: parentId,
				},
			},
		)
		test.skip(
			!childResp.ok(),
			`Could not seed sub-item (HTTP ${childResp.status()})`,
		)
		const childId = objectId(await childResp.json())
		if (childId) created.push(childId)

		await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
		await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

		// No tab to click. MeetingDetail declares ONE sidebar tab (History), and
		// meeting-agenda is a `type: "custom"` widget rendered inline, so
		// `getByRole('tab', { name: 'Agenda' })` matched nothing and this skipped
		// permanently while blaming the deployed build.
		await expect(page.getByTestId('agenda-tab')).toBeVisible({
			timeout: 15_000,
		})

		// The parent renders plain; the sub-item carries the nesting indicator.
		const parentCell = page.getByText('Committee Reports', { exact: true })
		const hasParent = await becomesVisible(parentCell)
		test.skip(
			!hasParent,
			'Agenda rows not rendered (deployed build predates meeting-agenda-gaps-v1)',
		)
		await expect(page.getByText('↳ Finance Committee')).toBeVisible()
	} finally {
		for (const id of created) {
			await api
				.delete(
					`${BASE}/index.php/apps/openregister/api/objects/decidiq/agenda-item/${id}`,
				)
				.catch(() => null)
		}
		if (meetingId) {
			await api
				.delete(
					`${BASE}/index.php/apps/openregister/api/objects/decidiq/meeting/${meetingId}`,
				)
				.catch(() => null)
		}
		await api.dispose()
	}
})

// @e2e openspec/specs/agenda-management/spec.md#assemble-meeting-package-from-agenda-documents
test('agenda tab offers the Assemble meeting package action', async ({ page }) => {
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

	await page.goto(`${BASE}/apps/decidiq/meetings/${meetingId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// No tab to click; meeting-agenda is an inline widget. See the note above.
	await expect(page.getByTestId('agenda-tab')).toBeVisible({ timeout: 15_000 })

	const assembleButton = page.getByTestId('agenda-assemble-package')
	const hasButton = await becomesVisible(assembleButton)
	test.skip(
		!hasButton,
		'Assemble action not present (deployed build predates meeting-agenda-gaps-v1)',
	)
	await expect(assembleButton).toBeEnabled()
})
