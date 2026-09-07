/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Exhaustive unit tests for src/utils/meetingAnalytics.js — the pure
 * meeting-efficiency analytics aggregates (meeting-efficiency / dashboard).
 * Duration stats vs scheduled, completion rate, speaking distribution, cost
 * trend, per-item cost breakdown with most-expensive flag, time-allocation
 * accuracy by item type. Empty-input safety throughout.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	agendaCompletionRate,
	agendaItemCostBreakdown,
	costTrend,
	isItemCompleted,
	meetingDurationStats,
	speakingDistribution,
	timeAllocationAccuracy,
} from '../../src/utils/meetingAnalytics.js'

// Helpers to build ISO dates n minutes apart from a base.
const base = Date.parse('2026-01-01T09:00:00Z')
const iso = (minutes) => new Date(base + minutes * 60000).toISOString()

describe('isItemCompleted', () => {
	it('completed when status afgerond', () => {
		expect(isItemCompleted({ status: 'completed' })).toBe(true)
	})
	it('completed when actualDuration recorded', () => {
		expect(isItemCompleted({ actualDuration: 12 })).toBe(true)
	})
	it('not completed otherwise / null', () => {
		expect(isItemCompleted({ status: 'beeldvorming' })).toBe(false)
		expect(isItemCompleted({ actualDuration: 0 })).toBe(false)
		expect(isItemCompleted(null)).toBe(false)
	})
})

describe('meetingDurationStats', () => {
	it('computes actual vs scheduled, average and overrun (spec duration trend)', () => {
		const meetings = [
			// opened 09:00, closed 10:30 = 90 min actual; scheduled 09:00-10:00 = 60 -> overrun
			{
				id: 'm1',
				title: 'Jan',
				scheduledDate: iso(0),
				endDate: iso(60),
				openedAt: iso(0),
				closedAt: iso(90),
			},
			// opened, closed = 40 min; scheduled 60 -> no overrun
			{
				id: 'm2',
				title: 'Feb',
				scheduledDate: iso(0),
				endDate: iso(60),
				openedAt: iso(0),
				closedAt: iso(40),
			},
		]
		const stats = meetingDurationStats(meetings)
		expect(stats.count).toBe(2)
		expect(stats.points[0].actualMinutes).toBe(90)
		expect(stats.points[0].scheduledMinutes).toBe(60)
		expect(stats.points[0].overrun).toBe(true)
		expect(stats.points[1].overrun).toBe(false)
		expect(stats.averageActualMinutes).toBe(65) // (90+40)/2
		expect(stats.overrunCount).toBe(1)
	})

	it('falls back to plannedDuration for scheduled when no end date', () => {
		const stats = meetingDurationStats([
			{ id: 'm', openedAt: iso(0), closedAt: iso(50), plannedDuration: 30 },
		])
		expect(stats.points[0].scheduledMinutes).toBe(30)
		expect(stats.points[0].overrun).toBe(true)
	})

	it('handles missing dates without producing NaN', () => {
		const stats = meetingDurationStats([{ id: 'm' }])
		expect(stats.points[0].actualMinutes).toBeNull()
		expect(stats.points[0].scheduledMinutes).toBeNull()
		expect(stats.averageActualMinutes).toBe(0)
		expect(stats.overrunCount).toBe(0)
	})

	it('sorts chronologically and is empty-safe', () => {
		const stats = meetingDurationStats([
			{ id: 'late', openedAt: iso(1000), closedAt: iso(1030) },
			{ id: 'early', openedAt: iso(0), closedAt: iso(30) },
		])
		expect(stats.points.map((p) => p.id)).toEqual(['early', 'late'])
		expect(meetingDurationStats([]).count).toBe(0)
		expect(meetingDurationStats(null).averageActualMinutes).toBe(0)
	})
})

describe('agendaCompletionRate', () => {
	it('computes the completed share', () => {
		const r = agendaCompletionRate([
			{ status: 'completed' },
			{ actualDuration: 10 },
			{ status: 'beeldvorming' },
			{ status: 'oordeelsvorming' },
		])
		expect(r).toEqual({ completed: 2, total: 4, rate: 0.5 })
	})
	it('is empty-safe (no division by zero)', () => {
		expect(agendaCompletionRate([])).toEqual({ completed: 0, total: 0, rate: 0 })
		expect(agendaCompletionRate(null).rate).toBe(0)
	})
})

describe('speakingDistribution', () => {
	it('aggregates per participant and sorts by speaking time desc', () => {
		const records = [
			{ participant: 'a', speakingDuration: 120 },
			{ participant: 'b', speakingDuration: 60 },
			{ participant: 'a', speakingDuration: 120 }, // second record for a
		]
		const dist = speakingDistribution(records, { a: 'Alice', b: 'Bob' })
		expect(dist.totalSeconds).toBe(300)
		expect(dist.rows[0]).toMatchObject({
			participantId: 'a',
			displayName: 'Alice',
			seconds: 240,
			share: 0.8,
		})
		expect(dist.rows[1]).toMatchObject({
			participantId: 'b',
			seconds: 60,
			share: 0.2,
		})
	})
	it('falls back to id as name and is empty-safe', () => {
		const dist = speakingDistribution([
			{ participant: 'x', speakingDuration: 10 },
		])
		expect(dist.rows[0].displayName).toBe('x')
		expect(speakingDistribution([]).totalSeconds).toBe(0)
		expect(speakingDistribution(null).rows).toEqual([])
	})
	it('ignores records without a participant id', () => {
		expect(speakingDistribution([{ speakingDuration: 50 }]).rows).toEqual([])
	})
})

describe('costTrend', () => {
	it('builds a chronological cost trend with total and average', () => {
		const t = costTrend([
			{ id: 'm2', meetingCost: 200, closedAt: iso(2000) },
			{ id: 'm1', meetingCost: 100, closedAt: iso(1000) },
			{ id: 'm0', meetingCost: 0 }, // excluded (no cost)
		])
		expect(t.points.map((p) => p.id)).toEqual(['m1', 'm2'])
		expect(t.total).toBe(300)
		expect(t.average).toBe(150)
	})
	it('is empty-safe', () => {
		expect(costTrend([])).toEqual({ points: [], total: 0, average: 0 })
		expect(costTrend(null).average).toBe(0)
	})
})

describe('agendaItemCostBreakdown', () => {
	it('costs each item and flags the most expensive (spec analytics)', () => {
		const rows = agendaItemCostBreakdown(
			[
				{ id: 'i1', title: 'Cheap', actualDuration: 10 },
				{ id: 'i2', title: 'Pricey', actualDuration: 30 },
			],
			10,
			60,
		)
		// sorted desc: pricey first
		expect(rows[0].id).toBe('i2')
		expect(rows[0].cost).toBe(300) // 0.5h x 10 x 60
		expect(rows[0].mostExpensive).toBe(true)
		expect(rows[1].cost).toBeCloseTo(100, 6)
		expect(rows[1].mostExpensive).toBe(false)
	})
	it('flags no item when all costs are zero', () => {
		const rows = agendaItemCostBreakdown(
			[{ id: 'i', actualDuration: 0 }],
			10,
			60,
		)
		expect(rows[0].mostExpensive).toBe(false)
	})
	it('is empty-safe', () => {
		expect(agendaItemCostBreakdown([], 10, 60)).toEqual([])
		expect(agendaItemCostBreakdown(null, 10, 60)).toEqual([])
	})
})

describe('timeAllocationAccuracy', () => {
	it('groups by item type and recommends increasing over-running types (spec example)', () => {
		const items = [
			{ itemType: 'decision', estimatedDuration: 15, actualDuration: 25 },
			{ itemType: 'decision', estimatedDuration: 15, actualDuration: 25 },
			{ itemType: 'informational', estimatedDuration: 10, actualDuration: 5 },
		]
		const out = timeAllocationAccuracy(items)
		const decision = out.find((r) => r.itemType === 'decision')
		expect(decision.avgEstimated).toBe(15)
		expect(decision.avgActual).toBe(25)
		expect(decision.verdict).toBe('over')
		expect(decision.recommendation).toContain(
			'consider increasing default allocation',
		)

		const info = out.find((r) => r.itemType === 'informational')
		expect(info.verdict).toBe('under')
		expect(info.recommendation).toContain('consider reducing default allocation')
	})

	it('marks accurate within +/-15% and emits no recommendation', () => {
		const out = timeAllocationAccuracy([
			{ itemType: 'discussion', estimatedDuration: 20, actualDuration: 21 },
		])
		expect(out[0].verdict).toBe('accurate')
		expect(out[0].recommendation).toBe('')
	})

	it('skips items with neither estimate nor actual, and is empty-safe', () => {
		expect(timeAllocationAccuracy([{ itemType: 'x' }])).toEqual([])
		expect(timeAllocationAccuracy([])).toEqual([])
		expect(timeAllocationAccuracy(null)).toEqual([])
	})
})
