/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where the SPA's permission list comes from (decidiq#1267).
 *
 * The nav filter and the route guard both read one list, built in
 * `src/utils/permissions.js` from a single server-provided input: initial
 * state `isAdmin`, published by `DashboardController::renderIndex()` from
 * Nextcloud's own admin test on the acting user. Before #1267 the list was
 * derived from `window.OC.currentUser.permissions`, a property that does not
 * exist (OC.currentUser is the uid STRING), so the list was always `[]` and
 * the nav filter failed open for everyone.
 *
 * This spec asserts the half a browser can observe: what each account's page
 * load actually receives. How that value becomes a list, and how the guard
 * treats the list, is pinned in `tests/vitest/navPermissions.spec.js`; the
 * scenarios for those carry reason-bearing exclusions in the spec.
 *
 * WHY A REAL, NON-ADMIN ACCOUNT. The project's storage state is the `admin`
 * session, and admin is the one account for which the old fail-open bug and
 * a correct implementation look identical: both show everything. Only a
 * second account in no group at all can tell "the server said false" from
 * "nobody said anything". Every assertion names the account it is about.
 *
 * WHY A BROWSER LOGIN, NOT HTTP BASIC. The scenario is about what a person's
 * page load receives, so each account logs in through the login form in its
 * own context, exactly as the global setup does for admin.
 *
 * @e2e openspec/specs/authorization-via-or-rbac/spec.md#the-server-tells-the-spa-whether-the-account-is-an-administrator
 */
import type { APIResponse, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from '../base-url.ts'

const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

// From a CSPRNG, not Math.random(): the password below is built from it.
const RUN_ID = `${Date.now()}-${randomBytes(6).toString('hex')}`

/** An account in no group at all, created for this run and deleted after it. */
const MEMBER = {
	uid: `dq1267-member-${RUN_ID}`,
	password: `Dq1267-member-${RUN_ID}-pw`,
}

/**
 * OCS headers. `OCS-APIRequest: true` is required: without it Nextcloud answers
 * an OCS call with 412 (CSRF) instead of processing it.
 */
const OCS = { Accept: 'application/json', 'OCS-APIRequest': 'true' }

/**
 * Read a response body for an assertion message without letting a non-JSON
 * body throw inside the message builder.
 *
 * @param resp The response to describe.
 * @return A short printable description.
 */
async function summarise(resp: APIResponse): Promise<string> {
	const text = await resp.text().catch(() => '<unreadable body>')
	return `HTTP ${resp.status()} ${text.slice(0, 400)}`
}

/**
 * Log in through the form in a fresh context, with no inherited cookies, and
 * open the decidiq dashboard.
 *
 * @param browser  The Playwright browser.
 * @param username The account to log in as.
 * @param password Its password.
 * @return The page, parked on the decidiq dashboard.
 */
async function openDashboardAs(
	browser: Browser,
	username: string,
	password: string,
): Promise<Page> {
	const context = await browser.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	await page.goto('/index.php/login', { timeout: 60_000 })
	await page.locator('input[name="user"]').fill(username)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForURL((url) => !/\/login(\?|$|\/)/.test(url.pathname), {
		timeout: 60_000,
	})
	await page.goto('/index.php/apps/decidiq/', { timeout: 60_000 })
	return page
}

/**
 * The value Nextcloud rendered for decidiq's `isAdmin` initial state.
 *
 * Nextcloud writes each initial state as a hidden input whose value is the
 * base64 of the JSON-encoded value. Read it from the DOM the server sent,
 * not from any variable the app derives from it.
 *
 * @param page    A page on the decidiq dashboard.
 * @param account The account name, for the assertion message.
 * @return The decoded value.
 */
async function isAdminStateOf(page: Page, account: string): Promise<unknown> {
	const input = page.locator('#initial-state-decidiq-isAdmin')
	await expect(
		input,
		`${account}: the dashboard must publish initial state decidiq/isAdmin`,
	).toHaveCount(1, { timeout: 30_000 })
	const raw = await input.getAttribute('value')
	expect(raw, `${account}: initial state decidiq/isAdmin has no value`).not.toBeNull()
	return JSON.parse(Buffer.from(raw as string, 'base64').toString('utf8'))
}

test.describe('decidiq#1267: the server tells the SPA whether the account is an administrator', () => {
	test.beforeAll(async ({ playwright }) => {
		const admin = await playwright.request.newContext({
			httpCredentials: { password: ADMIN_PASS, send: 'always', username: ADMIN_USER },
			storageState: { cookies: [], origins: [] },
		})
		const created = await admin.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
			data: { password: MEMBER.password, userid: MEMBER.uid },
			headers: OCS,
		})
		expect(created.ok(), `creating account ${MEMBER.uid}: ${await summarise(created)}`).toBe(
			true,
		)

		// The whole point of this account is that it holds nothing. Prove it
		// before relying on it, so a stray default group cannot make the
		// "false" assertion below pass for the wrong reason, or fail for one.
		const groups = await admin.get(
			`${BASE}/ocs/v2.php/cloud/users/${MEMBER.uid}/groups?format=json`,
			{ headers: OCS },
		)
		expect(groups.ok(), `reading the groups of ${MEMBER.uid}: ${await summarise(groups)}`).toBe(
			true,
		)
		const body = await groups.json()
		expect(
			body?.ocs?.data?.groups ?? [],
			`${MEMBER.uid} must be in no group at all, in particular not in "admin"`,
		).toEqual([])
		await admin.dispose()
	})

	test.afterAll(async ({ playwright }) => {
		const admin = await playwright.request.newContext({
			httpCredentials: { password: ADMIN_PASS, send: 'always', username: ADMIN_USER },
			storageState: { cookies: [], origins: [] },
		})
		await admin.delete(`${BASE}/ocs/v2.php/cloud/users/${MEMBER.uid}?format=json`, {
			headers: OCS,
		})
		await admin.dispose()
	})

	test('an administrator receives isAdmin true', async ({ browser }) => {
		const page = await openDashboardAs(browser, ADMIN_USER, ADMIN_PASS)
		try {
			expect(
				await isAdminStateOf(page, ADMIN_USER),
				`${ADMIN_USER} (a Nextcloud administrator) must receive isAdmin === true`,
			).toBe(true)
		} finally {
			await page.context().close()
		}
	})

	test('an account in no group receives isAdmin false, not nothing', async ({ browser }) => {
		const page = await openDashboardAs(browser, MEMBER.uid, MEMBER.password)
		try {
			// toBe(false), not toBeFalsy(): an absent value is exactly the
			// fail-open input #1267 removed, and must not pass as a "no".
			expect(
				await isAdminStateOf(page, MEMBER.uid),
				`${MEMBER.uid} (in no group) must receive isAdmin === false`,
			).toBe(false)
		} finally {
			await page.context().close()
		}
	})
})
