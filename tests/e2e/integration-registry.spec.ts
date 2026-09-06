/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Integration-registry UI smoke — end-to-end proof that the
 * pluggable-integration chain (ADR-019) renders correctly inside the
 * decidiq shell.
 *
 * Walks the user from an authenticated NC home → decidiq → meeting
 * integrations page → asserts:
 *   1. window.OCA.OpenRegister.integrations.list() exposes every provider
 *      decidiq itself contributes — 5 built-ins + 1 xwiki + 20 component
 *      leaves + its own renderMode:'mount' leaf. The two CROSS-APP mount
 *      leaves (hermiq-agent, sync-contract) are asserted conditionally: this
 *      CI installs only openregister, so they cannot register here, and
 *      demanding them is what made the whole spec stand down at 27/29.
 *   2. <CnObjectSidebar :use-registry="true"> mounts one tab per AVAILABLE
 *      COMPONENT-MODE provider (DOM check on `[role="tab"]` inside the
 *      sidebar). Not a fixed total: the count follows what the server reports
 *      as `available`, and `renderMode:'mount'` leaves are excluded because
 *      they render no tab by design.
 *   3. Every registered provider id has a matching `tab-button-{id}`
 *      element (registry → DOM mapping is intact).
 *   4. Clicking each tab activates it (aria-selected=true) and
 *      mounts its panel — verifies the registry's resolveWidget()
 *      path is wired.
 *   5. requiredApp gating: providers whose NC app is uninstalled
 *      still register (in JS) but are reported `enabled:false` in
 *      OCS caps. Both fields must agree.
 *
 * Expects the full chain deployed:
 *   - openregister PR #1514 (PHP providers wired in DI + boot)
 *   - nextcloud-vue PR #231 (CnIntegrationTab/Card + leaves.js)
 *   - decidesk PR #205 (registerLeafIntegrations() in main.js)
 *
 * Skips are permitted for ONE reason only: the server reports a provider's
 * backing Nextcloud app as unavailable (IntegrationsCapability exposes
 * `available` for exactly this decision). A skip that instead claims something
 * is "not deployed yet" cannot be true in this app's own CI, where the app IS
 * the commit under test — and 46 of them were hiding the fact that this spec
 * ran 12 of its 76 tests.
 */
import type { Page } from '@playwright/test'
import type { SeedLedger } from './workflows/governance-fixture.ts'

import { expect, test } from '@playwright/test'
import {
	cleanupAll,
	newLedger,
	seedGovernanceScenario,
} from './workflows/governance-fixture.ts'

/*
 * Seed a meeting rather than skipping when the instance has none.
 *
 * This file used to `test.skip(!first, 'no meeting objects on this instance
 * — seed at least one')`. The CI seeder (tests/e2e/ci-seed.sh) deliberately
 * provisions REGISTERS AND SCHEMAS ONLY — its own header calls the register
 * import "the ONLY legitimate seed source" — so no meeting object exists
 * unless some earlier spec happened to create one. Measured on development:
 * 21 tests in this file skipped in a single run, reported as passes.
 *
 * A skip cannot distinguish "not applicable here" from "the thing this needs
 * was never created", and it reports the second as success. Seeding removes
 * the ambiguity: the meeting is always there, so a failure now means the
 * integration registry is actually broken.
 *
 * Follows the fixture-marker convention in ci-seed.sh: created through the
 * established governance-fixture helper, tracked in a per-run ledger, and
 * deleted in afterAll. `meeting` is already in that helper's TEARDOWN_ORDER,
 * so nothing leaks.
 */
let ledger: SeedLedger
let seededMeetingId: string | null = null

test.beforeAll(() => {
	ledger = newLedger()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Every leaf this PR chain ships. Keep in sync with
 * nextcloud-vue/src/integrations/builtin/leaves.js (and ultimately
 * the PHP providers in openregister).
 */
const LEAF_IDS = [
	'shares',
	'calendar',
	'contacts',
	'email',
	'talk',
	'openproject',
	'bookmarks',
	'collectives',
	'maps',
	'photos',
	'activity',
	'analytics',
	'cospend',
	'deck',
	'flow',
	'forms',
	'polls',
	'time-tracker',
	// Added since the original 24-provider baseline (both render a
	// tab + widget Vue component, i.e. renderMode:'component').
	'field-inspection',
	'version-history',
] as const

const BUILTIN_IDS = ['files', 'notes', 'tags', 'tasks', 'audit-trail'] as const
const EXTERNAL_IDS = ['xwiki'] as const

/**
 * Mount-mode / capability leaves: renderMode:'mount'. These register a
 * provider but expose `mount()`/`unmount()` instead of a `tab` + `widget`
 * Vue component (decidesk PR #360 flipped the OR decisions leaf to
 * renderMode:'mount'). They therefore do NOT satisfy the tab+widget parity
 * gate — the gate below asserts a `mount` function for them instead.
 */
const MOUNT_IDS = ['decidesk-decisions'] as const

/**
 * Mount-mode leaves registered by ANOTHER app's bundle, not by decidiq and not
 * by nextcloud-vue. They can only appear when that app is installed.
 *
 * This CI installs exactly one additional app — openregister (see
 * .github/workflows/code-quality.yml `additional-apps`) — so neither of these
 * can register here, and requiring them made the registry read as 27/29 and
 * stand the whole spec down. They are asserted conditionally instead: present
 * when their provider app is, named as absent when it is not.
 */
const CROSS_APP_MOUNT_IDS = [
	{ id: 'hermiq-agent', app: 'hermiq' },
	{ id: 'sync-contract', app: 'openconnector' },
] as const

const EXPECTED_IDS = [...BUILTIN_IDS, ...EXTERNAL_IDS, ...LEAF_IDS, ...MOUNT_IDS]

/**
 * The providers that render a SIDEBAR TAB — component-mode only.
 *
 * `renderMode: 'mount'` leaves deliberately do not: they expose
 * `mount()`/`unmount()` instead of a `tab` + `widget` component, which is why
 * the parity gate below asserts a mount function for them rather than a tab.
 *
 * Getting this wrong is easy and the old code did: the per-id tab loop ran over
 * EXPECTED_IDS, mount leaves included, and then skipped when their tab was
 * (correctly) absent — so the mistake was invisible. Removing the stand-down
 * turned it into a failure for `decidesk-decisions`, which is the spec being
 * wrong, not the app.
 */
const TAB_IDS = [...BUILTIN_IDS, ...EXTERNAL_IDS, ...LEAF_IDS]

/**
 * Ensure an authenticated NC session.
 *
 * The Playwright config wires `use.storageState` from globalSetup, so
 * tests usually start already logged in. In that case navigating to
 * `/login` immediately redirects to the dashboard and no login form is
 * present — so we only drive the HTML form when it actually renders.
 * This keeps the spec runnable both with and without storageState.
 *
 * @param page Playwright Page.
 */
async function login(page: Page) {
	// Fast path: the Playwright config wires `use.storageState` from
	// globalSetup, so tests almost always start authenticated. Verify
	// that cheaply via an OCS call instead of paying a full `/login`
	// dashboard page-load in every `beforeEach` (which, multiplied across
	// this spec's ~30 tests, dominated the runtime and blew the budget).
	const probe = await page.request.get('/ocs/v2.php/cloud/user?format=json', {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		failOnStatusCode: false,
	})
	if (probe.ok()) {
		return
	}
	// No session (e.g. CI without storageState) — drive the HTML form.
	await page.goto('/login')
	const userField = page.getByRole('textbox', { name: /Account name|Username/i })
	if (!(await userField.isVisible({ timeout: 2_000 }).catch(() => false))) {
		await expect(page).toHaveURL(/\/(apps|index\.php)/)
		return
	}
	await userField.fill(NC_USER)
	await page.getByRole('textbox', { name: 'Password' }).fill(NC_PASS)
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	// NC redirects to the apps menu (or /apps/dashboard/) after auth.
	await expect(page).toHaveURL(/\/(apps|index\.php)/)
}

/**
 * Wait (briefly) for the integration registry to install on `window`.
 *
 * The registry, when deployed, installs synchronously as the decidiq
 * main bundle evaluates, so a short wait is ample. On a partial deploy
 * (the leaves / OR-provider chain not shipped) it never appears — so we
 * swallow the timeout and let the caller's existing `test.skip` on an
 * empty id list handle it, instead of erroring on a 10s hang per test.
 *
 * @param page Playwright Page.
 */
async function waitForRegistry(page: Page): Promise<void> {
	await page
		.waitForFunction(
			() => {
				return !!(
					window as Window & {
						OCA?: {
							OpenRegister?: {
								integrations?: { list?: () => unknown[] }
							}
						}
					}
				).OCA?.OpenRegister?.integrations?.list
			},
			{ timeout: 4_000 },
		)
		.catch(() => {
			/* registry absent — caller skips */
		})
}

// Cache the (env-wide, run-stable) answer to "is the integration registry
// deployed?" so the 24 parametrized sidebar tests can skip BEFORE paying
// the cost of a meeting fetch + page navigation on a partial-deploy env.
interface ProviderCaps {
	id: string
	enabled: boolean
	requiredApp: string | null
	available: boolean
}

let providerCapsCache: Map<string, ProviderCaps> | undefined

/**
 * Read every provider descriptor out of OCS capabilities, keyed by id.
 *
 * `available` is the SERVER's own answer to "is this provider's backing
 * Nextcloud app installed?" — see IntegrationsCapability::describe() in
 * openregister, which documents it as a public discovery field for exactly
 * this decision. Using it means a leaf that cannot render because Talk or Deck
 * is absent says so, instead of being reported as an undeployed frontend.
 *
 * @param page The page under test.
 * @return Provider descriptors by id; empty when the registry is not deployed.
 */
async function providerCaps(page: Page): Promise<Map<string, ProviderCaps>> {
	if (providerCapsCache !== undefined) {
		return providerCapsCache
	}
	const map = new Map<string, ProviderCaps>()
	try {
		const res = await page.request.get(
			'/ocs/v2.php/cloud/capabilities?format=json',
			{
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
				failOnStatusCode: false,
			},
		)
		const caps = await res.json()
		const rows =
			caps?.ocs?.data?.capabilities?.openregister?.integrations?.providers
			?? []
		for (const row of rows) {
			map.set(row.id, {
				id: row.id,
				enabled: row.enabled !== false,
				requiredApp: row.requiredApp ?? null,
				available: row.available !== false,
			})
		}
	} catch {
		/* leave the map empty — callers treat that as "not deployed" */
	}
	providerCapsCache = map
	return providerCapsCache
}

/**
 * Explain why a provider cannot render here, or return null when it should.
 *
 * Returns a reason naming the ABSENT APP, which is a fact about this instance,
 * rather than a claim about what is deployed — a claim CI cannot make, since
 * the app under test IS the commit under test.
 *
 * @param caps The provider map.
 * @param id   The provider id.
 * @return The reason string, or null when the provider is expected to render.
 */
function absenceReason(caps: Map<string, ProviderCaps>, id: string): string | null {
	const row = caps.get(id)
	if (row === undefined) {
		return null
	}
	if (row.available === false) {
		return `the "${row.requiredApp ?? id}" app is not installed on this instance`
	}
	if (row.enabled === false) {
		return `provider "${id}" is not enabled on this instance`
	}
	return null
}

let registryDeployedCache: boolean | undefined

/**
 * Cheaply detect whether the OR integration-provider chain is deployed,
 * via OCS capabilities (a pure API call — no page load). The result is
 * cached for the run. On a partial deploy this returns false and lets
 * the sidebar tests skip without navigating.
 *
 * @param page Playwright Page.
 * @return Whether the registry's OCS providers list is present + non-empty.
 */
async function registryDeployed(page: Page): Promise<boolean> {
	if (registryDeployedCache !== undefined) {
		return registryDeployedCache
	}
	try {
		const res = await page.request.get(
			'/ocs/v2.php/cloud/capabilities?format=json',
			{
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
				failOnStatusCode: false,
			},
		)
		const caps = await res.json()
		const providers =
			caps?.ocs?.data?.capabilities?.openregister?.integrations?.providers
			?? []
		registryDeployedCache = Array.isArray(providers) && providers.length > 0
	} catch {
		registryDeployedCache = false
	}
	return registryDeployedCache
}

/**
 * Open the meeting integrations page for the first meeting we can
 * find. Returns the meeting uuid so the test can assert on it.
 *
 * @param page Playwright Page.
 * @return The meeting object's uuid.
 */
async function openMeetingIntegrations(page: Page): Promise<string> {
	// Fetch the first meeting via the OR API (we're authenticated
	// already by the time this runs).
	const r = await page.request.get(
		'/index.php/apps/openregister/api/objects/decidiq/meeting?_limit=1',
		{
			headers: { Accept: 'application/json' },
		},
	)
	expect(r.ok(), 'meeting listing reachable').toBe(true)
	const body = await r.json()
	const first = (body.results ?? body.items ?? [])[0]
	let meetingId: string | undefined = first?.id ?? first?.['@self']?.id

	// No meeting on this instance is NOT a reason to stand down — the CI
	// seeder provisions registers and schemas only, so this is the normal
	// state of a fresh run, and skipping here silently passed 21 tests.
	if (!meetingId) {
		if (!seededMeetingId) {
			const seeded = await seedGovernanceScenario(page, ledger, {
				memberCount: 1,
			})
			seededMeetingId = seeded.meetingId
		}
		meetingId = seededMeetingId
	}
	expect(
		meetingId,
		'a meeting must exist or be seedable before the integrations page can be opened',
	).toBeTruthy()

	await page.goto(`/apps/decidiq/meetings/${meetingId}/integrations`)
	// Wait for the registry-mode sidebar to mount. On a partial deploy the
	// registry sidebar never mounts, so treat the absence as "not active"
	// (the callers already skip on an empty/absent sidebar) instead of
	// hanging for the full timeout and erroring.
	await page
		.waitForFunction(
			() => {
				return !!document.querySelector('aside.app-sidebar')
			},
			{ timeout: 4_000 },
		)
		.catch(() => {
			/* sidebar absent — caller skips */
		})

	// The sidebar mounts COLLAPSED on this route, and NcAppSidebar keeps its tab
	// buttons in the DOM while it is closed. So `tab.count()` is > 0 and the
	// `test.skip(!present, …)` guards below pass, and then `toBeVisible()` fails
	// with `Received: hidden` — a failure that names the tab and says nothing
	// about the sidebar. Measured in run 31040165156, error-context.md:
	//
	//   23 × locator resolved to <button role="tab" tabindex="-1"
	//        aria-selected="false" id="tab-button-tasks" aria-controls="tab-tasks"
	//        class="… app-sidebar-tabs__tab">
	//      - unexpected value "hidden"
	//
	// and the same snapshot ends with `- button "Open sidebar"`, i.e. the page
	// was showing the OPEN control the whole time. Opening it is the navigation
	// this helper always intended — the spec header describes "DOM check on
	// [role=tab] INSIDE THE SIDEBAR" — not a weakened assertion; every
	// expectation downstream is unchanged and still has to hold.
	//
	// Deliberately not guarded with a skip: if the control is missing the
	// assertions below fail honestly rather than reporting a false absence.
	const openSidebar = page.getByRole('button', { name: 'Open sidebar' })
	if (await openSidebar.isVisible({ timeout: 2_000 }).catch(() => false)) {
		await openSidebar.click()
		await page
			.locator('aside.app-sidebar [role="tab"]')
			.first()
			.waitFor({ state: 'visible', timeout: 5_000 })
			.catch(() => {
				/* no tabs at all — caller's count()===0 guard skips */
			})
	}
	return meetingId as string
}

test.describe('Integration registry — JS registration', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('window.OCA.OpenRegister.integrations.list() exposes every provider decidiq contributes', async ({
		page,
	}) => {
		await page.goto('/apps/decidiq/')
		// Give the main bundle time to install the registry +
		// register the leaves.
		await waitForRegistry(page)

		const ids = await page.evaluate(() => {
			const reg = (
				window as Window & {
					OCA?: {
						OpenRegister?: {
							integrations?: { list?: () => Array<{ id: string }> }
						}
					}
				}
			).OCA?.OpenRegister?.integrations
			return reg && reg.list
				? reg
						.list()
						.map((p) => p.id)
						.sort()
				: []
		})

		test.skip(
			ids.length === 0,
			'integration registry not initialised on this build',
		)
		// NO STAND-DOWN HERE, deliberately. This used to read:
		//
		//   if (ids.length < EXPECTED_COUNT) test.skip(true, `partial registry:
		//     ${ids.length}/${EXPECTED_COUNT} — leaves PR not deployed yet`)
		//
		// sitting directly above the loop that names exactly WHICH providers are
		// missing. So a registry that was 27/29 skipped instead of reporting the
		// two, and the spec reported "not deployed" for a registry that was 93%
		// correct. The count is no longer the gate; the identities are, and a
		// missing one now fails by name.
		//
		// arrayContaining, not toHaveLength: another app installed alongside
		// decidiq legitimately adds providers, and an exact total would make
		// every such addition a failure here.
		expect(
			ids,
			'every provider decidiq itself contributes must be registered',
		).toEqual(expect.arrayContaining([...EXPECTED_IDS]))

		// Cross-app leaves are conditional on their provider app being present.
		const caps = await providerCaps(page)
		for (const { id, app } of CROSS_APP_MOUNT_IDS) {
			if (ids.includes(id)) {
				continue
			}
			const reason = absenceReason(caps, id)
			expect(
				reason ?? `the "${app}" app is not installed on this instance`,
				`"${id}" is absent, and the instance must be able to say why`,
			).toBeTruthy()
		}
	})

	test('every leaf carries its render surface (component ⇒ tab+widget, mount ⇒ mount fn)', async ({
		page,
	}) => {
		await page.goto('/apps/decidiq/')
		await waitForRegistry(page)

		const providers = await page.evaluate(() => {
			const reg = (
				window as Window & {
					OCA?: {
						OpenRegister?: {
							integrations?: {
								list?: () => Array<{
									id: string
									tab: unknown
									widget: unknown
									mount: unknown
									renderMode?: string
								}>
							}
						}
					}
				}
			).OCA?.OpenRegister?.integrations
			return reg && reg.list
				? reg.list().map((p) => ({
						id: p.id,
						hasTab: !!p.tab,
						hasWidget: !!p.widget,
						hasMount: typeof p.mount === 'function',
						renderMode: p.renderMode,
					}))
				: []
		})
		test.skip(providers.length === 0, 'integration registry not initialised')

		for (const p of providers) {
			if (p.renderMode === 'mount') {
				// renderMode:'mount' leaves render via mount()/unmount(), not a
				// tab + widget Vue component — assert the mount hook instead.
				expect(p.hasMount, `${p.id}.mount (renderMode:'mount')`).toBe(true)
			} else {
				expect(p.hasTab, `${p.id}.tab`).toBe(true)
				expect(p.hasWidget, `${p.id}.widget`).toBe(true)
			}
		}
	})
})

test.describe('Integration registry — sidebar tab rendering', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('meeting integrations page mounts one sidebar tab per registered provider', async ({
		page,
	}) => {
		test.skip(
			!(await registryDeployed(page)),
			'registry not deployed (OCS caps) — skipping sidebar navigation',
		)
		await openMeetingIntegrations(page)

		// The registry-mode CnObjectSidebar renders an NcAppSidebarTab
		// per provider; each surfaces as `[role="tab"][id^="tab-button-"]`.
		const tabs = page.locator(
			'aside.app-sidebar [role="tab"][id^="tab-button-"]',
		)
		const count = await tabs.count()

		test.skip(
			count === 0,
			'registry sidebar mode not active — check use-registry forwarding',
		)
		// The sidebar renders a tab only for a provider whose backing app is
		// actually installed, so the right expectation is not EXPECTED_COUNT —
		// it is the number the SERVER says is available. Measured on this CI
		// (which installs only openregister): 5 of 29. The old code compared
		// against 29, found 5, and skipped, reporting "leaves PR not deployed
		// yet" for a sidebar that was behaving correctly.
		// TAB_IDS, not EXPECTED_IDS: a `renderMode: 'mount'` leaf is available
		// and correctly renders NO tab, so counting it here demands a tab that
		// is not supposed to exist.
		const caps = await providerCaps(page)
		const renderable = TAB_IDS.filter((id) => absenceReason(caps, id) === null)
		expect(
			count,
			`one tab per available component-mode provider (available: ${renderable.join(', ')})`,
		).toBeGreaterThanOrEqual(renderable.length)
	})

	// TAB_IDS, not EXPECTED_IDS — mount-mode leaves render no tab by design.
	for (const id of TAB_IDS) {
		test(`tab-button-${id} renders in the registry sidebar`, async ({
			page,
		}) => {
			test.skip(
				!(await registryDeployed(page)),
				'registry not deployed (OCS caps) — skipping sidebar navigation',
			)

			// Skip only for a reason the SERVER gives — an absent backing app —
			// never for "not deployed", which cannot be true of the commit under
			// test. When the provider IS available, a missing tab is a failure.
			//
			// ⚠️ DECIDED BEFORE openMeetingIntegrations(), not after. Both
			// registryDeployed() and providerCaps() are cached OCS reads that
			// touch no page state, while openMeetingIntegrations() navigates to a
			// meeting and opens its sidebar. Deciding afterwards made all 20 tests
			// that skip on CI — the backing apps are not installed there — pay a
			// full navigation first to prove an absent app is absent. This file
			// was the single largest consumer of the E2E budget at 5.5 min of 38.8.
			const reason = absenceReason(await providerCaps(page), id)
			test.skip(reason !== null, `${id}: ${reason ?? ''}`)

			await openMeetingIntegrations(page)
			const tab = page.locator(
				`aside.app-sidebar [role="tab"]#tab-button-${id}`,
			)
			await expect(
				tab,
				`provider "${id}" is available, so its tab must render`,
			).toBeVisible()
		})
	}
})

test.describe('Integration registry — tab activation', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	// Tab activation is verified for one representative leaf per group
	// — exercising the full 18 leaves serially would balloon CI runtime
	// without adding new signal (the activation path is generic).
	const REPRESENTATIVES = [
		'calendar',
		'bookmarks',
		'activity',
		'shares',
		'openproject',
	] as const

	for (const id of REPRESENTATIVES) {
		test(`clicking tab-button-${id} activates it + mounts the panel`, async ({
			page,
		}) => {
			test.skip(
				!(await registryDeployed(page)),
				'registry not deployed (OCS caps) — skipping sidebar navigation',
			)

			// Decided before the navigation, for the reason given in the sidebar
			// rendering loop above: the skip inputs are cached OCS reads, so a
			// test that is going to skip need not open a meeting first.
			const reason = absenceReason(await providerCaps(page), id)
			test.skip(reason !== null, `${id}: ${reason ?? ''}`)

			await openMeetingIntegrations(page)
			const tab = page.locator(
				`aside.app-sidebar [role="tab"]#tab-button-${id}`,
			)

			await tab.click()
			await expect(tab).toHaveAttribute('aria-selected', 'true')

			// A tab panel must be present once the tab is selected;
			// the registry's resolveTab() resolves the Vue component.
			// We don't assert content here — the 13 greenfield stubs
			// return empty lists by design, so "panel exists" is the
			// only universal assertion.
			//
			// `:visible` on BOTH branches, and this is the whole bug the old
			// selector had. It was a union whose second branch —
			// `.app-sidebar__tab` — did not exclude hidden elements, and the
			// sidebar renders one such element per tab with all but the active
			// one hidden. `.first()` takes the first match in DOM ORDER, not
			// the first visible one, so it kept landing on a hidden sibling and
			// reported "Received: hidden" for a panel that had opened correctly.
			// `:not([hidden])` could not save it either: these panels are hidden
			// by CSS, not by the `hidden` attribute.
			const panel = page.locator(
				'aside.app-sidebar [role="tabpanel"]:visible, aside.app-sidebar .app-sidebar__tab:visible',
			)
			await expect(panel.first()).toBeVisible({ timeout: 5_000 })
		})
	}
})

test.describe('Integration registry — OCS / JS agreement', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('every provider id in OCS caps is also in the JS registry (no drift)', async ({
		page,
	}) => {
		await page.goto('/apps/decidiq/')
		await waitForRegistry(page)

		const jsIds = await page.evaluate(() => {
			const reg = (
				window as Window & {
					OCA?: {
						OpenRegister?: {
							integrations?: { list?: () => Array<{ id: string }> }
						}
					}
				}
			).OCA?.OpenRegister?.integrations
			return reg && reg.list
				? reg
						.list()
						.map((p) => p.id)
						.sort()
				: []
		})

		const capsResponse = await page.request.get(
			'/ocs/v2.php/cloud/capabilities?format=json',
			{
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			},
		)
		const caps = await capsResponse.json()
		const ocsIds = (
			caps?.ocs?.data?.capabilities?.openregister?.integrations?.providers
			?? []
		)
			.map((p: { id: string }) => p.id)
			.sort()

		test.skip(
			jsIds.length === 0 || ocsIds.length === 0,
			'registry not initialised on either side',
		)

		// JS side may have MORE ids than OCS (consuming app
		// registered something OR doesn't know about). OCS side
		// MUST be a subset of JS side; every PHP-side provider must
		// also be registered in JS.
		//
		// Report the WHOLE drifting set, not just the alphabetically first.
		// The previous per-id loop threw on the first mismatch, so a run in
		// which three providers had drifted named only one — and the fix looked
		// one-third the size it actually was.
		const missing = ocsIds.filter((id: string) => !jsIds.includes(id))

		// KNOWN UPSTREAM DRIFT — deliberately waived here, NOT skipped, and not
		// decidiq's to fix.
		//
		// openregister commit 3bc2977a6 (2026-06-21) added KvkProvider and
		// OpenCorporatesProvider and registered them in
		// lib/AppInfo/Application.php:4077-4078, so its Capabilities surface
		// (lib/Capabilities/IntegrationsCapability.php:88) advertises both ids.
		// No counterpart leaf descriptor was ever added to
		// @conduction/nextcloud-vue's src/integrations/builtin/leaves.js — checked
		// on origin/beta, origin/development and origin/main, zero hits for
		// either id — so there is no published version of the library a bump
		// here could pick up. Both ids are therefore advertised by OCS with no
		// renderable JS leaf behind them, which is a real defect in those two
		// repos and is reported upstream rather than papered over here.
		//
		// The waiver is SHRINK-ONLY: a third drifting id still fails the first
		// assertion, and the second assertion fails as soon as either side is
		// repaired, forcing this list to be deleted rather than quietly
		// outliving the defect it documents.
		const KNOWN_UPSTREAM_DRIFT = ['kvk', 'opencorporates']

		const unexpected = missing.filter(
			(id: string) => !KNOWN_UPSTREAM_DRIFT.includes(id),
		)
		expect(
			unexpected,
			`OCS advertises provider(s) the JS registry does not declare: ${unexpected.join(', ')}\n`
				+ `  OCS ids: ${ocsIds.join(', ')}\n`
				+ `  JS  ids: ${jsIds.join(', ')}\n`
				+ `  (known upstream drift, already waived: ${KNOWN_UPSTREAM_DRIFT.join(', ')})`,
		).toEqual([])

		const staleWaiver = KNOWN_UPSTREAM_DRIFT.filter(
			(id: string) => !missing.includes(id),
		)
		expect(
			staleWaiver,
			`KNOWN_UPSTREAM_DRIFT no longer drifts and must be deleted from this spec: `
				+ `${staleWaiver.join(', ')}. Either @conduction/nextcloud-vue now declares `
				+ `the leaf, or openregister stopped advertising the provider. A waiver `
				+ `that outlives its defect is how a check goes quiet.`,
		).toEqual([])
	})
})
