/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the decidiq settings Pinia store
 * (src/store/modules/settings.js): fetch envelope handling, the
 * hasOpenRegisters / isAdmin flag derivation, the loading lifecycle, and the
 * save round-trip. global fetch is mocked; @nextcloud/auth + router stubbed.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

function mockFetchOnce({ ok = true, json = {} }) {
	globalThis.fetch = vi.fn().mockResolvedValueOnce({
		ok,
		json: async () => json,
	})
}

describe('decidiq settings store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
	})

	it('has sensible defaults', () => {
		const store = useSettingsStore()
		expect(store.settings).toEqual({})
		expect(store.loading).toBe(false)
		expect(store.hasOpenRegisters).toBe(false)
		expect(store.isAdmin).toBe(false)
		expect(store.getSettings).toEqual({})
		expect(store.getIsAdmin).toBe(false)
	})

	it('fetchSettings stores data and derives openregisters/admin flags', async () => {
		mockFetchOnce({
			json: { openregisters: true, isAdmin: true, meetingSchema: 'meeting' },
		})
		const store = useSettingsStore()
		const data = await store.fetchSettings()
		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/index.php/apps/decidiq/api/settings',
			expect.objectContaining({ headers: { requesttoken: 'test-token' } }),
		)
		expect(data).toMatchObject({ meetingSchema: 'meeting' })
		expect(store.settings).toMatchObject({ meetingSchema: 'meeting' })
		expect(store.hasOpenRegisters).toBe(true)
		expect(store.isAdmin).toBe(true)
		expect(store.loading).toBe(false)
	})

	it('fetchSettings coerces falsy/absent flags to false', async () => {
		mockFetchOnce({ json: { somethingElse: 1 } })
		const store = useSettingsStore()
		await store.fetchSettings()
		expect(store.hasOpenRegisters).toBe(false)
		expect(store.isAdmin).toBe(false)
	})

	it('fetchSettings returns null and clears loading on a non-ok response', async () => {
		mockFetchOnce({ ok: false, json: {} })
		const store = useSettingsStore()
		const result = await store.fetchSettings()
		expect(result).toBeNull()
		expect(store.loading).toBe(false)
	})

	it('fetchSettings swallows a network error and clears loading', async () => {
		globalThis.fetch = vi.fn().mockRejectedValueOnce(new Error('offline'))
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const store = useSettingsStore()
		const result = await store.fetchSettings()
		expect(result).toBeNull()
		expect(store.loading).toBe(false)
	})

	it('saveSettings POSTs the body and stores the returned settings', async () => {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({
			ok: true,
			json: async () => ({ saved: true }),
		})
		const store = useSettingsStore()
		const result = await store.saveSettings({ meetingSchema: 'mtg' })
		const [, opts] = globalThis.fetch.mock.calls[0]
		expect(opts.method).toBe('POST')
		expect(JSON.parse(opts.body)).toEqual({ meetingSchema: 'mtg' })
		expect(result).toEqual({ saved: true })
		expect(store.settings).toEqual({ saved: true })
		expect(store.loading).toBe(false)
	})

	it('saveSettings unwraps the {success, config} envelope from settings#create', async () => {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({
			ok: true,
			json: async () => ({
				success: true,
				config: { register: 'decidiq', organisation_name: 'ACME' },
			}),
		})
		const store = useSettingsStore()
		const result = await store.saveSettings({ organisation_name: 'ACME' })
		expect(result).toEqual({ register: 'decidiq', organisation_name: 'ACME' })
		// The flat settings map must not be replaced by the envelope —
		// useRelationStore reads settings.register after a save.
		expect(store.settings.register).toBe('decidiq')
		expect(store.settings.success).toBeUndefined()
	})
})
