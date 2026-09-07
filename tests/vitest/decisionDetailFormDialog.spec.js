/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the decision-type picker on decidiq's DETAIL pages.
 *
 * The defect these pin: #1099 moved the `decisionType` vocabulary out of the
 * stored schema's `enum` into the `decision_types` app config, and #1109
 * spliced it back into the two INDEX pages through CnIndexPage's
 * `form-dialog` slot. CnDetailPage had no such slot, so every detail page
 * that renders the Decision schema kept showing an EMPTY required picker in
 * its built-in Edit dialog. nextcloud-vue#944 added the slot with the same
 * name and the same scope; these tests hold the four decidiq surfaces that
 * needed it to the wiring, and hold the wiring itself to the two things that
 * make it work: the schema copy carries the vocabulary, and the save result
 * gets back to the dialog that submitted it.
 *
 * Component mounting is deliberately absent: this repo's vitest runs on plain
 * Vite with no @vitejs/plugin-vue, so a `.vue` file cannot be imported by a
 * spec (see registerDetailWidgets.spec.js). The logic therefore lives in
 * importable .js modules, and the manifest wiring is asserted against
 * src/manifest.json itself.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'
import { settleFormDialogResult } from '../../src/dialogs/formDialogResult.js'
import {
	FALLBACK_DECISION_TYPES,
	withDecisionTypeVocabulary,
} from '../../src/integrations/decisionLink.js'

// decisionLink.js reaches @nextcloud/axios (and through it @nextcloud/auth,
// which touches `window`) at import time; these tests exercise its pure
// helpers, so the transport is stubbed rather than exercised.
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))

/**
 * Read a repo file relative to this spec.
 *
 * @param {string} relative Path relative to tests/vitest/.
 *
 * @return {string} The file contents.
 */
function read(relative) {
	return readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')
}

const manifest = JSON.parse(read('../../src/manifest.json'))
const registrySource = read('../../src/registry.js')

/** Every page that renders the Decision schema through CnDetailPage. */
const decisionDetailPages = manifest.pages.filter(
	(page) => page.type === 'detail' && page.config?.schema === 'decision',
)

/**
 * The Decision schema exactly as the register ships it: what a detail page's
 * built-in dialog renders, decisionType enum and all (there is none).
 *
 * @return {object} A fresh copy of the stored Decision schema.
 */
function storedDecisionSchema() {
	return JSON.parse(read('../../lib/Settings/decidesk_register.json')).components
		.schemas.Decision
}

describe('the decision detail pages wire the form-dialog slot', () => {
	it('covers every detail page bound to the decision schema', () => {
		// Equality, not a subset: a new decision-backed detail page added
		// without the wiring has exactly this defect, and the test that only
		// checked the known four would stay green while it shipped.
		expect(decisionDetailPages.map((page) => page.id).sort()).toEqual([
			'AmendmentDetail',
			'DecisionDetail',
			'DecisionIntegrations',
			'MotionDetail',
		])
	})

	it.each(decisionDetailPages.map((page) => page.id))(
		'%s replaces its built-in form dialog with DecisionFormDialog',
		(id) => {
			const page = decisionDetailPages.find((entry) => entry.id === id)

			expect(page.slots?.['form-dialog']).toBe('DecisionFormDialog')
		},
	)

	it('the index pages keep the same wiring, so one component serves both', () => {
		const indexPages = manifest.pages.filter(
			(page) => page.type === 'index' && page.config?.schema === 'decision',
		)

		expect(indexPages.map((page) => page.id).sort()).toEqual([
			'Decisions',
			'Motions',
		])
		for (const page of indexPages) {
			expect(page.slots?.['form-dialog']).toBe('DecisionFormDialog')
		}
	})

	it('DecisionFormDialog is registered, so the renderer can resolve the name', () => {
		// CnPageRenderer resolves every `slots` value against the registry and
		// only warns when it cannot: an unregistered name renders the built-in
		// dialog again, which is the defect this change removes.
		expect(registrySource).toContain(
			"import DecisionFormDialog from './dialogs/DecisionFormDialog.vue'",
		)
		expect(registrySource).toContain(
			'DecisionFormDialog: page(DecisionFormDialog)',
		)
	})
})

describe('the detail-page picker offers the registry vocabulary', () => {
	it('renders no options at all without the wiring (negative control)', () => {
		// This is what the built-in dialog renders from: the stored schema,
		// whose decisionType is a free-text string by design since #1099.
		const stored = storedDecisionSchema()

		expect(stored.properties.decisionType).toBeTruthy()
		expect(stored.properties.decisionType.enum).toBeUndefined()
		expect(stored.required).toContain('decisionType')
	})

	it('offers the registry types once the schema passes through the slot', () => {
		const enriched = withDecisionTypeVocabulary(storedDecisionSchema(), [
			'motion',
			'advice',
			'subsidie-besluit',
		])

		expect(enriched.properties.decisionType.enum).toEqual([
			'motion',
			'advice',
			'subsidie-besluit',
		])
		expect(enriched.properties.decisionType.enumLabels.motion).toBeTruthy()
	})

	it('offers the shipped seed while the registry has not answered', () => {
		// The slot component fetches on mount, so the first open can land
		// before the answer does. An empty picker there is the same defect.
		expect(
			withDecisionTypeVocabulary(storedDecisionSchema(), null).properties
				.decisionType.enum,
		).toEqual(FALLBACK_DECISION_TYPES)
	})

	it('never mutates the store schema every other surface reads', () => {
		const stored = storedDecisionSchema()
		const enriched = withDecisionTypeVocabulary(stored, ['motion'])

		expect(stored.properties.decisionType.enum).toBeUndefined()
		expect(enriched).not.toBe(stored)
		expect(enriched.properties.decisionType.title).toBe(
			stored.properties.decisionType.title,
		)
	})
})

describe('settleFormDialogResult (the locked-modal trap)', () => {
	const fakeDialog = () => ({ setResult: vi.fn() })

	it('closes the dialog on a successful save', () => {
		const dialog = fakeDialog()

		expect(
			settleFormDialogResult(dialog, { success: true, data: { id: '1' } }),
		).toBe(true)
		expect(dialog.setResult).toHaveBeenCalledWith({
			success: true,
			data: { id: '1' },
		})
	})

	it('unlocks the dialog on a failed save so the user can retry or close', () => {
		// CnFormDialog raises `loading` on submit and only setResult lowers
		// it, with `no-close` bound to `loading`. On the error path the page
		// leaves the form open, so a dropped result strands the user in a
		// modal that can do neither.
		const dialog = fakeDialog()

		expect(settleFormDialogResult(dialog, { error: 'Save failed' })).toBe(true)
		expect(dialog.setResult).toHaveBeenCalledWith({ error: 'Save failed' })
	})

	it('leaves a confirm that resolves to nothing alone', () => {
		// CnIndexPage's confirm resolves to undefined and closes its dialog by
		// flipping `show`. setResult reads `resultData.success`, so passing it
		// nothing would throw where the index pages work today.
		const dialog = fakeDialog()

		expect(settleFormDialogResult(dialog, undefined)).toBe(false)
		expect(settleFormDialogResult(dialog, null)).toBe(false)
		expect(settleFormDialogResult(dialog, 'saved')).toBe(false)
		expect(dialog.setResult).not.toHaveBeenCalled()
	})

	it('survives the dialog having unmounted while the save was in flight', () => {
		// A successful edit sets `show` false, which unmounts the replacement
		// and empties the ref before the awaited confirm returns.
		expect(() => settleFormDialogResult(null, { success: true })).not.toThrow()
		expect(settleFormDialogResult({}, { success: true })).toBe(false)
	})
})
