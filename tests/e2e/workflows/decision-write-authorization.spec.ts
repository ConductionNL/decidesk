/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who may write a Decision through OpenRegister's own object API (decidiq#1269).
 *
 * The Decision schema used to declare an `authorization` block naming `read`
 * and nothing else. OpenRegister resolves a schema's block IN PLACE OF the
 * register's (PermissionHandler::resolveAuthorizationRaw() only consults the
 * register when the schema block is empty), and a non-empty block denies every
 * action it omits (hasGroupPermission(): `if (empty($authorization[$action]))
 * return false;`). So the register's grant of `update` to the administrator
 * groups never reached Decision: a `decidiq-administrators` member got 403 on
 * every update, and nobody but a Nextcloud superuser could create one.
 *
 * WHY EVERY ACTOR HERE IS A REAL, NON-SUPERUSER ACCOUNT. A superuser bypasses
 * object RBAC AND property RBAC unconditionally, so a suite driven by the
 * `admin` session cannot tell "the rule holds" from "there is no rule". That is
 * how #1269 went unnoticed: every path an admin walks was healthy. The two
 * accounts below are created for this run, their groups are read back and
 * asserted before anything else, and every assertion message names the
 * account that acted.
 *
 * WHY THE FIXTURE IS OWNED BY SOMEONE ELSE. OpenRegister grants the object
 * owner every action before any rule is read. If the griffie account created
 * the Decision it edits, the edit would succeed whatever the schema said, so
 * the fixture is created by the admin account and its owner is asserted.
 *
 * WHY STEP 2 NEEDS STEP 1. #1271 put a property-level `update` rule on
 * `isPublished` / `publishedAt` naming a group with no members. A property rule
 * is only consulted AFTER the object-level update check passes, so while
 * #1269 stood, a refused `isPublished` write proved nothing: the object-level
 * deny refused it first, and it refused the title edit just the same. Step 1
 * shows the object-level check now admits the griffie account; step 2 then
 * requires the refusal to carry the PROPERTY rule's message, not the
 * object-level one. The two refusals are told apart by their text:
 *
 *   object level  : User '<name>' does not have permission to 'update' objects in schema 'Decision'
 *   property level: You are not authorized to modify the following properties: isPublished
 *
 * WHY PUT WITH THE WHOLE OBJECT. That is what the SPA sends
 * (@conduction/nextcloud-vue useObjectStore::saveObject() PUTs the object it
 * read). PATCH is avoided on purpose: OpenRegister's ObjectsController::patch()
 * has no NotAuthorizedException branch, so every refusal there comes back as a
 * bare 500 "Internal server error" and the two messages above are lost.
 *
 * @e2e openspec/specs/authorization-via-or-rbac/spec.md#an-administrator-group-member-edits-a-decision-they-did-not-create
 * @e2e openspec/specs/authorization-via-or-rbac/spec.md#a-direct-write-to-a-flow-owned-publication-field-is-refused
 * @e2e openspec/specs/authorization-via-or-rbac/spec.md#a-member-outside-the-administrator-groups-cannot-edit-a-decision-they-did-not-create
 * @e2e openspec/specs/authorization-via-or-rbac/spec.md#any-member-can-raise-a-decision
 * @e2e openspec/specs/authorization-via-or-rbac/spec.md#a-non-owner-cannot-rewrite-another-users-object
 * @e2e openspec/specs/authorization-via-or-rbac/spec.md#reads-and-creates-are-unchanged
 */
import type {
	APIRequestContext,
	APIResponse,
	PlaywrightWorkerArgs,
} from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

/** The administrator group the register grants update and delete to. */
const ADMIN_GROUP = 'decidiq-administrators'

/** Both names the register grants update to; the ordinary member is in neither. */
const ADMIN_GROUPS = ['decidiq-administrators', 'decidesk-administrators']

const DECISIONS = `${BASE}/index.php/apps/openregister/api/objects/decidiq/decision`

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e4)}`

/**
 * The two accounts this spec provisions. Run-unique ids, so a leftover from an
 * interrupted run can never be mistaken for this run's account, and a strong
 * password so Nextcloud's password policy does not refuse the create.
 */
const GRIFFIE = {
	uid: `dq1269-griffie-${RUN_ID}`,
	password: `Dq1269-griffie-${RUN_ID}-pw`,
}
const MEMBER = {
	uid: `dq1269-member-${RUN_ID}`,
	password: `Dq1269-member-${RUN_ID}-pw`,
}

/** Decision UUIDs created during the current test; deleted in afterEach. */
let createdDecisions: string[] = []

/**
 * A request context that authenticates as `username` with HTTP Basic.
 *
 * `send: 'always'` is required: Playwright otherwise withholds the credentials
 * until it sees a WWW-Authenticate challenge, OpenRegister's object API answers
 * without one, and the call silently runs as the anonymous principal.
 * The empty storageState keeps the project's admin cookie out of it, so the
 * account named here is the only identity on the request.
 *
 * @param playwright The Playwright fixture that creates request contexts.
 * @param username   The Nextcloud account to act as.
 * @param password   Its password.
 * @return A request context bound to that account.
 */
async function actingAs(
	playwright: PlaywrightWorkerArgs['playwright'],
	username: string,
	password: string,
): Promise<APIRequestContext> {
	return playwright.request.newContext({
		httpCredentials: { password, send: 'always', username },
		storageState: { cookies: [], origins: [] },
	})
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
 * Create a Nextcloud account in exactly the groups given.
 *
 * @param admin  An admin request context.
 * @param uid    The account id.
 * @param pass   The password.
 * @param groups The groups the account must be in.
 * @return Resolves once Nextcloud has confirmed the account.
 */
async function provisionUser(
	admin: APIRequestContext,
	uid: string,
	pass: string,
	groups: string[],
): Promise<void> {
	const created = await admin.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
		data: { groups, password: pass, userid: uid },
		headers: OCS,
	})
	expect(
		created.ok(),
		`creating account ${uid}: ${await summarise(created)}`,
	).toBe(true)
}

/**
 * The groups Nextcloud reports for an account, read by the admin.
 *
 * @param admin An admin request context.
 * @param uid   The account id.
 * @return The group ids.
 */
async function groupsOf(admin: APIRequestContext, uid: string): Promise<string[]> {
	const resp = await admin.get(
		`${BASE}/ocs/v2.php/cloud/users/${uid}/groups?format=json`,
		{
			headers: OCS,
		},
	)
	expect(resp.ok(), `reading the groups of ${uid}: ${await summarise(resp)}`).toBe(
		true,
	)
	const body = await resp.json()
	return body?.ocs?.data?.groups ?? []
}

/**
 * GET a Decision as the given account.
 *
 * @param ctx The acting request context.
 * @param id  The Decision UUID.
 * @return The response.
 */
function readDecision(ctx: APIRequestContext, id: string): Promise<APIResponse> {
	return ctx.get(`${DECISIONS}/${id}`, { headers: { Accept: 'application/json' } })
}

/**
 * PUT a whole Decision back as the given account, the way the SPA saves one.
 *
 * @param ctx  The acting request context.
 * @param id   The Decision UUID.
 * @param body The full object to write.
 * @return The response.
 */
function writeDecision(
	ctx: APIRequestContext,
	id: string,
	body: Record<string, unknown>,
): Promise<APIResponse> {
	return ctx.put(`${DECISIONS}/${id}`, {
		data: body,
		headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
	})
}

/**
 * The UUID of an OpenRegister object response.
 *
 * @param obj The decoded object.
 * @return Its UUID, or an empty string.
 */
function uuidOf(obj: any): string {
	return String(obj?.id ?? obj?.['@self']?.id ?? obj?.uuid ?? '')
}

test.describe('decidiq#1269: who may write a Decision through the object API', () => {
	test.beforeAll(async ({ playwright }) => {
		const admin = await actingAs(playwright, ADMIN_USER, ADMIN_PASS)
		try {
			// The group normally exists already (OpenRegister provisions every
			// group an authorization block names), but this spec must not depend
			// on that. 102 "group exists" is a success for this purpose.
			const group = await admin.post(
				`${BASE}/ocs/v2.php/cloud/groups?format=json`,
				{
					data: { groupid: ADMIN_GROUP },
					headers: OCS,
				},
			)
			expect(
				[200, 400],
				`ensuring group ${ADMIN_GROUP}: ${await summarise(group)}`,
			).toContain(group.status())

			await provisionUser(admin, GRIFFIE.uid, GRIFFIE.password, [ADMIN_GROUP])
			await provisionUser(admin, MEMBER.uid, MEMBER.password, [])
		} finally {
			await admin.dispose()
		}
	})

	test.afterEach(async ({ playwright }) => {
		// Here and not in the test body, so a failed assertion still cleans up.
		const admin = await actingAs(playwright, ADMIN_USER, ADMIN_PASS)
		try {
			for (const id of createdDecisions) {
				await admin.delete(`${DECISIONS}/${id}`, {
					headers: { Accept: 'application/json' },
				})
			}
		} finally {
			createdDecisions = []
			await admin.dispose()
		}
	})

	test.afterAll(async ({ playwright }) => {
		const admin = await actingAs(playwright, ADMIN_USER, ADMIN_PASS)
		try {
			for (const uid of [GRIFFIE.uid, MEMBER.uid]) {
				await admin.delete(
					`${BASE}/ocs/v2.php/cloud/users/${uid}?format=json`,
					{
						headers: OCS,
					},
				)
			}
		} finally {
			await admin.dispose()
		}
	})

	test('an administrator group member edits a Decision but not its publication state; an ordinary member edits only its own', async ({
		playwright,
	}) => {
		const admin = await actingAs(playwright, ADMIN_USER, ADMIN_PASS)
		const griffie = await actingAs(playwright, GRIFFIE.uid, GRIFFIE.password)
		const member = await actingAs(playwright, MEMBER.uid, MEMBER.password)

		try {
			// ── Preconditions: the actors are who this test says they are ──────
			// If either account were a superuser, every refusal below would
			// invert into a success and every success would prove nothing.
			const griffieGroups = await groupsOf(admin, GRIFFIE.uid)
			expect(
				griffieGroups,
				`${GRIFFIE.uid} must be in ${ADMIN_GROUP} (groups: ${griffieGroups.join(', ')})`,
			).toContain(ADMIN_GROUP)
			expect(
				griffieGroups,
				`${GRIFFIE.uid} must NOT be a Nextcloud superuser (groups: ${griffieGroups.join(', ')})`,
			).not.toContain('admin')

			const memberGroups = await groupsOf(admin, MEMBER.uid)
			for (const forbidden of ['admin', ...ADMIN_GROUPS]) {
				expect(
					memberGroups,
					`${MEMBER.uid} must not be in ${forbidden} (groups: ${memberGroups.join(', ') || 'none'})`,
				).not.toContain(forbidden)
			}

			// ── Fixture: a draft Decision owned by the admin account ───────────
			// isPublished is written explicitly so the stored object carries it;
			// the superuser is exempt from the property rule, which is fine here
			// because the admin is not an actor under test.
			const title = `e2e-1269-${RUN_ID}`
			const seeded = await admin.post(DECISIONS, {
				data: {
					decisionType: 'meeting-outcome',
					isPublished: 'internal',
					lifecycle: 'draft',
					text: 'Fixture for decidiq#1269.',
					title,
				},
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
			})
			expect(
				seeded.ok(),
				`${ADMIN_USER} seeding the fixture Decision: ${await summarise(seeded)}`,
			).toBe(true)
			const fixture = await seeded.json()
			const id = uuidOf(fixture)
			expect(id, 'the fixture Decision must have a UUID').not.toBe('')
			createdDecisions.push(id)

			// Guard the guard: the owner bypass would make step 1 pass whatever
			// the schema declared.
			expect(
				fixture?.['@self']?.owner,
				`the fixture must not be owned by ${GRIFFIE.uid}, or the owner bypass decides step 1`,
			).not.toBe(GRIFFIE.uid)

			// ── Step 1: the griffie account edits an ordinary field ─────────────
			// This is the #1269 fix. Before it, this PUT answered 403 "does not
			// have permission to 'update' objects in schema 'Decision'".
			const readByGriffie = await readDecision(griffie, id)
			expect(
				readByGriffie.ok(),
				`${GRIFFIE.uid} (${ADMIN_GROUP}) reading the Decision: ${await summarise(readByGriffie)}`,
			).toBe(true)
			const asRead = await readByGriffie.json()

			const edited = await writeDecision(griffie, id, {
				...asRead,
				title: `${title}-edited`,
			})
			expect(
				edited.status(),
				`${GRIFFIE.uid} (${ADMIN_GROUP}, not the owner) updating the title must succeed: ${await summarise(edited)}`,
			).toBe(200)

			// Verified in the same request context that made the write.
			const afterEdit = await (await readDecision(griffie, id)).json()
			expect(
				afterEdit.title,
				`${GRIFFIE.uid} must read back the title it wrote`,
			).toBe(`${title}-edited`)

			// ── Step 2: the same account changes isPublished directly ───────────
			// Refused, and refused by the PROPERTY rule (#1271). Step 1 shows the
			// object-level check admits this account, so the message is what
			// proves which rule refused it.
			const published = await writeDecision(griffie, id, {
				...afterEdit,
				isPublished: 'public',
			})
			const publishedBody = await summarise(published)
			expect(
				published.status(),
				`${GRIFFIE.uid} (${ADMIN_GROUP}) writing isPublished directly must be refused: ${publishedBody}`,
			).toBe(403)
			expect(
				publishedBody,
				`${GRIFFIE.uid}: the refusal must come from the isPublished property rule, not the object-level update check`,
			).toContain(
				'You are not authorized to modify the following properties: isPublished',
			)

			const afterPublish = await (await readDecision(griffie, id)).json()
			expect(
				afterPublish.isPublished,
				`${GRIFFIE.uid}'s refused write must leave isPublished unchanged`,
			).toBe('internal')

			// ── Step 3: an ordinary member edits the same Decision ─────────────
			// Refused at the OBJECT level: not the owner, not in either
			// administrator group, not a superuser.
			const readByMember = await readDecision(member, id)
			expect(
				readByMember.ok(),
				`${MEMBER.uid} (no groups) reading the Decision: ${await summarise(readByMember)}`,
			).toBe(true)
			const memberEdit = await writeDecision(member, id, {
				...(await readByMember.json()),
				title: `${title}-by-a-member`,
			})
			const memberBody = await summarise(memberEdit)
			expect(
				memberEdit.status(),
				`${MEMBER.uid} (no groups, not the owner) updating the Decision must be refused: ${memberBody}`,
			).toBe(403)
			expect(
				memberBody,
				`${MEMBER.uid}: the refusal must be the object-level update check`,
			).toContain("permission to 'update' objects in schema 'Decision'")

			const memberDelete = await member.delete(`${DECISIONS}/${id}`, {
				headers: { Accept: 'application/json' },
			})
			const memberDeleteBody = await summarise(memberDelete)
			expect(
				memberDelete.status(),
				`${MEMBER.uid} (no groups, not the owner) deleting the Decision must be refused: ${memberDeleteBody}`,
			).toBe(403)
			expect(
				memberDeleteBody,
				`${MEMBER.uid}: the delete refusal must be the object-level delete check`,
			).toContain("permission to 'delete' objects in schema 'Decision'")

			const afterMember = await readDecision(griffie, id)
			expect(
				afterMember.ok(),
				`${MEMBER.uid}'s refused delete must leave the Decision in place`,
			).toBe(true)
			expect(
				(await afterMember.json()).title,
				`${MEMBER.uid}'s refused write must leave the title unchanged`,
			).toBe(`${title}-edited`)

			// Reads and lists stay open to every authenticated account.
			const listed = await member.get(`${DECISIONS}?_limit=5`, {
				headers: { Accept: 'application/json' },
			})
			expect(
				listed.ok(),
				`${MEMBER.uid} (no groups) listing Decisions: ${await summarise(listed)}`,
			).toBe(true)

			// ── Step 4: the ordinary member raises a Decision of their own ──────
			// `create` is granted to every authenticated account, as the register
			// intends. Before #1269 this answered 403 for everyone but a superuser.
			const raised = await member.post(DECISIONS, {
				data: {
					decisionType: 'meeting-outcome',
					text: 'Raised by an ordinary member for decidiq#1269.',
					title: `${title}-raised-by-a-member`,
				},
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
			})
			const raisedBody = await summarise(raised)
			const own = raised.ok() ? await raised.json() : {}
			if (raised.ok()) {
				createdDecisions.push(uuidOf(own))
			}
			expect(
				raised.status(),
				`${MEMBER.uid} (no groups) creating a Decision must succeed: ${raisedBody}`,
			).toBe(201)

			// ── Step 5: and edits it, as its owner ────────────────────────────
			// The same account refused in step 3 may rewrite a Decision it owns:
			// OpenRegister admits the owner before any rule is read. This is the
			// other half of "a non-owner cannot rewrite another user's object".
			const ownId = uuidOf(own)
			const ownEdit = await writeDecision(member, ownId, {
				...(await (await readDecision(member, ownId)).json()),
				title: `${title}-raised-and-edited`,
			})
			expect(
				ownEdit.status(),
				`${MEMBER.uid} (no groups) editing the Decision it owns must succeed: ${await summarise(ownEdit)}`,
			).toBe(200)
			expect(
				(await (await readDecision(member, ownId)).json()).title,
				`${MEMBER.uid} must read back the title it wrote on its own Decision`,
			).toBe(`${title}-raised-and-edited`)
		} finally {
			await Promise.all([admin.dispose(), griffie.dispose(), member.dispose()])
		}
	})
})
