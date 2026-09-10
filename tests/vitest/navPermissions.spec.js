/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The permission list and the route guard that reads it.
 *
 * 🔴 THE NAV FILTER FAILED OPEN, AND AN EMPTY LIST IS WHY.
 *
 * `App.vue` computed its permission list as
 * `window.OC?.currentUser?.permissions ?? []`. `OC.currentUser` is the uid
 * STRING (Nextcloud `core/src/OC/currentuser.js`), so `.permissions` is
 * `undefined` and that expression is always `[]`. The library's filter
 * (`CnAppNav.passesPermission`, @conduction/nextcloud-vue 2.39.0) reads an
 * empty list as "the app did not say":
 *
 *     if (!item.permission) return true
 *     if (!this.permissions || this.permissions.length === 0) return true
 *     return this.permissions.includes(item.permission)
 *
 * So any entry decidiq gated would render for everyone. The invariant these
 * tests defend is therefore NOT "the list is right" but "the list is never
 * empty" — an empty list is the one input that turns the whole gate off.
 *
 * The route half is tested separately because it is the half that matters: a
 * hidden nav entry is not a gate, and a typed URL walked straight past it.
 *
 * @spec exclude Bootstrap wiring for the manifest permission gate; no behavioural spec yet.
 */
import { describe, expect, it } from 'vitest'
import {
	permissionGuard,
	routesFromManifest,
} from '../../src/utils/manifestRoutes.js'
import { currentPermissions } from '../../src/utils/permissions.js'

/** The exact escape in CnAppNav.passesPermission, reproduced so the tests can assert against it. */
function libraryPassesPermission(item, permissions) {
	if (!item.permission) return true
	if (!permissions || permissions.length === 0) return true
	return permissions.includes(item.permission)
}

describe('currentPermissions', () => {
	it('never returns an empty list, which is what disarmed the filter', () => {
		for (const input of [true, false, undefined, null, 'false', 0]) {
			expect(currentPermissions(input).length).toBeGreaterThan(0)
		}
	})

	it('grants admin only for a real boolean true', () => {
		expect(currentPermissions(true)).toContain('admin')
		for (const input of [false, undefined, null, 'true', 1, {}]) {
			expect(currentPermissions(input)).not.toContain('admin')
		}
	})

	it('is not the old window expression, which could only ever answer []', () => {
		// OC.currentUser is the uid string. This is what the old code read.
		const oldList = 'alice'.permissions ?? []
		expect(oldList).toEqual([])
		// And an empty list is what makes the library render a gated entry.
		expect(libraryPassesPermission({ permission: 'admin' }, oldList)).toBe(true)
		// The replacement closes it for a non-admin.
		expect(
			libraryPassesPermission({ permission: 'admin' }, currentPermissions(false)),
		).toBe(false)
	})
})

describe('the nav filter, given the list this app now builds', () => {
	const gated = { id: 'admin-thing', permission: 'admin' }
	const ungated = { id: 'meetings' }

	it('hides a gated entry from a non-admin', () => {
		expect(libraryPassesPermission(gated, currentPermissions(false))).toBe(false)
	})

	it('shows a gated entry to an admin', () => {
		expect(libraryPassesPermission(gated, currentPermissions(true))).toBe(true)
	})

	it('leaves an ungated entry alone for everyone', () => {
		expect(libraryPassesPermission(ungated, currentPermissions(false))).toBe(true)
		expect(libraryPassesPermission(ungated, currentPermissions(true))).toBe(true)
	})
})

describe('routesFromManifest', () => {
	const manifest = {
		pages: [
			{ id: 'Meetings', route: '/meetings' },
			{ id: 'LiveMeeting', route: '/meetings/:id' },
			{ id: 'AdminThing', route: '/admin/thing', permission: 'admin' },
		],
	}

	it('carries pages[].permission onto route meta so the guard can read it', () => {
		const routes = routesFromManifest(manifest, {})
		const byName = Object.fromEntries(
			routes.filter((r) => r.name).map((r) => [r.name, r]),
		)
		expect(byName.AdminThing.meta.permission).toBe('admin')
		expect(byName.Meetings.meta.permission).toBeNull()
	})

	it('keeps the props-on-parameterised-route and catch-all behaviour', () => {
		const routes = routesFromManifest(manifest, {})
		const byName = Object.fromEntries(
			routes.filter((r) => r.name).map((r) => [r.name, r]),
		)
		expect(byName.LiveMeeting.props).toBe(true)
		expect(byName.Meetings.props).toBe(false)
		expect(routes[routes.length - 1]).toEqual({
			path: '/:pathMatch(.*)*',
			redirect: '/',
		})
	})
})

describe('permissionGuard', () => {
	const gatedRoute = { meta: { permission: 'admin' } }
	const openRoute = { meta: { permission: null } }

	it('lets anyone through an ungated route', () => {
		expect(permissionGuard(openRoute, currentPermissions(false))).toBe(true)
		expect(permissionGuard({}, currentPermissions(false))).toBe(true)
	})

	it('lets an admin through a gated route', () => {
		expect(permissionGuard(gatedRoute, currentPermissions(true))).toBe(true)
	})

	it('refuses a non-admin and sends them back into the app', () => {
		expect(permissionGuard(gatedRoute, currentPermissions(false))).toEqual({
			path: '/',
		})
	})

	it('fails CLOSED on an empty or malformed list, unlike CnAppNav', () => {
		// This is the case the nav filter deliberately lets through. On a route
		// it must not: the cost of a wrong answer here is the page itself.
		for (const list of [[], undefined, null, 'admin', {}]) {
			expect(permissionGuard(gatedRoute, list)).toEqual({ path: '/' })
		}
	})
})
