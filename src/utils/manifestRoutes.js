/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Manifest → vue-router routes, and the route half of `permission`.
 *
 * The manifest schema documents `pages[].permission` as "Optional permission
 * identifier required to access this page", and until now nothing in decidiq
 * read it. The library does not read it either: across
 * `@conduction/nextcloud-vue` 2.39.0 the only readers of a singular
 * `item.permission` are `CnAppNav.passesPermission` (nav entries and their
 * children) and `CnAppRoot.passesIntegrationPermission` (the Integrations
 * section of the settings modal). Both gate what RENDERS in a menu. Neither
 * gates a route.
 *
 * That is the half that matters. Hiding a nav entry answers "can a non-admin
 * SEE this?" correctly every time, while a typed URL walks straight past it —
 * the same shape as ConductionNL/dossiq#2307 and ConductionNL/launchpad#587,
 * where the working half is precisely what hid the broken half.
 */

/**
 * Build the vue-router routes array from the merged manifest's pages.
 *
 * Each manifest page becomes one route whose `name` IS `page.id` (the lib's
 * manifest contract). A route whose path declares a `:` parameter gets
 * `props: true` — generic and schema-agnostic.
 *
 * `page.permission` is carried onto `meta` so `permissionGuard` can read
 * it without re-deriving the manifest at navigation time.
 *
 * @param {object} manifest The merged manifest (with `pages[]`).
 * @param {object} component The component every route renders.
 * @return {Array<object>} vue-router 4 routes config.
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-008-a-manifest-permission-gates-the-nav-entry-and-the-route-and-fails-closed
 */
export function routesFromManifest(manifest, component) {
	const routes = (manifest.pages ?? []).map((page) => ({
		name: page.id,
		path: page.route,
		component,
		props: page.route.includes(':'),
		meta: { permission: page.permission ?? null },
	}))

	// ⚠️ vue-router 4 REMOVED the bare `path: '*'` wildcard, and does not warn:
	// the route simply never matches, so an unknown URL renders the shell with
	// an empty content area. The named-param form is the v4 spelling.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

/**
 * The route half of `permission`.
 *
 * 🔴 THIS FAILS CLOSED, AND THAT IS THE OPPOSITE OF `CnAppNav`.
 *
 * `CnAppNav.passesPermission` treats an absent or empty list as "the app did
 * not say" and renders the entry. For a menu that is a defensible default: the
 * cost of getting it wrong is a visible link. For a route it is not, because
 * the cost of getting it wrong is the page itself. So a gated route with no
 * list, an empty list, or a non-array is REFUSED here. The two rules differ on
 * purpose, and `currentPermissions()` never hands either of them an empty list
 * anyway.
 *
 * Read this guard for what it is: it stops a page rendering in the SPA. It is
 * not an authorization boundary, and nothing behind it may depend on it. Every
 * endpoint a gated page calls still has to enforce its own access server-side.
 *
 * @param {object} to The vue-router target route.
 * @param {Array<string>} permissions The permissions this account holds.
 * @return {boolean|object} `true` to allow, or a redirect location to refuse.
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-008-a-manifest-permission-gates-the-nav-entry-and-the-route-and-fails-closed
 */
export function permissionGuard(to, permissions) {
	const required = to?.meta?.permission
	if (!required) {
		return true
	}
	if (Array.isArray(permissions) && permissions.includes(required)) {
		return true
	}
	return { path: '/' }
}
