// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	installIntegrationRegistry,
	registerBuiltinIntegrations,
	registerIcons,
	registerLeafIntegrations,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { setActivePinia } from 'pinia'
import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { registerDetailWidgets } from './components/widgets/registerDetailWidgets.js'
import appIcons from './icons.js'
import { registerDecisionsLeaf } from './integrations/registerDecisionsLeaf.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import pinia from './pinia.js'
import registry from './registry.js'
import { initializeStores } from './store/store.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// NL Design System token mapping (ADR-010)
import './assets/nl-design.css'
// Global (unscoped) app styles
import './assets/app.css'

// Vue 3 (ADR-066): global t/n install via app.config.globalProperties after
// createApp (below), not Vue.mixin. pinia + router install via app.use.

// Pluggable integration registry (ADR-019). Install the global registry
// (draining any pre-mount `window.OCA.OpenRegister.integrations` queue),
// then register the built-in core integrations (files / notes / tags /
// tasks / audit-trail) plus the xWiki "Articles" leaf plus the 17 leaf
// integrations (calendar / contacts / email / talk / bookmarks /
// collectives / maps / photos / activity / analytics / cospend / deck /
// flow / forms / polls / time-tracker / shares / openproject). Detail
// pages whose manifest `config.sidebar.useRegistry` is true render one
// sidebar tab per registered integration via the host `<CnObjectSidebar>`
// in App.vue; each integration's tab shows up only when the underlying
// NC app is installed (the registry filters on isEnabled per AD-5).
installIntegrationRegistry()
registerBuiltinIntegrations()
registerLeafIntegrations()

// decidiq's FIRST own integration leaf — "Besluitvorming" (decisions).
// Surfaces decidiq proposals / advice / decisions linked to ANY host
// object (canonically a procest case) as a sidebar tab + detail-page
// widget. Generic: reads the host object identity from the registry
// context, never hard-codes the consumer. Uses the load-order-safe queue
// stub so it registers even if decidiq's bundle loads before OR's.
registerDecisionsLeaf()

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[decidiq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs (including
// this repo's standard dev container) only allow the JS/CSS allowlist
// through Apache and rewrite everything else to index.php — there's no
// route for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback meant boot silently failed when translations couldn't
// load. Strings just fall back to their English source on miss; boot
// MUST not depend on this resolving.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('decidiq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest contract).
 * `LiveMeeting` carries `:id`, so we pass `props: true` for any route whose
 * path declares a `:` parameter — generic, schema-agnostic.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()`
// adds an internal `_Ctor` cache to the component definition; mutating
// a non-extensible export throws "Cannot add property _Ctor, object is
// not extensible". Cloning gives Vue Router an extensible
// component-options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

// Collect the app's manifest.d/*.json fragments — require.context is resolved
// by this app's own webpack build, so it stays app-local — then hand the base
// manifest, fragments, and menu-layout to the shared pipeline.
// `require.context` is a WEBPACK build-time API, not CommonJS `require`: the
// bundler rewrites this call at compile time and no `require` exists at
// runtime. eslint's browser globals therefore report `no-undef` correctly —
// the code is right and the linter is right. Scoped to this one identifier so
// a genuinely undefined name elsewhere in the file still fails.
/* global require */
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

/**
 * Build the vue-router routes array from the merged manifest's pages.
 *
 * @param {object} manifest The merged manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	// vue-router 4 syntax: the bare '*' catch-all became a named param matcher.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

/**
 * The router base for THIS page load.
 *
 * ⚠️ `generateUrl('/apps/decidiq')` alone is not enough. Nextcloud serves the
 * app under BOTH `/apps/decidiq/...` and `/index.php/apps/decidiq/...`, but
 * `generateUrl()` returns only the form the instance is configured for. A
 * visitor arriving on the other form — a bookmark, an emailed deep link, an
 * integration that hardcodes `/index.php` — has a pathname the router cannot
 * strip its base from. No route matches, the catch-all takes over, and they
 * land on the dashboard with no error at all: the deep link is silently
 * swallowed.
 *
 * Measured on a live instance for learniq, across all 282 of its routes:
 * `/apps/learniq/courses` resolved to Courses, `/index.php/apps/learniq/courses`
 * resolved to the dashboard. Every route behaved the same way, so this is not
 * one broken page but every deep link in that URL form.
 *
 * Deriving the base from the pathname makes both forms resolve, because the
 * base then always matches the URL the visitor actually arrived on.
 *
 * @return {string} The base path vue-router should strip from the URL.
 */
function routerBase() {
	const match = window.location.pathname.match(/^(.*\/apps\/decidiq)(?:\/|$)/)
	return match ? match[1] : generateUrl('/apps/decidiq')
}

const router = createRouter({
	history: createWebHistory(routerBase()),
	routes: routesFromManifest(mergedManifest),
})

/**
 * User-settings spec — "Set default landing page": when the user lands on the
 * app root (`/`) and has configured a non-dashboard default view, replace the
 * route with their preference. Deep links are never overridden (only the `/`
 * entry is rewritten), and any failure leaves the dashboard untouched.
 * Fire-and-forget with a short timeout so boot never blocks on it.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
function applyDefaultViewPreference() {
	if (router.currentRoute.value.path !== '/') {
		return
	}
	const routesByPreference = { meetings: '/meetings', decisions: '/decisions' }
	const controller = new AbortController()
	const timer = setTimeout(() => controller.abort(), 3000)
	fetch(generateUrl('/apps/decidiq/api/preferences/default-view'), {
		headers: { Accept: 'application/json' },
		signal: controller.signal,
	})
		.then((response) => (response.ok ? response.json() : null))
		.then((data) => {
			const target = routesByPreference[data?.value]
			if (target && router.currentRoute.value.path === '/') {
				router.replace(target).catch(() => {})
			}
		})
		.catch(() => {})
		.finally(() => clearTimeout(timer))
}

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and our `registry`) as frozen module objects in some
// bundle shapes — Vue 2's `Vue.extend()` mutates component definitions to
// attach an internal `_Ctor` cache, which throws "Cannot add property _Ctor,
// object is not extensible" against a frozen source map. Cloning here yields
// extensible objects without changing the values the lib resolves at render
// time.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }

// Boot order matters: initializeStores() registers every object type
// (meeting, agenda-item, participant, motion, ...) on the lib's shared
// store via registerObjectType(). If we mount Vue before this resolves,
// Vue creates child components synchronously and any `created()` hook
// that calls fetchObject/fetchCollection/subscribe runs before the
// types are registered — the lib throws "Object type X is not
// registered" and the page renders empty data + a fallback header.
// App.vue's own `await initializeStores()` was insufficient because Vue
// doesn't wait for an async `created()` to resolve before mounting
// children. Awaiting here, before $mount, means the store is ready by
// the time the first child component runs.
//
// initializeStores() is documented as idempotent so App.vue's call
// stays in place as a safety net for future entry points.
// Activate the Pinia instance BEFORE initializeStores() runs.
// initializeStores() calls `useObjectStore()` / `useSettingsStore()`
// outside a Vue setup() context — Pinia's `useStore()` reads the
// active pinia from a module-global, and `app.use(pinia)` is what
// normally sets it. But that happens AFTER this async IIFE awaits, so
// any `useStore()` call here would hit `getActivePinia()._s` against
// undefined and throw "Cannot read properties of undefined (reading
// '_s')". Setting it explicitly upfront is the idiomatic fix for
// boot-time store access before the app is created (Vue 3 + Pinia).
setActivePinia(pinia)

;(async () => {
	// Activate Pinia globally before any useStore() call. Without this,
	// initializeStores() (which calls useObjectStore() outside any Vue
	// component) throws "Cannot read properties of undefined (reading
	// '_s')" because Pinia falls back to the active instance and there
	// isn't one yet — `app.use(pinia)` only activates pinia once the app
	// mounts, which happens after this IIFE awaits initializeStores().
	setActivePinia(pinia)

	try {
		await initializeStores()
	} catch (e) {
		// Boot must not depend on this resolving — the app should still
		// mount so the user sees a usable shell. Children that need
		// registered types will surface their own errors and recover
		// when initializeStores() retries via App.vue's lifecycle hook.
		// NB: this pre-existing console.error is intentionally left without
		// an inline eslint-disable — it is already accounted for as a known
		// no-console debt item in eslint-suppressions.json (count: 1), which
		// is outside this change's file-edit scope to update. Adding a
		// disable comment here would silence it AND desync that baseline
		// (npm run lint would then report the suppression as stale/unused).
		console.error('Boot: initializeStores() failed; mounting anyway', e)
	}

	try {
		// Registers the three register-detail catalog widgets (version-timeline
		// / delegation-chain / confidentiality-status-timeline) into the shared
		// dashboardWidgetRegistry (register-detail-optimisation). MUST resolve
		// before mount — CnDetailPage looks widget types up synchronously via
		// getWidgetTypeEntry() at render time, so a page whose manifest
		// declares one of these types needs the registry populated first.
		// Dynamic-imported (rather than a static side-effect import) so this
		// module's pure helper functions stay importable in isolation by
		// Vitest, which has no @vitejs/plugin-vue registered and therefore
		// cannot resolve a static top-level import of @conduction/nextcloud-vue
		// or a .vue file (see tests/vitest/registerDetailWidgets.spec.js).
		await registerDetailWidgets()
	} catch (e) {
		// Non-fatal — the three widgets simply won't render on their detail
		// pages; every other page is unaffected.
		// eslint-disable-next-line no-console
		console.error('Boot: registerDetailWidgets() failed', e)
	}

	// Vue 3 (ADR-066): mount App as the root component directly and pass the
	// bootstrap props as root props (second arg). decidiq's manifest is static
	// (no backend /api/manifest delta), so App can receive it straight — no
	// reactive wrapper render needed.
	const app = createApp(App, {
		manifest: mergedManifest,
		registry: registryProp,
		pageTypes: pageTypesProp,
	})

	// Surface any render/lifecycle error that Vue would otherwise swallow into a
	// blank comment node — boot must never fail silently.
	app.config.errorHandler = (err, instance, info) => {
		// eslint-disable-next-line no-console
		console.error('[decidiq] Vue error (' + info + '):', err)
	}

	// Vue 3 global install contract (ADR-066): t/n move from Vue.mixin to
	// app.config.globalProperties so `this.t(...)` / `this.n(...)` keep working
	// in Options-API components across the app.
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n

	app.use(pinia)
	app.use(router)
	app.mount('#content')

	// Honour the user's default-view display preference (user-settings spec).
	applyDefaultViewPreference()
})()
