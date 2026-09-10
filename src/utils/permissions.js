/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The permission strings the current account holds.
 *
 * 🔴 THIS REPLACES AN EXPRESSION THAT COULD NEVER ANSWER ANYTHING BUT `[]`.
 *
 * `App.vue` used to compute the list as:
 *
 *     window.OC?.currentUser?.permissions ?? []
 *
 * `OC.currentUser` is the uid STRING (Nextcloud `core/src/OC/currentuser.js`
 * sets it from the `data-user` head attribute, or `false` when logged out), so
 * `.permissions` is always `undefined` and the whole expression is always
 * `[]`. That matters because of how the library reads an empty list
 * (`CnAppNav.passesPermission`):
 *
 *     if (!item.permission) return true
 *     if (!this.permissions || this.permissions.length === 0) return true
 *     return this.permissions.includes(item.permission)
 *
 * The middle line reads "empty" as "the app did not say" and renders the entry
 * anyway. So the filter fails OPEN: any nav entry decidiq gates would render
 * for everyone, and would do so silently, because a declaration that does
 * nothing looks exactly like one that works.
 *
 * `currentPermissions()` never returns an empty array. An account holding
 * nothing else still gets `['user']`, which is what keeps that escape from
 * firing.
 */

/** Held by every authenticated account. Its only job is to keep the list non-empty. */
export const BASE_PERMISSION = 'user'

/** Held by an account Nextcloud itself considers an administrator. */
export const ADMIN_PERMISSION = 'admin'

/**
 * Build the permission list for this page load.
 *
 * Strict `=== true` rather than a truthiness test, deliberately: initial state
 * that failed to deploy, or arrived as the string `"false"`, must DENY. The
 * only input that grants admin is a real boolean true from the server.
 *
 * @param {boolean} isAdmin The server's own answer, from initial state.
 * @return {Array<string>} A never-empty list of permission strings.
 */
export function currentPermissions(isAdmin) {
	const permissions = [BASE_PERMISSION]
	if (isAdmin === true) {
		permissions.push(ADMIN_PERMISSION)
	}
	return permissions
}
