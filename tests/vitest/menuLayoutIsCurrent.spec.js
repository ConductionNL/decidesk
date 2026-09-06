/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every rule in src/menu-layout.json must still name a menu entry that exists.
 *
 * 🔴 THE NAV GATE CANNOT SEE THIS. check-nav-ceiling.js asks whether every entry
 * that EXISTS is placed. It never asks the other direction: whether every rule
 * that places something still has something to place. So a rule left behind by a
 * removed menu entry is inert, invisible, and accumulates.
 *
 * Measured: retiring the oral-question, interpellation, consultation and
 * regulation menu entries across three changes left SEVEN relocations naming
 * entries that no longer existed, and every nav check stayed green throughout.
 *
 * Unlike its sibling in navCeilingGate.spec.js, this one deliberately reads the
 * REAL manifests: the defect is drift between two files, so a fixture cannot
 * show it.
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/** Every menu id the app declares, base manifest plus fragments. */
function declaredMenuIds() {
	const files = [path.join(ROOT, 'src', 'manifest.json')]
	const fragmentDir = path.join(ROOT, 'src', 'manifest.d')
	if (fs.existsSync(fragmentDir)) {
		for (const name of fs.readdirSync(fragmentDir)) {
			if (name.endsWith('.json')) files.push(path.join(fragmentDir, name))
		}
	}

	const ids = new Set()
	for (const file of files) {
		const parsed = JSON.parse(fs.readFileSync(file, 'utf8'))
		for (const entry of parsed.menu ?? []) {
			if (entry?.id) ids.add(entry.id)
		}
	}
	return ids
}

describe('menu-layout.json stays current with the manifests', () => {
	const layout = JSON.parse(
		fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.json'), 'utf8'),
	)
	const ids = declaredMenuIds()

	it('is not vacuous: the app declares menu entries to check against', () => {
		expect(ids.size).toBeGreaterThan(0)
	})

	it('every relocation names an entry that exists', () => {
		const stale = Object.keys(layout.relocations ?? {}).filter(
			(id) => !ids.has(id),
		)
		expect(
			stale,
			`menu-layout.json relocates entries that no longer exist: ${stale.join(', ')}`,
		).toEqual([])
	})

	it('every settings-section entry exists', () => {
		const stale = (layout.settingsSection ?? []).filter((id) => !ids.has(id))
		expect(
			stale,
			`menu-layout.json lifts entries that no longer exist into settings: ${stale.join(', ')}`,
		).toEqual([])
	})

	it('every removal names an entry that exists', () => {
		// A removal for an entry already gone is the same drift, and it hides the
		// fact that the entry was deleted outright rather than suppressed.
		const stale = (layout.removals ?? []).filter((id) => !ids.has(id))
		expect(
			stale,
			`menu-layout.json removes entries that no longer exist: ${stale.join(', ')}`,
		).toEqual([])
	})

	it('a relocation target is itself a real entry', () => {
		// Relocating under a parent that does not exist leaves the child
		// unreachable, which the ceiling check counts as "placed".
		const stale = [...new Set(Object.values(layout.relocations ?? {}))].filter(
			(parent) => !ids.has(parent),
		)
		expect(
			stale,
			`menu-layout.json relocates under parents that do not exist: ${stale.join(', ')}`,
		).toEqual([])
	})
})
