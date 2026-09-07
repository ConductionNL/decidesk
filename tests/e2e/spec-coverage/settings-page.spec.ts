/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — app configuration (genuine behavioural).
 *
 * RETARGETED under ADR-079 D1. This file used to deep-link the in-app
 * `/apps/decidiq/settings` route — the manifest `type:"settings"` page — which
 * was a SECOND home for configuration that already lived in the Nextcloud
 * settings framework. That page is deleted; app-level configuration now has
 * exactly one address, `/settings/admin/decidiq`, rendered by
 * lib/Settings/AdminSettings.php and authorized by Nextcloud SERVER-SIDE before
 * the section renders.
 *
 * The scenarios below are the same two the old file owned; only the surface
 * moved. Two things got stronger in the move, and neither is a relaxation:
 *
 *  - The old file carried a `test.fixme` for the register-mapping actions,
 *    because the nc-vue `version-info` / `register-mapping` settings WIDGETS
 *    crashed while rendering the manifest page (`TypeError: e is not a
 *    function` at `Proxy.render`, twice). The admin page does not use those
 *    widgets — CnAdminSettingsShell renders the register mapping itself — so
 *    the scenario is asserted here for real instead of being deferred.
 *  - The old file could not assert on decidiq-origin console errors, for the
 *    same reason. This one can.
 *
 * @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
 * @e2e openspec/specs/openregister-integration/spec.md#configure-register-mapping
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

async function openAdminSettings(page: Page): Promise<void> {
	await page.goto(`${BASE}/settings/admin/decidiq`)
	await page.waitForSelector('[data-testid="admin-root"]', { timeout: 15_000 })
}

// @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
test('Admin settings: the Nextcloud admin section mounts with the app configuration sections', async ({
	page,
}) => {
	await openAdminSettings(page)

	await expect(page).toHaveURL(/\/settings\/admin\/decidiq/)

	// The sections that carry app-level configuration.
	await expect(page.getByTestId('organisation-settings')).toBeVisible()
	await expect(page.getByTestId('organisation-mode-settings')).toBeVisible()
})

// @e2e openspec/specs/admin-settings/spec.md#admin-selects-a-tenant-mode
//
// organisatie_modus was the ONE key the deleted in-app page owned that the
// admin page did not — the other two Advanced fields (ori_endpoint,
// email_voting_enabled) already had sections here. This test is what makes the
// rehoming real rather than asserted: it selects a mode, saves, and requires
// the choice to have reached a consumer on the other side of the server.
//
// The anchor above used to name the REQUIREMENT slug
// (`requirement-req-adm-mode-001-...`). gate-19 indexes SCENARIO slugs, so that
// anchor resolved to nothing and was reported by nobody — a dangling anchor is
// silent, which is why it survived. It now names the scenario this body
// actually exercises.
//
// And the body was extended to cover all three of that scenario's THEN clauses
// rather than two. It used to re-read the admin form it had just written, which
// proves the form round-trips and nothing else; the third clause — "the
// navigation Bodies item relabels on next render" — went unasserted, and a mode
// setting that persists while changing nothing a user sees is the whole failure
// this scenario guards against. Reading the label in the SPA proves persistence
// AND the relabel in one navigation. Mutating src/config/modeLabels.js to any
// other string fails this test and no other, which is the control that makes
// the anchor honest.
//
// ⚠️ THE MODE UNDER TEST IS `corp`, NOT `assoc`, AND IT HAS TO BE.
// This test used to select Association and assert the Bodies entry read
// "Factions & committees". configurable-types-domain-model (#957) applied
// REQ-CTM-010 "one concept, one label" to src/config/modeLabels.js: a faction
// IS a GovernanceBody with bodyType 'faction' and a committee IS one with
// bodyType 'advisory-body' — there is no second schema — so both gov and assoc
// now resolve Organisation to the single label 'Bodies'. That is the intended
// behaviour, and it also destroys this test's control: gov and assoc are no
// longer distinguishable by ANY nav label, so no assertion on that entry could
// tell a saved mode from an ignored one. Simply updating the expected string to
// 'Bodies' would leave a test that passes whether or not the save reached the
// SPA at all — the exact hollow green this file's header argues against.
// `corp` maps Organisation to 'Board' and is therefore the nearest mode that
// still relabels, which keeps the scenario's third THEN clause under a real
// assertion. If corp's label is ever collapsed into 'Bodies' too, the fix is to
// move to `ops` ('Teams'), not to drop the assertion.
test('Admin settings: organisation mode saves, reaches the SPA and relabels the nav', async ({
	page,
}) => {
	await openAdminSettings(page)

	const section = page.getByTestId('organisation-mode-settings')
	await expect(section).toBeVisible()

	// Two nc-vue specifics, both already documented by passing tests in
	// user-settings.spec.ts, and both fatal if ignored:
	//
	//  - NcSelect does NOT set `inheritAttrs: false`, so `data-testid` lands on
	//    the WRAPPER, not the combobox. Clicking the wrapper does not open the
	//    dropdown; click the inner input.
	//  - Options render through NcEllipsisedOption, which splits any label of
	//    10+ characters into two spans, so the option's ACCESSIBLE NAME gains a
	//    space at the split point — an option named "Association (assoc)" never
	//    exists. Match on text content instead, which is unaffected.
	await section.locator('[data-testid="organisation-mode"] input').first().click()
	// CORPORATE, not Association. `assoc` no longer relabels anything: under
	// configurable-types-domain-model REQ-CTM-010 ("one concept, one label")
	// MODE_LABELS.gov and MODE_LABELS.assoc are now the SAME map —
	// `Organisation: 'Bodies'` in both — because 'Factions & bodies' implied two
	// kinds of thing where a faction is just a GovernanceBody with
	// bodyType 'faction'. Switching gov → assoc is therefore invisible in the
	// nav, and this test could never pass again against it. `corp` maps
	// Organisation → 'Board', so it still proves the round trip.
	await page
		.getByRole('option')
		.filter({ hasText: /^Corporate \(corp\)$/ })
		.click()
	await section.getByTestId('organisation-mode-save').click()

	// The round trip is proven against a DIFFERENT consumer, which is both
	// stronger evidence and one page load cheaper than re-reading the form that
	// wrote it. The SPA reads organisatie_modus from the server on boot and
	// resolves nav labels through src/config/modeLabels.js: the Organisation
	// entry is 'Bodies' under gov and 'Board' under corp. So this single
	// navigation covers the scenario's remaining two THEN clauses — the value
	// persisted server-side, and the navigation relabelled.
	//
	// ⚠️ Two page loads, not three. This app awaits initializeStores() BEFORE
	// createApp().mount(), so every navigation blocks on a settings round trip;
	// an earlier draft re-read the admin page here as well and spent the whole
	// 20 s per-test budget on redundant SPA boots. The fix is to remove a
	// navigation, never to widen the timeout.
	await page.goto(`${BASE}/apps/decidiq/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	const bodiesEntry = page
		.getByTestId('cn-nav-entry-GovernanceBodies')
		.or(page.getByRole('link', { name: /^(Bodies|Board)$/ }))
		.first()
	// Asserting the gov label is ABSENT as well as the corp label present is
	// what separates "the label changed" from "something on the page matched".
	//
	// This entry is now COLLAPSIBLE, so these read its whole subtree — the
	// children are Proxy authorizations, Offboarding, Onboarding, Gifts, Other
	// positions and Retirement schedules. That is safe in both directions only
	// because the match is case-sensitive: 'Offboarding' and 'Onboarding'
	// contain 'board', never 'Board'. Keep it that way.
	await expect(bodiesEntry).toContainText('Board')
	await expect(bodiesEntry).not.toContainText('Bodies')

	// Restore the instance default so the assertion is not order-dependent for
	// any later spec that reads the mode. Cleanup goes through the API on
	// purpose: it is not the thing under test, and a third SPA boot is what
	// broke this test's budget.
	const restore = await page.request.put(
		`${BASE}/index.php/apps/decidiq/api/settings`,
		{
			headers: {
				'Content-Type': 'application/json',
				requesttoken: (
					await (
						await page.request.get(`${BASE}/index.php/csrftoken`)
					).json()
				).token,
			},
			data: { organisatie_modus: 'gov' },
		},
	)
	expect(restore.status(), 'restoring organisatie_modus=gov').toBeLessThan(300)
})

// @e2e openspec/specs/openregister-integration/spec.md#configure-register-mapping
test('Admin settings: register mapping exposes its configuration actions', async ({
	page,
}) => {
	await openAdminSettings(page)

	// CnAdminSettingsShell renders the register/schema mapping for the app.
	// These are the actions the old in-app page could never paint.
	await expect(
		page.getByRole('button', { name: /Re-?import configuration/i }).first(),
	).toBeVisible()
})

// @e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults
test('Admin settings: no decidiq-origin 5xx and no decidiq console error on load', async ({
	page,
}) => {
	const serverErrors: string[] = []
	const consoleErrors: string[] = []
	page.on('response', (r) => {
		if (r.status() >= 500 && /decidiq/i.test(r.url()))
			serverErrors.push(`HTTP ${r.status()} ${r.url()}`)
	})
	page.on('console', (m) => {
		if (m.type() === 'error' && /decidiq/i.test(m.location().url ?? ''))
			consoleErrors.push(m.text())
	})

	await openAdminSettings(page)
	await expect(page.getByTestId('organisation-settings')).toBeVisible()

	expect(
		serverErrors,
		`decidiq 5xx on admin settings:\n${serverErrors.join('\n')}`,
	).toHaveLength(0)
	expect(
		consoleErrors,
		`decidiq console errors on admin settings:\n${consoleErrors.join('\n')}`,
	).toHaveLength(0)
})
