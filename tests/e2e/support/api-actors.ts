/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Request contexts that act as ONE named Nextcloud identity each, for specs
 * that prove API and permission scenarios rather than drive the SPA.
 *
 * Why not the project's admin `storageState`
 * -------------------------------------------
 * The project context carries the admin session cookie. A superuser bypasses
 * OpenRegister's object RBAC and property RBAC, so a scenario that says "a
 * citizen may", "an anonymous visitor may not" or "staff may" cannot be told
 * apart from "there is no rule" when every call is made as admin. Each context
 * built here starts from an EMPTY storage state and carries exactly one
 * identity: HTTP Basic for a named account, nothing at all for anonymous.
 *
 * Why every context sends `OCS-APIRequest: true`
 * ----------------------------------------------
 * Nextcloud's `Request::passesCSRFCheck()` accepts a request carrying that
 * header without a `requesttoken`. The app's participation endpoints are not
 * `#[NoCSRFRequired]`, and a Basic-auth response sets a session cookie, so the
 * second write in the same context would otherwise need a CSRF token. With the
 * header set, a refusal can only come from the code under test.
 *
 * Why `send: 'always'`
 * --------------------
 * Playwright withholds Basic credentials until it sees a `WWW-Authenticate`
 * challenge. The OpenRegister object API and decidiq's app routes answer
 * anonymously readable requests without one, so without `send: 'always'` the
 * call silently runs as the anonymous principal.
 *
 * Accounts are created per run over OCS with a CSPRNG password and deleted in
 * `afterAll`. Objects are tracked in an `ObjectLedger` by the id the response
 * returned, and deleted children-first by `ObjectLedger.cleanup()`, which a
 * spec calls from `afterAll` so a failed assertion still cleans up.
 */

import type {
	APIRequestContext,
	APIResponse,
	PlaywrightWorkerArgs,
} from '@playwright/test'

import { randomBytes } from 'node:crypto'
import { BASE_URL as BASE } from '../base-url.ts'

/** OpenRegister object API for the decidiq register. */
export const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`

/** decidiq's own app routes. */
export const APP_API = `${BASE}/index.php/apps/decidiq/api`

const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

/** Headers every JSON call sends. See the file header for the OCS one. */
const JSON_HEADERS = {
	Accept: 'application/json',
	'Content-Type': 'application/json',
	'OCS-APIRequest': 'true',
}

type Playwright = PlaywrightWorkerArgs['playwright']

/** One Nextcloud identity and the request context bound to it. */
export interface Actor {
	/** The account id, or `anonymous`. */
	uid: string
	/** The request context. Dispose it through `disposeActors()`. */
	ctx: APIRequestContext
}

/**
 * A run id from a CSPRNG. Passwords are built from it, so Math.random() is not
 * acceptable here.
 *
 * @return A run-unique id.
 */
export function newRunId(): string {
	return `${Date.now()}-${randomBytes(6).toString('hex')}`
}

/**
 * A context that authenticates as `username` with HTTP Basic and nothing else.
 *
 * @param playwright The Playwright fixture that creates request contexts.
 * @param username   The account to act as.
 * @param password   Its password.
 * @return The actor.
 */
export async function actorFor(
	playwright: Playwright,
	username: string,
	password: string,
): Promise<Actor> {
	const ctx = await playwright.request.newContext({
		extraHTTPHeaders: JSON_HEADERS,
		httpCredentials: { password, send: 'always', username },
		storageState: { cookies: [], origins: [] },
	})
	return { ctx, uid: username }
}

/**
 * The suite's administrator (a Nextcloud superuser): staff in every scenario.
 *
 * @param playwright The Playwright fixture.
 * @return The admin actor.
 */
export function adminActor(playwright: Playwright): Promise<Actor> {
	return actorFor(playwright, ADMIN_USER, ADMIN_PASS)
}

/**
 * A context with no identity at all: the anonymous visitor.
 *
 * @param playwright The Playwright fixture.
 * @return The anonymous actor.
 */
export async function anonymousActor(playwright: Playwright): Promise<Actor> {
	const ctx = await playwright.request.newContext({
		extraHTTPHeaders: { Accept: 'application/json' },
		storageState: { cookies: [], origins: [] },
	})
	return { ctx, uid: 'anonymous' }
}

/**
 * Create a Nextcloud account for this run and return an actor for it.
 *
 * The account is in no group unless `groups` names one, so it is an ordinary
 * authenticated user: the "citizen" of every participation scenario.
 *
 * @param playwright The Playwright fixture.
 * @param admin      An admin actor, which creates the account.
 * @param uid        The account id. Make it run-unique.
 * @param groups     Groups to put the account in.
 * @return The new account's actor.
 * @throws When Nextcloud refuses the account.
 */
export async function provisionAccount(
	playwright: Playwright,
	admin: Actor,
	uid: string,
	groups: string[] = [],
): Promise<Actor> {
	// Mixed classes so Nextcloud's password policy accepts it.
	const password = `Dq-${randomBytes(18).toString('hex')}-Pw1!`
	const resp = await admin.ctx.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
		data: { groups, password, userid: uid },
	})
	if (!resp.ok()) {
		throw new Error(`creating account ${uid}: ${await describe(resp)}`)
	}
	return actorFor(playwright, uid, password)
}

/**
 * Delete accounts this run created. Best-effort: a teardown never throws.
 *
 * @param admin An admin actor.
 * @param uids  The account ids.
 * @return Resolves once every delete has been attempted.
 */
export async function removeAccounts(admin: Actor, uids: string[]): Promise<void> {
	for (const uid of uids) {
		await admin.ctx
			.delete(`${BASE}/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`)
			.catch(() => undefined)
	}
}

/**
 * Dispose every context. Best-effort.
 *
 * @param actors The actors to dispose.
 * @return Resolves once all are disposed.
 */
export async function disposeActors(...actors: Array<Actor | undefined>): Promise<void> {
	for (const actor of actors) {
		await actor?.ctx.dispose().catch(() => undefined)
	}
}

/**
 * A short, printable description of a response for assertion messages.
 *
 * @param resp The response.
 * @return `HTTP <status> <first 400 chars of the body>`.
 */
export async function describe(resp: APIResponse): Promise<string> {
	const text = await resp.text().catch(() => '<unreadable body>')
	return `HTTP ${resp.status()} ${text.slice(0, 400)}`
}

/**
 * The UUID of an OpenRegister object in any of the shapes it comes back in.
 *
 * @param obj The decoded object.
 * @return Its UUID, or an empty string.
 */
export function uuidOf(obj: any): string {
	return String(obj?.id ?? obj?.['@self']?.id ?? obj?.uuid ?? '')
}

/**
 * Every object a spec created, by schema, deleted children-first.
 *
 * ⚠️ A schema missing from `order` is deleted LAST, never skipped: unlike
 * governance-fixture's TEARDOWN_ORDER, an unknown schema cannot leak here.
 */
export class ObjectLedger {
	private readonly created = new Map<string, string[]>()

	/**
	 * @param order Schema slugs in teardown order (children first).
	 */
	constructor(private readonly order: string[] = []) {}

	/**
	 * Record an object for teardown.
	 *
	 * @param schema The schema slug.
	 * @param id     The object UUID.
	 * @return Nothing.
	 */
	track(schema: string, id: string): void {
		if (id === '') {
			return
		}
		const ids = this.created.get(schema) ?? []
		ids.push(id)
		this.created.set(schema, ids)
	}

	/**
	 * Delete every tracked object, children first. Best-effort.
	 *
	 * @param admin An admin actor.
	 * @return Resolves once every delete has been attempted.
	 */
	async cleanup(admin: Actor): Promise<void> {
		const schemas = [
			...this.order.filter((s) => this.created.has(s)),
			...[...this.created.keys()].filter((s) => !this.order.includes(s)),
		]
		for (const schema of schemas) {
			await Promise.all(
				(this.created.get(schema) ?? []).map((id) =>
					admin.ctx.delete(`${OR}/${schema}/${id}`).catch(() => undefined),
				),
			)
		}
		this.created.clear()
	}
}

/**
 * Create an OpenRegister object as `actor` and track it. Throws on non-2xx.
 *
 * @param actor  The acting identity.
 * @param ledger The ledger to track the object in.
 * @param schema The schema slug.
 * @param data   The object.
 * @return The created object.
 */
export async function createObject(
	actor: Actor,
	ledger: ObjectLedger,
	schema: string,
	data: Record<string, unknown>,
): Promise<any> {
	const resp = await actor.ctx.post(`${OR}/${schema}`, { data })
	if (resp.status() >= 300) {
		throw new Error(`${actor.uid} creating ${schema}: ${await describe(resp)}`)
	}
	const obj = await resp.json()
	ledger.track(schema, uuidOf(obj))
	return obj
}

/**
 * Read one OpenRegister object as `actor`.
 *
 * @param actor  The acting identity.
 * @param schema The schema slug.
 * @param id     The object UUID.
 * @return The raw response, so a caller can assert a refusal.
 */
export function readObject(actor: Actor, schema: string, id: string): Promise<APIResponse> {
	return actor.ctx.get(`${OR}/${schema}/${id}`)
}

/**
 * List OpenRegister objects as `actor`.
 *
 * @param actor  The acting identity.
 * @param schema The schema slug.
 * @param query  Query parameters (filters, `_search`, `_limit`).
 * @return The response status and its `results`.
 */
export async function listObjects(
	actor: Actor,
	schema: string,
	query: Record<string, string | number> = {},
): Promise<{ status: number, results: any[] }> {
	const resp = await actor.ctx.get(`${OR}/${schema}`, {
		params: { _limit: 200, ...query },
	})
	if (!resp.ok()) {
		return { results: [], status: resp.status() }
	}
	const body = await resp.json()
	return { results: body.results ?? body.items ?? [], status: resp.status() }
}

/**
 * Replace an OpenRegister object as `actor`, the way the SPA saves one (PUT of
 * the whole object; OpenRegister's save is PUT-semantic, so an omitted
 * property is nulled).
 *
 * @param actor  The acting identity.
 * @param schema The schema slug.
 * @param id     The object UUID.
 * @param data   The whole object.
 * @return The raw response.
 */
export function replaceObject(
	actor: Actor,
	schema: string,
	id: string,
	data: Record<string, unknown>,
): Promise<APIResponse> {
	return actor.ctx.put(`${OR}/${schema}/${id}`, { data })
}

/**
 * POST to one of decidiq's own routes as `actor`.
 *
 * @param actor The acting identity.
 * @param path  The path under `/api`, starting with `/`.
 * @param data  The JSON body.
 * @return The raw response.
 */
export function appPost(
	actor: Actor,
	path: string,
	data: Record<string, unknown> = {},
): Promise<APIResponse> {
	return actor.ctx.post(`${APP_API}${path}`, { data })
}

/**
 * An ISO timestamp `days` from now (negative for the past).
 *
 * @param days Offset in days.
 * @return The timestamp.
 */
export function daysFromNow(days: number): string {
	return new Date(Date.now() + days * 86_400_000).toISOString()
}

export { BASE }
