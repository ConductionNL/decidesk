/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for ensureRelationType (src/components/tabs/useRelationStore.js):
 * the logical-type → settings-key → schema-slug resolution + register
 * fallback that the CnObjectSidebar relation tabs use to register child
 * object types on first use. The store-registry module is mocked so we can
 * assert exactly what registerObjectType is called with.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

// Controllable fakes for the two stores ensureRelationType pulls in.
const registerObjectType = vi.fn()
let objectTypeRegistry = {}
let settingsData = {}

vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({
		get objectTypeRegistry() {
			return objectTypeRegistry
		},
		registerObjectType,
	}),
	useSettingsStore: () => ({
		get getSettings() {
			return settingsData
		},
	}),
}))

import { ensureRelationType } from '../../src/components/tabs/useRelationStore.js'

beforeEach(() => {
	registerObjectType.mockReset()
	objectTypeRegistry = {}
	settingsData = {}
})

describe('ensureRelationType', () => {
	it('resolves the schema slug from the matching settings key', () => {
		settingsData = { meetingSchema: 'meeting-123', register: 'decidiq' }
		ensureRelationType('meeting')
		expect(registerObjectType).toHaveBeenCalledWith(
			'meeting',
			'meeting-123',
			'decidiq',
		)
	})

	it('falls back to the literal type slug when the settings key is unset', () => {
		settingsData = { register: 'decidiq' }
		ensureRelationType('amendment')
		expect(registerObjectType).toHaveBeenCalledWith(
			'amendment',
			'amendment',
			'decidiq',
		)
	})

	it('falls back to the "decidiq" register when settings has none', () => {
		settingsData = {}
		ensureRelationType('motion')
		expect(registerObjectType).toHaveBeenCalledWith(
			'motion',
			'motion',
			'decidiq',
		)
	})

	it('honours a custom register from settings', () => {
		settingsData = { register: 'gov', votingRoundSchema: 'vr-9' }
		ensureRelationType('voting-round')
		expect(registerObjectType).toHaveBeenCalledWith(
			'voting-round',
			'vr-9',
			'gov',
		)
	})

	it('is idempotent — does not re-register an already-registered type', () => {
		objectTypeRegistry = { participant: { slug: 'participant' } }
		settingsData = { participantSchema: 'p-1' }
		ensureRelationType('participant')
		expect(registerObjectType).not.toHaveBeenCalled()
	})
})
