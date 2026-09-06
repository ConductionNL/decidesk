/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Exhaustive unit tests for src/utils/meetingCost.js — the pure meeting-cost
 * math (meeting-efficiency / cost calculator). Includes the spec worked
 * example (45 min x 12 attendees x EUR 75 = EUR 675).
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	agendaItemCost,
	computeMeetingCost,
	formatEur,
} from '../../src/utils/meetingCost.js'

describe('computeMeetingCost', () => {
	it('matches the spec worked example (45 min, 12, EUR 75 = 675)', () => {
		expect(computeMeetingCost(45 * 60, 12, 75)).toBe(675)
	})

	it('is linear in elapsed time, attendees and rate', () => {
		expect(computeMeetingCost(3600, 1, 100)).toBe(100) // 1h x 1 x 100
		expect(computeMeetingCost(1800, 4, 50)).toBe(100) // 0.5h x 4 x 50
		expect(computeMeetingCost(7200, 3, 60)).toBe(360) // 2h x 3 x 60
	})

	it('returns 0 at zero elapsed / zero attendees / zero rate', () => {
		expect(computeMeetingCost(0, 12, 75)).toBe(0)
		expect(computeMeetingCost(2700, 0, 75)).toBe(0)
		expect(computeMeetingCost(2700, 12, 0)).toBe(0)
	})

	it('clamps negatives and is NaN-safe (never renders NaN)', () => {
		expect(computeMeetingCost(-100, 12, 75)).toBe(0)
		expect(computeMeetingCost(2700, -1, 75)).toBe(0)
		expect(computeMeetingCost(2700, 12, -10)).toBe(0)
		expect(computeMeetingCost(NaN, 12, 75)).toBe(0)
		expect(computeMeetingCost(2700, NaN, 75)).toBe(0)
		expect(computeMeetingCost(2700, 12, undefined)).toBe(0)
	})
})

describe('agendaItemCost', () => {
	it('costs an item from its actual minutes', () => {
		expect(agendaItemCost(30, 10, 60)).toBe(300) // 0.5h x 10 x 60
	})

	it('is NaN/negative-safe', () => {
		expect(agendaItemCost(-5, 10, 60)).toBe(0)
		expect(agendaItemCost(NaN, 10, 60)).toBe(0)
	})
})

describe('formatEur', () => {
	it('formats whole amounts without decimals', () => {
		expect(formatEur(675)).toBe('EUR 675')
		expect(formatEur(0)).toBe('EUR 0')
	})

	it('formats fractional amounts with two decimals', () => {
		expect(formatEur(675.5)).toBe('EUR 675.50')
		expect(formatEur(12.345)).toBe('EUR 12.35') // rounds to 2dp
	})

	it('is NaN-safe', () => {
		expect(formatEur(NaN)).toBe('EUR 0')
		expect(formatEur(undefined)).toBe('EUR 0')
	})
})
