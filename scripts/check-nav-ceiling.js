#!/usr/bin/env node

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Nav-ceiling gate — enforces ADR-004's six-item top-level navigation.
 *
 * WHAT THIS CHECKS, AND WHY IT LIVES HERE
 * ---------------------------------------
 * ADR-004 (openspec/architecture/adr-004-information-architecture.md) fixed
 * a 6-item top-level navigation in May 2026 (change `ia-six-item-nav`).
 * Nothing enforced it mechanically. Over the following months, 22
 * independent `src/manifest.d/*.json` fragments each added their own
 * top-level menu entry — every one individually reasonable, none
 * coordinated — and the rendered navigation grew back to 44 entries with no
 * single commit that "broke" the ADR. `src/menu-layout.json` is the
 * declared mechanism for re-homing fragment entries (relocations /
 * removals / a lift into the settings section, consumed by
 * `@conduction/nextcloud-vue`'s `buildManifest`/`applyMenuLayout`), but it
 * is just data — nothing stopped fragment #23 from reintroducing the same
 * defect. This gate is the enforcement `ia-six-item-nav` never got.
 *
 * WHAT IT DOES
 * ------------
 * Rebuilds the effective top-level menu the same way `src/main.js`'s
 * `buildManifest` pipeline does at boot — base `src/manifest.json` + every
 * `src/manifest.d/*.json` fragment (sorted, mirroring
 * `require.context('./manifest.d/', false, /\.json$/)`) + `src/menu-
 * layout.json` — then asserts two invariants:
 *
 *   R1 ceiling             the merged menu's PRIMARY top-level entry count
 *                          (entries carrying no `section`, i.e. neither
 *                          "footer" nor "settings") is at most CEILING (6).
 *   R2 fragment-placement  every top-level entry a fragment declares is
 *                          "placed": self-scoped to `section: "footer"`/
 *                          `"settings"`, OR its id is a key in
 *                          `menu-layout.json#relocations`, OR listed in
 *                          `#removals`, OR listed in `#settingsSection`.
 *                          An unplaced entry fails EVEN IF the ceiling
 *                          happens to still be at or under 6 that day —
 *                          the defect is silent accumulation, not just the
 *                          eventual overflow.
 *
 * WHY THE MERGE LOGIC IS VENDORED, NOT IMPORTED
 * -----------------------------------------------
 * `scripts/check-integration-parity.js`'s header comment documents a
 * measured failure mode: a hydra-gates CI context is not guaranteed to
 * have run `npm ci`, so a check resolving `@conduction/nextcloud-vue` out
 * of `node_modules` can silently report a pass having checked nothing when
 * the module is absent. This gate is wired into the `frontend-checks` CI
 * tier, which DOES run its own `npm ci` — but vendoring a small, stable
 * subset of `@conduction/nextcloud-vue/src/utils/buildManifest.js`
 * (`mergeMenuItems`, `applyMenuRelocations`, `applyMenuRemovals`,
 * `applySettingsSection` — menu-only, no page/template merging) costs
 * little and buys the gate the ability to run correctly from a bare
 * checkout, a pre-commit hook, or a future hydra-gates adoption without a
 * behavioural change. If the canonical implementation's menu semantics
 * change, cross-check against that file directly.
 *
 * Exit codes:
 *   0 — the merged menu is at or under the ceiling AND every fragment
 *       top-level entry is placed
 *   1 — at least one violation (each printed as a `✗` line)
 */

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = process.argv[2] ? path.resolve(process.argv[2]) : process.cwd()

/**
 * ADR-004's agreed top-level navigation ceiling. "Adding a 7th top-level
 * requires an ADR amendment that demonstrates the new surface can't
 * reasonably live under an existing item" (ADR-004, "Top-level ceiling") —
 * raising this constant is the mechanical follow-through of that amendment,
 * never a standalone change.
 *
 * @type {number}
 */
const CEILING = 6

/**
 * Merge an array of incoming menu items into a target array, keyed by `id`.
 * Mirrors `mergeMenuItems` in `@conduction/nextcloud-vue/src/utils/
 * buildManifest.js` (menu-only; that function also handles arbitrary
 * fields, reproduced here as-is since the ceiling/placement checks only
 * need `id`, `section`, and `children`).
 *
 * @param {Array<object>} target The accumulated menu (mutated in place).
 * @param {Array<object>} incoming Menu items from the base or a fragment.
 * @return {void}
 */
function mergeMenuItems(target, incoming) {
	for (const item of incoming) {
		const existing = target.find((t) => t.id === item.id)
		if (!existing) {
			target.push({
				...item,
				children: Array.isArray(item.children)
					? [...item.children]
					: item.children,
			})
			continue
		}
		for (const key of [
			'label',
			'icon',
			'route',
			'order',
			'section',
			'featureFlag',
			'permission',
			'visibleIf',
			'href',
			'action',
		]) {
			if (existing[key] === undefined && item[key] !== undefined) {
				existing[key] = item[key]
			}
		}
		if (Array.isArray(item.children) && item.children.length > 0) {
			if (!Array.isArray(existing.children)) {
				existing.children = []
			}
			mergeMenuItems(existing.children, item.children)
		}
	}
}

/**
 * Re-home merged menu entries per `menu-layout.json#relocations`
 * (`{ sourceId: targetGroupId }`). Mirrors `applyMenuRelocations` in
 * `buildManifest.js`.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} relocations Source-id → target-group-id map.
 * @return {Array<object>} The menu with relocations applied.
 */
function applyMenuRelocations(menu, relocations) {
	if (!relocations || typeof relocations !== 'object') return menu
	for (let pass = 0; pass < 5; pass++) {
		const moves = []
		for (let i = menu.length - 1; i >= 0; i--) {
			const node = menu[i]
			const target = relocations[node.id]
			if (target && target !== node.id) {
				menu.splice(i, 1)
				moves.push({ node, target })
				continue
			}
			if (!Array.isArray(node.children)) continue
			for (let j = node.children.length - 1; j >= 0; j--) {
				const child = node.children[j]
				const childTarget = relocations[child.id]
				if (!childTarget) continue
				if (childTarget === node.id && !Array.isArray(child.children))
					continue
				node.children.splice(j, 1)
				moves.push({ node: child, target: childTarget })
			}
		}
		if (moves.length === 0) break
		moves.forEach(({ node, target }) => {
			const group = menu.find((m) => m.id === target)
			if (!group) {
				menu.push(node)
				return
			}
			if (!Array.isArray(group.children)) group.children = []
			if (Array.isArray(node.children)) {
				mergeMenuItems(group.children, node.children)
			} else {
				mergeMenuItems(group.children, [node])
			}
		})
	}
	return menu.filter(
		(m) =>
			m.route
			|| m.href
			|| m.action
			|| (Array.isArray(m.children) && m.children.length > 0),
	)
}

/**
 * Remove individual menu entries by id per `menu-layout.json#removals`.
 * Mirrors `applyMenuRemovals` in `buildManifest.js`.
 *
 * @param {Array<object>} menu The merged menu.
 * @param {Array<string>|undefined} removals Menu-entry ids to drop.
 * @return {Array<object>} The menu without the removed entries.
 */
function applyMenuRemovals(menu, removals) {
	if (!Array.isArray(removals) || removals.length === 0) return menu
	const drop = new Set(removals)
	const wasGroup = (n) => Array.isArray(n.children) && n.children.length > 0
	const isClickable = (n) =>
		n.route !== undefined || n.href !== undefined || n.action !== undefined
	const prune = (nodes) =>
		nodes.reduce((acc, n) => {
			if (drop.has(n.id) && !wasGroup(n)) return acc
			if (Array.isArray(n.children)) {
				const children = prune(n.children)
				const hadChildren = wasGroup(n)
				if (children.length === 0 && hadChildren && !isClickable(n))
					return acc
				acc.push({ ...n, children })
				return acc
			}
			acc.push(n)
			return acc
		}, [])
	return prune(menu)
}

/**
 * Promote entries listed in `menu-layout.json#settingsSection` into the
 * settings foldout (tagged `section: "settings"`, flattened, appended to
 * the top level). Mirrors `applySettingsSection` in `buildManifest.js`.
 *
 * @param {Array<object>} menu The merged + relocated + pruned menu.
 * @param {Array<string>|undefined} settingsIds Entry ids to move to the foldout.
 * @return {Array<object>} The menu with the settings entries lifted out.
 */
function applySettingsSection(menu, settingsIds) {
	if (!Array.isArray(settingsIds) || settingsIds.length === 0) return menu
	const want = new Set(settingsIds)
	const isClickable = (n) =>
		n.route !== undefined || n.href !== undefined || n.action !== undefined
	const lifted = []
	const strip = (nodes) =>
		nodes.reduce((acc, n) => {
			if (want.has(n.id)) {
				// The lifted entry is the node WITHOUT its children.
				const leaf = { ...n }
				delete leaf.children
				lifted.push({ ...leaf, section: 'settings' })
				return acc
			}
			if (Array.isArray(n.children)) {
				const children = strip(n.children)
				if (
					children.length === 0
					&& n.children.length > 0
					&& !isClickable(n)
				)
					return acc
				acc.push({ ...n, children })
				return acc
			}
			acc.push(n)
			return acc
		}, [])
	const remaining = strip(menu)
	return [...remaining, ...lifted]
}

/**
 * Build the effective top-level menu from a base manifest, its fragments,
 * and its menu-layout — the menu-only subset of `buildManifest` +
 * `applyMenuLayout`.
 *
 * @param {{menu?: Array<object>}} base The base manifest.
 * @param {Array<{menu?: Array<object>}>} fragments Fragment objects.
 * @param {{relocations?: object, removals?: string[], settingsSection?: string[]}} menuLayout
 * @return {Array<object>} The effective merged + laid-out top-level menu.
 */
function buildEffectiveMenu(base, fragments = [], menuLayout = {}) {
	const menu = []
	mergeMenuItems(menu, (base && base.menu) || [])
	for (const frag of fragments) {
		if (frag && Array.isArray(frag.menu)) {
			mergeMenuItems(menu, frag.menu)
		}
	}
	let out = applyMenuRelocations(menu, menuLayout.relocations)
	out = applyMenuRemovals(out, menuLayout.removals)
	out = applySettingsSection(out, menuLayout.settingsSection)
	return out
}

/**
 * R1 — assert the merged menu's primary top-level entry count is at or
 * under the ceiling. Footer (`section: "footer"`) and settings
 * (`section: "settings"`) entries are excluded from the primary count —
 * neither renders in the main scrollable nav ADR-004 caps.
 *
 * @param {Array<object>} effectiveMenu The merged + laid-out top-level menu.
 * @param {number} [ceiling] The ceiling to enforce.
 * @return {{failures: string[], primary: object[], footer: object[], settings: object[]}}
 */
function evaluateCeiling(effectiveMenu, ceiling = CEILING) {
	const primary = effectiveMenu.filter(
		(m) => m.section !== 'footer' && m.section !== 'settings',
	)
	const footer = effectiveMenu.filter((m) => m.section === 'footer')
	const settings = effectiveMenu.filter((m) => m.section === 'settings')
	const failures = []
	if (primary.length > ceiling) {
		failures.push(
			`✗ [R1 nav-ceiling] primary top-level nav has ${primary.length} entries `
				+ `(ceiling: ${ceiling}) — ADR-004 requires an ADR amendment before a 7th `
				+ `top-level item. Entries: ${primary.map((m) => m.id).join(', ')}`,
		)
	}
	return { failures, primary, footer, settings }
}

/**
 * R2 — assert every fragment top-level menu entry is placed: self-scoped
 * to `section: "footer"`/`"settings"`, or covered by a `menu-layout.json`
 * relocation, removal, or settingsSection entry.
 *
 * @param {Array<{file?: string, menu?: Array<object>}>} fragments Fragment
 *   objects; `file` (relative path) is optional and used only for
 *   diagnostics.
 * @param {{relocations?: object, removals?: string[], settingsSection?: string[]}} menuLayout
 * @return {{failures: string[], checked: number}}
 */
function evaluateFragmentPlacement(fragments, menuLayout = {}) {
	const relocated = new Set(Object.keys(menuLayout.relocations || {}))
	const removed = new Set(menuLayout.removals || [])
	const settingsIds = new Set(menuLayout.settingsSection || [])
	const failures = []
	let checked = 0
	for (const frag of fragments) {
		const label = frag && frag.file ? frag.file : '(fragment)'
		for (const item of frag && Array.isArray(frag.menu) ? frag.menu : []) {
			checked++
			const selfScoped =
				item.section === 'footer' || item.section === 'settings'
			const placed =
				selfScoped
				|| relocated.has(item.id)
				|| removed.has(item.id)
				|| settingsIds.has(item.id)
			if (!placed) {
				failures.push(
					`✗ [R2 fragment-placement] ${label} declares top-level menu entry `
						+ `"${item.id}" with no placement in menu-layout.json (no relocation, `
						+ `removal, or settingsSection entry) and no self-declared `
						+ `section: "footer"/"settings" — it will surface as a new primary `
						+ `nav item with no deliberate placement decision.`,
				)
			}
		}
	}
	return { failures, checked }
}

/**
 * Load the manifest.d fragment files, sorted to mirror webpack's
 * `require.context('./manifest.d/', false, /\.json$/).keys().sort()`.
 *
 * @param {string} manifestDDir Absolute path to `src/manifest.d`.
 * @return {Array<{file: string, menu: Array<object>}>} Loaded fragments.
 */
function loadFragments(manifestDDir) {
	if (!fs.existsSync(manifestDDir)) return []
	const files = fs
		.readdirSync(manifestDDir)
		.filter((f) => f.endsWith('.json'))
		.sort()
	return files.map((f) => {
		const rel = path.join('src', 'manifest.d', f)
		try {
			const doc = JSON.parse(
				fs.readFileSync(path.join(manifestDDir, f), 'utf8'),
			)
			return { file: rel, menu: Array.isArray(doc.menu) ? doc.menu : [] }
		} catch (e) {
			return { file: rel, menu: [], parseError: e.message }
		}
	})
}

/**
 * Run the gate against the real repo files and report.
 *
 * @return {void}
 */
function main() {
	const manifestPath = path.join(REPO_ROOT, 'src', 'manifest.json')
	const manifestDDir = path.join(REPO_ROOT, 'src', 'manifest.d')
	const menuLayoutPath = path.join(REPO_ROOT, 'src', 'menu-layout.json')

	if (!fs.existsSync(manifestPath)) {
		console.error(
			`✗ nav-ceiling: base manifest not found at ${manifestPath} — nothing was checked. Refusing to report a pass.`,
		)
		process.exit(1)
	}

	let base
	try {
		base = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
	} catch (e) {
		console.error(
			`✗ nav-ceiling: ${manifestPath} is not valid JSON (${e.message})`,
		)
		process.exit(1)
	}

	let menuLayout = {}
	if (fs.existsSync(menuLayoutPath)) {
		try {
			const doc = JSON.parse(fs.readFileSync(menuLayoutPath, 'utf8'))
			menuLayout = {
				relocations:
					doc.relocations && typeof doc.relocations === 'object'
						? doc.relocations
						: {},
				removals: Array.isArray(doc.removals) ? doc.removals : [],
				settingsSection: Array.isArray(doc.settingsSection)
					? doc.settingsSection
					: [],
			}
		} catch (e) {
			console.error(
				`✗ nav-ceiling: ${menuLayoutPath} is not valid JSON (${e.message})`,
			)
			process.exit(1)
		}
	}

	const fragments = loadFragments(manifestDDir)
	const parseErrors = fragments.filter((f) => f.parseError)
	for (const f of parseErrors) {
		console.error(`✗ nav-ceiling: ${f.file} is not valid JSON (${f.parseError})`)
	}

	const effectiveMenu = buildEffectiveMenu(base, fragments, menuLayout)
	const ceilingResult = evaluateCeiling(effectiveMenu, CEILING)
	const placementResult = evaluateFragmentPlacement(fragments, menuLayout)

	const failures = [
		...parseErrors.map((f) => `✗ [parse] ${f.file}`),
		...ceilingResult.failures,
		...placementResult.failures,
	]
	const scope =
		`${fragments.length} fragment(s), ${placementResult.checked} fragment top-level menu entr(y/ies), `
		+ `${ceilingResult.primary.length} primary / ${ceilingResult.footer.length} footer / `
		+ `${ceilingResult.settings.length} settings top-level entries in the merged menu`

	if (failures.length === 0) {
		console.log(
			`✓ nav-ceiling: ${scope} — at or under the ADR-004 ceiling (${CEILING}), every fragment entry placed.`,
		)
		process.exit(0)
	}

	console.error(
		`nav-ceiling gate FAILED — ${failures.length} violation(s) over ${scope}:`,
	)
	for (const f of failures) {
		console.error(f)
	}
	process.exit(1)
}

if (require.main === module) {
	main()
}

module.exports = {
	mergeMenuItems,
	applyMenuRelocations,
	applyMenuRemovals,
	applySettingsSection,
	buildEffectiveMenu,
	evaluateCeiling,
	evaluateFragmentPlacement,
	loadFragments,
	CEILING,
}
