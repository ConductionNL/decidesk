/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * No two manifest fragments may declare the same page or menu id.
 *
 * 🔴 A COLLISION DELETES A PAGE, SILENTLY, AND REROUTES WIDGETS TO THE WRONG ONE.
 *
 * `buildManifest` merges fragments BY ID, and a same-id page replaces the earlier
 * one wholesale. Fragments load in filename order, so whichever sorts later wins
 * and the other page simply stops existing. Nothing reports it: the manifest
 * still validates, the nav ceiling is still satisfied, and every route still
 * resolves — to the surviving page.
 *
 * Measured: one-consultation-schema declared `Consultations`, `ConsultationDetail`
 * and a `Consultations` menu entry. citizen-participation.json already had all
 * three for `public-consultation`. `consultations.json` sorts later, so the
 * citizen pages were replaced, and the DecisionDetail widget listing PUBLIC
 * consultations kept routing to `ConsultationDetail` — which now rendered a
 * GOVERNANCE consultation. A row opened the wrong record, with no error anywhere.
 *
 * @spec exclude Structural invariant of the shipped manifests; no behavioural spec.
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/** The base manifest first, then fragments in the order require.context yields. */
function manifestFiles() {
	const files = [path.join(ROOT, 'src', 'manifest.json')]
	const dir = path.join(ROOT, 'src', 'manifest.d')
	if (fs.existsSync(dir)) {
		for (const name of fs.readdirSync(dir).sort()) {
			if (name.endsWith('.json')) files.push(path.join(dir, name))
		}
	}
	return files
}

/** Every id declared under one key, with the file that declared it. */
function declarations(key) {
	const seen = new Map()
	const clashes = []
	for (const file of manifestFiles()) {
		const parsed = JSON.parse(fs.readFileSync(file, 'utf8'))
		for (const entry of parsed[key] ?? []) {
			if (!entry?.id) continue
			if (seen.has(entry.id)) {
				clashes.push(
					`${key} id "${entry.id}": ${path.basename(seen.get(entry.id))} then ${path.basename(file)}`,
				)
			}
			seen.set(entry.id, file)
		}
	}
	return { seen, clashes }
}

describe('manifest ids are unique across the bundled manifest and its fragments', () => {
	it('is not vacuous: the app declares pages to check', () => {
		expect(declarations('pages').seen.size).toBeGreaterThan(0)
	})

	it('no page id is declared twice', () => {
		const { clashes } = declarations('pages')
		expect(
			clashes,
			`A same-id page REPLACES the earlier one wholesale, so one of these pages does not exist at runtime and any widget routing to it opens the wrong record:\n  ${clashes.join('\n  ')}`,
		).toEqual([])
	})

	it('no menu id is declared twice', () => {
		const { clashes } = declarations('menu')
		expect(
			clashes,
			`A same-id menu entry replaces the earlier one, so one navigation entry silently disappears:\n  ${clashes.join('\n  ')}`,
		).toEqual([])
	})

	it('every rowRoute and viewAllRoute names a page that exists', () => {
		// The other half of the same failure: a route naming a page that was
		// replaced still "resolves", to whatever replaced it.
		const { seen } = declarations('pages')
		const missing = []
		for (const file of manifestFiles()) {
			const parsed = JSON.parse(fs.readFileSync(file, 'utf8'))
			for (const page of parsed.pages ?? []) {
				for (const widget of page.config?.widgets ?? []) {
					for (const key of ['rowRoute', 'viewAllRoute']) {
						// Two shapes are valid: a bare page id, or `{ name: id }`
						// with optional query. Reading only the string form would
						// report every object route as missing, which is noise
						// rather than a finding.
						const raw = widget.content?.[key]
						const target =
							typeof raw === 'string' ? raw : (raw?.name ?? null)
						if (target && !seen.has(target)) {
							missing.push(
								`${path.basename(file)} ${page.id}/${widget.id} ${key} -> ${target}`,
							)
						}
					}
				}
			}
		}
		expect(
			missing,
			`Widget routes naming no page:\n  ${missing.join('\n  ')}`,
		).toEqual([])
	})
})
