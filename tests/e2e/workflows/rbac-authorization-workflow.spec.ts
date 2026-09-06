/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent e2e — the consume-or-rbac-authorization migration
 * driven through the real SPA UI + object API. It proves the who-may-act
 * outcomes preserved by the migration are STILL enforced, now via OpenRegister
 * RBAC scopes projected from the governance-body roster:
 *
 *   - a signatory (chair/vice-chair/secretary of the body) may initiate QES
 *     signing on a Minutes record; a non-signatory is denied (403);
 *   - only a member of the body's chair scope may run a chair-only meeting
 *     lifecycle transition; a non-chair is denied;
 *   - a disallowed domain transition is still refused by workflow policy,
 *     independent of the actor's scope;
 *   - a non-admin is denied on every previously `requireAdmin()`-gated
 *     controller surface; an admin is allowed.
 *
 * LIVE-RUN STATUS. The admin-gating scenario RUNS: it drives the four
 * RequiresOrAdmin controllers over HTTP as an anonymous caller (401), as the
 * non-admin account ci-seed.sh provisions (403), and as the admin session
 * Playwright holds (not denied).
 *
 * The three scope-dependent scenarios still skip, and the reason has been
 * corrected. It used to read "decidiq not deployed on the shared instance",
 * which stopped being true long ago — decidiq is deployed, and 205 other tests
 * drive it on the same run. What actually blocks them is narrower: each needs a
 * SECOND account to act, and each acts through a POST, which Nextcloud answers
 * 412 unless the request carries a CSRF requesttoken from that account's own
 * session. This suite ships a single storageState (admin) and no helper to sign
 * in as anybody else, so the denial half cannot be staged. Each is covered at
 * unit level (GovernanceScopeGuardTest, GovernanceRoleScopeProjectorTest,
 * MeetingServiceTest chair-scope allow/deny + fail-closed,
 * EIDASSignatureControllerTest signatory allow/deny).
 *
 * 🔑 The old reason was not merely stale, it was load-bearing: while it stood,
 * nobody re-read the endpoint list underneath it, and three of its four URLs
 * had rotted to routes that do not exist.
 *
 * The backend-only / build-time scenarios (scope projection idempotency, the
 * anti-pattern gate being clean, and the unresolved-scope fail-closed edge)
 * are excluded inline in the spec delta (`@e2e exclude ...`) per gate-19 and
 * are NOT duplicated here — they have no distinct UI flow and are unit/gate
 * covered.
 *
 * @e2e openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#a-signatory-may-initiate-signing
 * @e2e openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#a-non-signatory-is-denied-by-openregister
 * @e2e openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#only-the-chair-may-run-a-chair-only-transition
 * @e2e openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#domain-policy-still-forbids-a-disallowed-transition-regardless-of-actor
 * @e2e openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#a-non-admin-is-denied-on-every-previously-admin-gated-surface
 * @e2e openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#an-admin-is-allowed
 */
import { expect, test } from '@playwright/test'
import { BASE } from './governance-fixture.ts'

/**
 * The non-admin account ci-seed.sh provisions purely so admin-gating is
 * testable. A suite whose only account is an admin cannot tell "denies a
 * non-admin" from "denies nobody".
 */
const MEMBER_USER = process.env.E2E_MEMBER_USER ?? 'decidiq-e2e-member'
const MEMBER_PASS = process.env.E2E_MEMBER_PASS ?? 'decidiq-e2e-member-pw'

// The four controllers consuming the shared RequiresOrAdmin trait:
// AuditLogController, GovernanceReportController,
// MultilingualReconciliationController and RegulatorExportController. EVERY
// public method on all four opens with requireAdmin(), so any of their routes
// would serve; these are the read-only index routes.
//
// GET on purpose. Nextcloud's CSRF middleware answers 412 to a POST carrying no
// requesttoken, and it does so BEFORE the guard runs — measured on a live
// instance — so a POST here would mask the 403 this test exists to prove.
//
// ⚠️ THREE OF THE FOUR URLs THIS LIST CARRIED WERE WRONG:
// `/api/governance-report/generate`, `/api/multilingual/reconcile` and
// `/api/regulator-export` match NO route in appinfo/routes.php (the real ones
// are plural, and multilingual exposes `/queue`, never `/reconcile`). Nothing
// ever noticed, because the only test reading them was skipped unconditionally.
// A dead URL inside a skipped test is invisible twice over.
const ADMIN_GATED_ENDPOINTS = [
	{ name: 'AuditLog', url: '/apps/decidiq/api/audit-log' },
	{ name: 'GovernanceReport', url: '/apps/decidiq/api/governance-reports' },
	{
		name: 'MultilingualReconciliation',
		url: '/apps/decidiq/api/multilingual/queue',
	},
	{ name: 'RegulatorExport', url: '/apps/decidiq/api/regulator-exports' },
]

test.describe('consume-or-rbac-authorization — who-may-act preserved via OR RBAC', () => {
	test('a signatory may initiate signing; a non-signatory is denied (403)', async ({
		page: _page,
	}) => {
		// Deferred live run: requires decidiq deployed with a Minutes record on a
		// body whose signatory scope (decidesk:body:{id}:signatory) is populated by
		// the role projector. A signatory's POST to
		// /apps/decidiq/api/eidas/{minutesId}/initiate is accepted (202); a
		// non-signatory's is rejected (403) and initializeSigningRequest is never
		// reached. Asserted at unit level in EIDASSignatureControllerTest +
		// GovernanceScopeGuardTest.
		test.skip(
			true,
			'needs a SECOND account acting through a CSRF-bearing session: these are POST '
				+ 'endpoints, and the suite ships one storageState (admin) with no helper to '
				+ 'sign in as anyone else. Covered at unit level, see the note above.',
		)
		expect(BASE).toBeTruthy()
	})

	test('only the chair may run a chair-only lifecycle transition', async ({
		page: _page,
	}) => {
		// Deferred live run: a chair-only transition (e.g. legislative opened→adjourned)
		// succeeds for a member of decidesk:body:{id}:chair and is refused for a
		// non-chair with "Only the meeting chair may perform this transition."
		// Asserted at unit level in MeetingServiceTest (chair allow / non-chair deny
		// / fail-closed when the body scope is unresolvable).
		test.skip(
			true,
			'needs a SECOND account acting through a CSRF-bearing session: these are POST '
				+ 'endpoints, and the suite ships one storageState (admin) with no helper to '
				+ 'sign in as anyone else. Covered at unit level, see the note above.',
		)
		expect(BASE).toBeTruthy()
	})

	test('a disallowed domain transition is refused independent of actor scope', async ({
		page: _page,
	}) => {
		// Deferred live run: a domain whose workflow sets allowPause:false refuses
		// opened→paused for the chair too (workflow policy, not actor auth). Asserted
		// at unit level in MeetingServiceTest testDomainDisallowedTransitionReturnsFailure.
		test.skip(
			true,
			'needs a SECOND account acting through a CSRF-bearing session: these are POST '
				+ 'endpoints, and the suite ships one storageState (admin) with no helper to '
				+ 'sign in as anyone else. Covered at unit level, see the note above.',
		)
		expect(BASE).toBeTruthy()
	})

	test('a non-admin is denied on every previously admin-gated surface; an admin is allowed', async ({
		page,
		playwright,
	}) => {
		// Guard the guard: an empty list would make every loop below vacuous.
		expect(ADMIN_GATED_ENDPOINTS.length).toBe(4)

		// 1. Unauthenticated — 401 before the guard ever looks at a role.
		//
		// The empty storageState is load-bearing: without it this context picks
		// up the admin session cookie from the project's `use.storageState`, and
		// Nextcloud answers 412 (CSRF, no requesttoken) rather than 401. Measured
		// — the first version of this test read that 412 as a failing assertion
		// when it was really testing the wrong caller.
		const anon = await playwright.request.newContext({
			storageState: { cookies: [], origins: [] },
		})
		for (const ep of ADMIN_GATED_ENDPOINTS) {
			const resp = await anon.get(`${BASE}${ep.url}`, {
				headers: { Accept: 'application/json' },
			})
			expect(
				resp.status(),
				`${ep.name} must answer 401 to an anonymous caller`,
			).toBe(401)
		}
		await anon.dispose()

		// 2. Authenticated, not an admin — 403 from RequiresOrAdmin. This is the
		// leg the suite could never run before: with `admin` as its only account,
		// "denies a non-admin" was indistinguishable from "denies nobody".
		// `send: 'always'` is required, not cosmetic. By default Playwright holds
		// basic credentials back until it sees a WWW-Authenticate challenge, and
		// decidiq's guard answers a bare 401 without one — so the credentials are
		// never offered and the call reads as anonymous. Measured: without it
		// every endpoint answered 401, i.e. this leg silently retested leg 1.
		const member = await playwright.request.newContext({
			httpCredentials: {
				password: MEMBER_PASS,
				send: 'always',
				username: MEMBER_USER,
			},
			storageState: { cookies: [], origins: [] },
		})
		for (const ep of ADMIN_GATED_ENDPOINTS) {
			const resp = await member.get(`${BASE}${ep.url}`, {
				headers: { Accept: 'application/json' },
			})
			expect(
				resp.status(),
				`${ep.name} must answer 403 to an authenticated non-admin`,
			).toBe(403)
		}
		await member.dispose()

		// 3. The admin session Playwright already holds — allowed through. Asserted
		// as "not denied" rather than 200: several of these answer 404 or an empty
		// collection on a fresh instance, and that is fine. What must never happen
		// is an admin meeting the guard.
		for (const ep of ADMIN_GATED_ENDPOINTS) {
			const resp = await page.request.get(`${BASE}${ep.url}`, {
				headers: { Accept: 'application/json' },
			})
			expect(
				[401, 403],
				`${ep.name} must not deny an admin (got ${resp.status()})`,
			).not.toContain(resp.status())
		}
	})
})
