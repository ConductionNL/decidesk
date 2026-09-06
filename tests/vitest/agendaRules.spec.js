// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for src/services/agendaRules.js and src/services/noticeRules.js
// (meeting-agenda-gaps-v1): statutory ALV matcher, agenda tree build +
// flatten, the frontend recurrence-expansion mirror, and the statutory
// notice-deadline mirror.
//
// @spec openspec/specs/agenda-management/spec.md
// @spec openspec/specs/meeting-management/spec.md

import { describe, expect, it } from 'vitest'
import {
	buildAgendaTree,
	expandRecurrence,
	flattenTree,
	MAX_SERIES_INSTANCES,
	missingStatutoryItems,
	STATUTORY_ALV_ITEMS,
} from '../../src/services/agendaRules.js'
import {
	DEFAULT_NOTICE_PERIOD_DAYS,
	getNoticeDeadlineInfo,
} from '../../src/services/noticeRules.js'

describe('missingStatutoryItems', () => {
	it('returns nothing for non-ALV meeting types', () => {
		expect(missingStatutoryItems('regular', [])).toEqual([])
		expect(missingStatutoryItems('', [])).toEqual([])
		expect(missingStatutoryItems('committee', [{ title: 'Opening' }])).toEqual(
			[],
		)
	})

	it('reports all eight statutory items for an empty ALV agenda', () => {
		const missing = missingStatutoryItems('general_assembly', [])
		expect(missing).toHaveLength(STATUTORY_ALV_ITEMS.length)
		expect(missing.map((m) => m.id)).toContain('audit-committee-report')
	})

	it('matches case-insensitively on en + nl synonyms', () => {
		const items = [
			{ title: 'OPENING van de vergadering' },
			{ title: 'Vaststelling notulen' },
			{ title: 'Jaarverslag 2025' },
			{ title: 'Jaarrekening 2025' },
			{ title: 'Verslag kascommissie' },
			{ title: 'Benoeming bestuur' },
			{ title: 'Rondvraag' },
			{ title: 'Sluiting' },
		]
		expect(missingStatutoryItems('general_assembly', items)).toEqual([])
	})

	it('reports only the genuinely missing items', () => {
		const items = [
			{ title: 'Opening' },
			{ title: 'Annual report 2025' },
			{ title: 'Closing' },
		]
		const ids = missingStatutoryItems('general_assembly', items).map((m) => m.id)
		expect(ids).toContain('financial-statements')
		expect(ids).toContain('audit-committee-report')
		expect(ids).toContain('board-elections')
		expect(ids).not.toContain('opening')
		expect(ids).not.toContain('annual-report')
		expect(ids).not.toContain('closing')
	})
})

describe('buildAgendaTree / flattenTree', () => {
	const items = [
		{ id: 'a', title: 'Opening', orderNumber: 1 },
		{ id: 'b', title: 'Committee Reports', orderNumber: 2 },
		{ id: 'b1', title: 'Finance Committee', orderNumber: 3, parentItem: 'b' },
		{ id: 'b2', title: 'Audit Committee', orderNumber: 4, parentItem: 'b' },
		{ id: 'c', title: 'Closing', orderNumber: 5 },
	]

	it('nests children under their parent, both levels ordered', () => {
		const tree = buildAgendaTree(items)
		expect(tree.map((n) => n.item.id)).toEqual(['a', 'b', 'c'])
		expect(tree[1].children.map((c) => c.id)).toEqual(['b1', 'b2'])
		expect(tree[0].children).toEqual([])
	})

	it('degrades unknown parents to top-level (no orphan loss)', () => {
		const tree = buildAgendaTree([
			{
				id: 'x',
				title: 'Orphan',
				orderNumber: 1,
				parentItem: 'does-not-exist',
			},
			{ id: 'y', title: 'Top', orderNumber: 2 },
		])
		expect(tree.map((n) => n.item.id)).toEqual(['x', 'y'])
	})

	it('ignores self-referencing parents', () => {
		const tree = buildAgendaTree([
			{ id: 's', title: 'Self', orderNumber: 1, parentItem: 's' },
		])
		expect(tree.map((n) => n.item.id)).toEqual(['s'])
	})

	it('flattens back to parent→children order', () => {
		const flat = flattenTree(buildAgendaTree(items))
		expect(flat.map((i) => i.id)).toEqual(['a', 'b', 'b1', 'b2', 'c'])
	})

	it('keeps children grouped under their parent after a top-level move', () => {
		const tree = buildAgendaTree(items)
		// Move "Closing" above "Committee Reports".
		;[tree[1], tree[2]] = [tree[2], tree[1]]
		const flat = flattenTree(tree)
		expect(flat.map((i) => i.id)).toEqual(['a', 'c', 'b', 'b1', 'b2'])
	})

	it('handles empty and null input', () => {
		expect(buildAgendaTree([])).toEqual([])
		expect(buildAgendaTree(null)).toEqual([])
		expect(flattenTree(null)).toEqual([])
	})
})

describe('expandRecurrence (frontend mirror of MeetingSeriesService::expandPattern)', () => {
	it('expands a monthly pattern Apr→Dec into 9 instances', () => {
		const result = expandRecurrence('2026-04-15T14:00:00+02:00', {
			frequency: 'monthly',
			interval: 1,
			until: '2026-12-31',
		})
		expect(result.error).toBeNull()
		expect(result.dates).toHaveLength(9)
		expect(result.dates[0]).toBe('2026-04-15')
		expect(result.dates[8]).toBe('2026-12-15')
	})

	it('skips exception dates', () => {
		const result = expandRecurrence('2026-06-01', {
			frequency: 'weekly',
			interval: 1,
			until: '2026-06-30',
			exceptions: ['2026-06-15'],
		})
		expect(result.dates).toEqual([
			'2026-06-01',
			'2026-06-08',
			'2026-06-22',
			'2026-06-29',
		])
	})

	it('skips months lacking the template day-of-month', () => {
		const result = expandRecurrence('2026-01-31', {
			frequency: 'monthly',
			interval: 1,
			until: '2026-04-30',
		})
		// February and April lack the 31st; March has it.
		expect(result.dates).toEqual(['2026-01-31', '2026-03-31'])
	})

	it('caps at 52 instances and flags truncation', () => {
		const result = expandRecurrence('2026-01-01', {
			frequency: 'daily',
			interval: 1,
			until: '2026-12-31',
		})
		expect(result.dates).toHaveLength(MAX_SERIES_INSTANCES)
		expect(result.truncated).toBe(true)
	})

	it('honours the interval', () => {
		const result = expandRecurrence('2026-01-01', {
			frequency: 'daily',
			interval: 7,
			until: '2026-01-22',
		})
		expect(result.dates).toEqual([
			'2026-01-01',
			'2026-01-08',
			'2026-01-15',
			'2026-01-22',
		])
	})

	it('reports validation errors instead of throwing', () => {
		expect(
			expandRecurrence('2026-01-01', {
				frequency: 'yearly',
				until: '2027-01-01',
			}).error,
		).toBe('frequency')
		expect(
			expandRecurrence('2026-01-01', {
				frequency: 'daily',
				interval: 0,
				until: '2027-01-01',
			}).error,
		).toBe('interval')
		expect(
			expandRecurrence('2026-01-01', { frequency: 'daily', interval: 1 })
				.error,
		).toBe('until')
		expect(
			expandRecurrence('not-a-date', {
				frequency: 'daily',
				interval: 1,
				until: '2027-01-01',
			}).error,
		).toBe('date')
	})
})

describe('getNoticeDeadlineInfo (frontend mirror of BoardMeetingService)', () => {
	const now = new Date('2026-05-10T09:00:00Z')

	it('computes the deadline from meetingDate minus noticePeriodDays', () => {
		const info = getNoticeDeadlineInfo(
			{ meetingDate: '2026-06-01', noticePeriodDays: 15 },
			now,
		)
		expect(info.deadline).toBe('2026-05-17')
		expect(info.daysUntilDeadline).toBe(7)
		expect(info.level).toBe('ok')
	})

	it('defaults the notice period to 15 days', () => {
		const info = getNoticeDeadlineInfo({ meetingDate: '2026-06-01' }, now)
		expect(info.deadline).toBe('2026-05-17')
		expect(DEFAULT_NOTICE_PERIOD_DAYS).toBe(15)
	})

	it('warns within 3 days of the deadline', () => {
		const info = getNoticeDeadlineInfo(
			{ meetingDate: '2026-05-27', noticePeriodDays: 15 },
			now,
		)
		expect(info.daysUntilDeadline).toBe(2)
		expect(info.level).toBe('warning')
	})

	it('flags an overdue deadline', () => {
		const info = getNoticeDeadlineInfo(
			{ meetingDate: '2026-05-20', noticePeriodDays: 15 },
			now,
		)
		expect(info.daysUntilDeadline).toBeLessThan(0)
		expect(info.level).toBe('overdue')
	})

	it('returns unknown when the meeting date is missing or invalid', () => {
		expect(getNoticeDeadlineInfo({}, now).level).toBe('unknown')
		expect(getNoticeDeadlineInfo({ meetingDate: 'garbage' }, now).level).toBe(
			'unknown',
		)
	})
})
