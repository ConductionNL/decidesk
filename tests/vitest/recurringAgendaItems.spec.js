// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for src/services/recurringAgendaItems.js.
//
// The point of this module is a NEGATIVE: the recurring-template read must not
// go through the shared object store, because that store keeps one collection
// slot per type and the read would evict the meeting-scoped agenda every chair
// mounted AgendaBuilder. These tests pin the two things that guarantee it —
// the request goes out over axios against the configured register/schema, and
// the paging parameter is `_limit` (a bare `limit` is a property filter in
// OpenRegister and silently matches nothing).
//
// @spec openspec/specs/agenda-management/spec.md

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchRecurringAgendaItems } from '../../src/services/recurringAgendaItems.js'
import { useSettingsStore } from '../../src/store/modules/settings.js'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => get(...a) } }))

describe('fetchRecurringAgendaItems', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		get.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('reads the configured register + schema with _limit, not limit', async () => {
		get.mockResolvedValueOnce({ data: { results: [{ id: 'a' }] } })

		const items = await fetchRecurringAgendaItems()

		expect(items).toEqual([{ id: 'a' }])
		expect(get).toHaveBeenCalledTimes(1)
		const [url, options] = get.mock.calls[0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/objects/decidiq/agenda-item',
		)
		expect(options.params).toEqual({ isRecurring: true, _limit: 200 })
		// A bare `limit` would be read as a property filter and return nothing.
		expect(options.params.limit).toBeUndefined()
	})

	it('honours a register / schema override from the settings store', async () => {
		const settings = useSettingsStore()
		settings.settings = { register: 'other', agendaItemSchema: 'ai' }
		get.mockResolvedValueOnce({ data: { results: [] } })

		await fetchRecurringAgendaItems()

		expect(get.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/other/ai',
		)
	})

	it('accepts a bare array body and never returns null', async () => {
		get.mockResolvedValueOnce({ data: [{ id: 'b' }] })
		expect(await fetchRecurringAgendaItems()).toEqual([{ id: 'b' }])

		get.mockResolvedValueOnce({ data: {} })
		expect(await fetchRecurringAgendaItems()).toEqual([])
	})
})
