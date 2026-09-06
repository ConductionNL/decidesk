/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the user-settings logic module
 * (src/components/userSettings/userPreferences.js): the channel ↔
 * deliveryMethod enum mapping, delegation + e-mail validation, the
 * date-format preference formatter, and the fetch envelope handling of the
 * per-user REST endpoints. global fetch is mocked; @nextcloud/auth + router
 * are aliased to stubs by vitest.config.js.
 *
 * @spec openspec/specs/user-settings/spec.md
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	channelsToDeliveryMethod,
	DEFAULT_REMINDER_TIMES,
	deliveryMethodToChannels,
	DISPLAY_DEFAULTS,
	fetchDisplayPreference,
	fetchNotificationPreference,
	formatDate,
	isValidEmail,
	REMINDER_TIME_OPTIONS,
	saveDisplayPreference,
	saveNotificationPreference,
	validateDelegation,
} from '../../src/components/userSettings/userPreferences.js'

function mockFetchOnce({ ok = true, status = 200, json = {} }) {
	globalThis.fetch = vi.fn().mockResolvedValueOnce({
		ok,
		status,
		json: async () => json,
	})
}

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.fetch
})

describe('channel ↔ deliveryMethod mapping', () => {
	// @spec openspec/specs/user-settings/spec.md
	it('maps the two toggles onto the storage enum', () => {
		expect(channelsToDeliveryMethod({ inApp: true, email: true })).toBe('both')
		expect(channelsToDeliveryMethod({ inApp: true, email: false })).toBe(
			'in-app',
		)
		expect(channelsToDeliveryMethod({ inApp: false, email: true })).toBe('email')
	})

	it('returns null when both channels are off (no "none" in the enum)', () => {
		expect(channelsToDeliveryMethod({ inApp: false, email: false })).toBeNull()
	})

	it('maps the enum back onto independent toggles (round-trip)', () => {
		for (const method of ['in-app', 'email', 'both']) {
			const channels = deliveryMethodToChannels(method)
			expect(channelsToDeliveryMethod(channels)).toBe(method)
		}
		expect(deliveryMethodToChannels('both')).toEqual({
			inApp: true,
			email: true,
		})
	})
})

describe('defaults', () => {
	it('reminder default is 24h + 1h before, all tokens valid', () => {
		expect(DEFAULT_REMINDER_TIMES).toEqual(['24h', '1h'])
		for (const token of DEFAULT_REMINDER_TIMES) {
			expect(REMINDER_TIME_OPTIONS).toContain(token)
		}
	})

	it('display defaults follow the dashboard + locale conventions', () => {
		expect(DISPLAY_DEFAULTS['default-view']).toBe('dashboard')
		expect(DISPLAY_DEFAULTS['date-format']).toBe('locale')
	})
})

describe('validation', () => {
	// @spec openspec/specs/user-settings/spec.md
	it('accepts plausible email addresses and rejects junk', () => {
		expect(isValidEmail('work@example.com')).toBe(true)
		expect(isValidEmail('not-an-email')).toBe(false)
		expect(isValidEmail('a@b')).toBe(false)
		expect(isValidEmail('')).toBe(false)
	})

	it('delegation requires an expiry date (automatic expiry)', () => {
		expect(
			validateDelegation({
				delegate: 'memberB',
				delegationFrom: '2026-07-01',
				delegationUntil: '',
			}),
		).toBe('expiry-required')
	})

	it('delegation rejects an inverted period', () => {
		expect(
			validateDelegation({
				delegate: 'memberB',
				delegationFrom: '2026-07-14',
				delegationUntil: '2026-07-01',
			}),
		).toBe('inverted-period')
	})

	it('valid delegation and no-delegation pass', () => {
		expect(
			validateDelegation({
				delegate: 'memberB',
				delegationFrom: '2026-07-01',
				delegationUntil: '2026-07-14',
			}),
		).toBeNull()
		expect(
			validateDelegation({
				delegate: '',
				delegationFrom: '',
				delegationUntil: '',
			}),
		).toBeNull()
	})
})

describe('formatDate', () => {
	// @spec openspec/specs/user-settings/spec.md
	it('formats DD-MM-YYYY when configured', () => {
		expect(formatDate('2026-07-04T12:00:00', 'DD-MM-YYYY')).toBe('04-07-2026')
	})

	it('supports the other explicit formats', () => {
		expect(formatDate('2026-07-04T12:00:00', 'YYYY-MM-DD')).toBe('2026-07-04')
		expect(formatDate('2026-07-04T12:00:00', 'MM/DD/YYYY')).toBe('07/04/2026')
	})

	it('defaults to the locale rendering and tolerates junk', () => {
		const date = new Date('2026-07-04T12:00:00')
		expect(formatDate(date)).toBe(date.toLocaleDateString())
		expect(formatDate('', 'DD-MM-YYYY')).toBe('')
		expect(formatDate('garbage', 'DD-MM-YYYY')).toBe('')
	})
})

describe('notification preference fetch/save envelopes', () => {
	// @spec openspec/specs/user-settings/spec.md
	it('GETs the per-user endpoint with the request token', async () => {
		mockFetchOnce({ json: { person: 'admin', deliveryMethod: 'both' } })
		const pref = await fetchNotificationPreference()
		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/index.php/apps/decidiq/api/notification-preference',
			expect.objectContaining({ headers: { requesttoken: 'test-token' } }),
		)
		expect(pref).toMatchObject({ deliveryMethod: 'both' })
	})

	it('PUTs changes as JSON and returns the persisted object', async () => {
		mockFetchOnce({ json: { person: 'admin', meetingReminder: false } })
		const saved = await saveNotificationPreference({ meetingReminder: false })
		const [url, options] = globalThis.fetch.mock.calls[0]
		expect(url).toBe('/index.php/apps/decidiq/api/notification-preference')
		expect(options.method).toBe('PUT')
		expect(JSON.parse(options.body)).toEqual({ meetingReminder: false })
		expect(saved.meetingReminder).toBe(false)
	})

	it('surfaces the 422 message from the error envelope', async () => {
		mockFetchOnce({
			ok: false,
			status: 422,
			json: { message: 'Invalid deliveryMethod.' },
		})
		await expect(
			saveNotificationPreference({ deliveryMethod: 'pigeon' }),
		).rejects.toThrow('Invalid deliveryMethod.')
	})

	it('throws on a failed GET', async () => {
		mockFetchOnce({ ok: false, status: 500, json: {} })
		await expect(fetchNotificationPreference()).rejects.toThrow('HTTP 500')
	})
})

describe('display preference fetch/save envelopes', () => {
	// @spec openspec/specs/user-settings/spec.md
	it('returns the stored value from the {value} envelope', async () => {
		mockFetchOnce({ json: { value: 'meetings' } })
		await expect(fetchDisplayPreference('default-view')).resolves.toBe(
			'meetings',
		)
	})

	it('falls back to the default when unset or failing', async () => {
		mockFetchOnce({ json: { value: null } })
		await expect(fetchDisplayPreference('default-view')).resolves.toBe(
			'dashboard',
		)
		mockFetchOnce({ ok: false, status: 500, json: {} })
		await expect(fetchDisplayPreference('date-format')).resolves.toBe('locale')
	})

	it('PUTs the value envelope to the keyed endpoint', async () => {
		mockFetchOnce({ json: {} })
		await saveDisplayPreference('date-format', 'DD-MM-YYYY')
		const [url, options] = globalThis.fetch.mock.calls[0]
		expect(url).toBe('/index.php/apps/decidiq/api/preferences/date-format')
		expect(options.method).toBe('PUT')
		expect(JSON.parse(options.body)).toEqual({ value: 'DD-MM-YYYY' })
	})
})
