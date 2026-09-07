/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure dashboard widget logic (src/views/dashboard/widgets/
 * widgetLogic.js). These functions carry every governance-domain calculation
 * the widgets need — set-difference for pending votes, overdue detection, <24h
 * urgency, lifecycle grouping, active-decision count, badge mapping and
 * health-series assembly — with the "now" clock always passed in explicitly,
 * so they are deterministic under the `node` environment.
 *
 * Covers spec scenarios REQ-001 / REQ-002 / REQ-003 / REQ-004 / REQ-008 /
 * REQ-009 / REQ-010 / REQ-011 / REQ-012 / REQ-013.
 */

import { describe, expect, it } from 'vitest'
import {
	activeDecisionCount,
	countdownBucket,
	DAY_MS,
	groupMotionsByLifecycle,
	hasEnoughHealthData,
	healthDataPoints,
	healthSeries,
	isActiveDecision,
	isOverdue,
	isUpcoming,
	isUrgent,
	outcomeBadge,
	overdueActions,
	pendingInRange,
	pendingVotingRounds,
	publicationBadge,
	recentDecisions,
	resolveParticipantId,
	RUNNING_MOTION_LIFECYCLES,
	sortByDueDate,
	toTime,
	upcomingMeetings,
	withinDeadlineRange,
} from '../../../src/views/dashboard/widgets/widgetLogic.js'

// A fixed clock so every relative-date assertion is reproducible.
const NOW = new Date('2026-06-12T12:00:00Z').getTime()
const inHours = (h) => new Date(NOW + h * 60 * 60 * 1000).toISOString()

describe('toTime', () => {
	it('parses ISO strings to epoch ms', () => {
		expect(toTime('2026-06-12T12:00:00Z')).toBe(NOW)
	})

	it('returns NaN for null / undefined / empty', () => {
		expect(Number.isNaN(toTime(null))).toBe(true)
		expect(Number.isNaN(toTime(undefined))).toBe(true)
		expect(Number.isNaN(toTime(''))).toBe(true)
	})
})

describe('resolveParticipantId (REQ-009 / REQ-012 no-participant rule)', () => {
	const participants = [
		{ id: 'p1', nextcloudUserId: 'avries' },
		{ id: 'p2', nextcloudUserId: 'bjansen' },
	]

	it('resolves the participant id for a matching uid', () => {
		expect(resolveParticipantId(participants, 'avries')).toBe('p1')
	})

	it('returns null when no participant matches the uid', () => {
		expect(resolveParticipantId(participants, 'ghost')).toBe(null)
	})

	it('returns null for a missing uid or non-array input', () => {
		expect(resolveParticipantId(participants, undefined)).toBe(null)
		expect(resolveParticipantId(null, 'avries')).toBe(null)
	})
})

describe('pendingVotingRounds (REQ-009 / REQ-012 set-difference)', () => {
	const openRounds = [{ id: 'r1' }, { id: 'r2' }]
	const votes = [{ participant: 'p1', votingRound: 'r2' }]

	it('returns open rounds the participant has not voted in', () => {
		const pending = pendingVotingRounds(openRounds, votes, 'p1')
		expect(pending.map((r) => r.id)).toEqual(['r1'])
	})

	it('returns empty when the participant voted in every open round', () => {
		const all = [
			{ participant: 'p1', votingRound: 'r1' },
			{ participant: 'p1', votingRound: 'r2' },
		]
		expect(pendingVotingRounds(openRounds, all, 'p1')).toEqual([])
	})

	it('ignores votes cast by other participants', () => {
		const others = [
			{ participant: 'p2', votingRound: 'r1' },
			{ participant: 'p2', votingRound: 'r2' },
		]
		expect(
			pendingVotingRounds(openRounds, others, 'p1').map((r) => r.id),
		).toEqual(['r1', 'r2'])
	})

	it('returns empty for a null participant id (no-participant ⇒ 0)', () => {
		expect(pendingVotingRounds(openRounds, votes, null)).toEqual([])
	})

	it('coerces id types when matching votes', () => {
		const numericVotes = [{ participant: 1, votingRound: 2 }]
		const rounds = [{ id: '1' }, { id: '2' }]
		expect(
			pendingVotingRounds(rounds, numericVotes, '1').map((r) => r.id),
		).toEqual(['1'])
	})
})

describe('isUrgent (REQ-011 / REQ-012 <24h threshold)', () => {
	it('is true when under 24h remain', () => {
		expect(isUrgent(inHours(23), NOW)).toBe(true)
	})

	it('is true when the deadline has already passed', () => {
		expect(isUrgent(inHours(-1), NOW)).toBe(true)
	})

	it('is false when 24h or more remain', () => {
		expect(isUrgent(inHours(25), NOW)).toBe(false)
	})

	it('is false for an unparseable deadline', () => {
		expect(isUrgent(null, NOW)).toBe(false)
	})

	it('uses exactly a 24h threshold', () => {
		expect(DAY_MS).toBe(24 * 60 * 60 * 1000)
	})
})

describe('countdownBucket', () => {
	it('buckets a past deadline as overdue', () => {
		expect(countdownBucket(inHours(-2), NOW).key).toBe('overdue')
	})

	it('buckets <24h as today', () => {
		expect(countdownBucket(inHours(5), NOW).key).toBe('today')
	})

	it('buckets 24-48h as tomorrow', () => {
		expect(countdownBucket(inHours(30), NOW).key).toBe('tomorrow')
	})

	it('buckets >=48h as later', () => {
		expect(countdownBucket(inHours(72), NOW).key).toBe('later')
	})

	it('buckets an unparseable deadline as unknown', () => {
		expect(countdownBucket(null, NOW).key).toBe('unknown')
	})
})

describe('upcoming meetings (REQ-008 / REQ-011)', () => {
	const meetings = [
		{ id: 'm-past', scheduledDate: inHours(-10) },
		{ id: 'm-soon', scheduledDate: inHours(5) },
		{ id: 'm-later', scheduledDate: inHours(50) },
	]

	it('isUpcoming is true at or after now', () => {
		expect(isUpcoming({ scheduledDate: inHours(0) }, NOW)).toBe(true)
		expect(isUpcoming({ scheduledDate: inHours(-1) }, NOW)).toBe(false)
	})

	it('filters out past meetings and sorts ascending', () => {
		const rows = upcomingMeetings(meetings, NOW)
		expect(rows.map((m) => m.id)).toEqual(['m-soon', 'm-later'])
	})

	it('returns empty for non-array input', () => {
		expect(upcomingMeetings(null, NOW)).toEqual([])
	})
})

describe('overdue action items (REQ-002 / REQ-010)', () => {
	it('is overdue when past due and not completed/cancelled', () => {
		expect(isOverdue({ dueDate: inHours(-1), taskStatus: 'open' }, NOW)).toBe(
			true,
		)
	})

	it('is not overdue when completed or cancelled', () => {
		expect(
			isOverdue({ dueDate: inHours(-1), taskStatus: 'completed' }, NOW),
		).toBe(false)
		expect(
			isOverdue({ dueDate: inHours(-1), taskStatus: 'cancelled' }, NOW),
		).toBe(false)
	})

	it('is not overdue when due in the future', () => {
		expect(isOverdue({ dueDate: inHours(10), taskStatus: 'open' }, NOW)).toBe(
			false,
		)
	})

	it('counts overdue items in a collection', () => {
		const items = [
			{ dueDate: inHours(-1), taskStatus: 'open' },
			{ dueDate: inHours(-5), taskStatus: 'in-progress' },
			{ dueDate: inHours(-1), taskStatus: 'completed' },
			{ dueDate: inHours(5), taskStatus: 'open' },
		]
		expect(overdueActions(items, NOW)).toHaveLength(2)
	})
})

describe('sortByDueDate (REQ-002)', () => {
	it('sorts ascending and pushes undated items last', () => {
		const items = [
			{ id: 'b', dueDate: inHours(20) },
			{ id: 'none', dueDate: null },
			{ id: 'a', dueDate: inHours(5) },
		]
		expect(sortByDueDate(items).map((i) => i.id)).toEqual(['a', 'b', 'none'])
	})

	it('does not mutate the input array', () => {
		const items = [
			{ id: 'b', dueDate: inHours(20) },
			{ id: 'a', dueDate: inHours(5) },
		]
		const copy = [...items]
		sortByDueDate(items)
		expect(items).toEqual(copy)
	})
})

describe('groupMotionsByLifecycle (REQ-001)', () => {
	it('groups motions under each running lifecycle, always present', () => {
		// ADR-005 Decision.lifecycle vocabulary. These fixtures used to read
		// `submitted` / `under-discussion` — values no stored decision can hold —
		// so this test agreed with a widget that filtered on the same impossible
		// words, and neither half could see that the widget was always empty.
		const motions = [
			{ id: '1', lifecycle: 'proposed' },
			{ id: '2', lifecycle: 'voting' },
			{ id: '3', lifecycle: 'proposed' },
			{ id: '4', lifecycle: 'archived' },
		]
		const groups = groupMotionsByLifecycle(motions)
		expect(Object.keys(groups)).toEqual(RUNNING_MOTION_LIFECYCLES)
		expect(groups.proposed.map((m) => m.id)).toEqual(['1', '3'])
		expect(groups.voting.map((m) => m.id)).toEqual(['2'])
		expect(groups.deliberating).toEqual([])
	})

	it('drops motions whose lifecycle is not a running stage', () => {
		const groups = groupMotionsByLifecycle([{ id: 'x', lifecycle: 'archived' }])
		expect(groups.proposed.concat(groups.voting, groups.deliberating)).toEqual(
			[],
		)
	})

	it('every running stage is a real Decision.lifecycle value', () => {
		// The defect this file missed was a vocabulary that existed nowhere but
		// here. Asserting the stage keys against the schema's own enum is the
		// only check that could have caught it.
		const schemaStates = [
			'draft',
			'proposed',
			'deliberating',
			'voting',
			'decided',
			'enacted',
			'archived',
			'withdrawn',
		]
		for (const stage of RUNNING_MOTION_LIFECYCLES) {
			expect(schemaStates).toContain(stage)
		}
	})
})

describe('active decisions (REQ-013)', () => {
	it('isActiveDecision is true only when outcome is null/absent', () => {
		expect(isActiveDecision({ outcome: null })).toBe(true)
		expect(isActiveDecision({ outcome: undefined })).toBe(true)
		expect(isActiveDecision({ outcome: '' })).toBe(true)
		expect(isActiveDecision({ outcome: 'adopted' })).toBe(false)
	})

	it('counts undecided decisions', () => {
		const decisions = [
			{ outcome: 'adopted' },
			{ outcome: null },
			{ outcome: null },
		]
		expect(activeDecisionCount(decisions)).toBe(2)
	})
})

describe('recentDecisions (REQ-003)', () => {
	const decisions = [
		{ id: 'old', decisionDate: '2026-04-23' },
		{ id: 'new', decisionDate: '2026-06-05' },
		{ id: 'mid', decisionDate: '2026-05-21' },
	]

	it('sorts by decisionDate descending', () => {
		expect(recentDecisions(decisions).map((d) => d.id)).toEqual([
			'new',
			'mid',
			'old',
		])
	})

	it('caps at the given limit', () => {
		expect(recentDecisions(decisions, 2).map((d) => d.id)).toEqual([
			'new',
			'mid',
		])
	})
})

describe('badge mapping (REQ-003)', () => {
	it('maps outcome enums to label + variant', () => {
		expect(outcomeBadge('adopted')).toEqual({
			label: 'Adopted',
			variant: 'success',
		})
		expect(outcomeBadge('rejected')).toEqual({
			label: 'Rejected',
			variant: 'error',
		})
		expect(outcomeBadge(null)).toEqual({
			label: 'Undecided',
			variant: 'default',
		})
	})

	it('maps publication enums to label + variant', () => {
		expect(publicationBadge('internal')).toEqual({
			label: 'Internal',
			variant: 'default',
		})
		expect(publicationBadge('public')).toEqual({
			label: 'Public',
			variant: 'success',
		})
		expect(publicationBadge('confidential')).toEqual({
			label: 'Confidential',
			variant: 'warning',
		})
	})
})

describe('governance health (REQ-004)', () => {
	const meetings = [
		{
			id: 'a',
			scheduledDate: '2026-04-23',
			quorumPercentage: 93,
			actionItemCompletionRate: 85,
		},
		{
			id: 'b',
			scheduledDate: '2026-05-21',
			quorumPercentage: 87,
			actionItemCompletionRate: 72,
		},
		{
			id: 'no-q',
			scheduledDate: '2026-06-10',
			quorumPercentage: null,
			actionItemCompletionRate: 50,
		},
		{
			id: 'no-rate',
			scheduledDate: '2026-06-18',
			quorumPercentage: 80,
			actionItemCompletionRate: null,
		},
	]

	it('keeps only meetings with both materialized metrics, sorted ascending', () => {
		const points = healthDataPoints(meetings)
		expect(points.map((m) => m.id)).toEqual(['a', 'b'])
	})

	it('requires at least 2 points to render', () => {
		expect(hasEnoughHealthData(healthDataPoints(meetings))).toBe(true)
		expect(hasEnoughHealthData(healthDataPoints([meetings[0]]))).toBe(false)
		expect(hasEnoughHealthData([])).toBe(false)
	})

	it('assembles two live series from the data points — never fabricated', () => {
		const points = healthDataPoints(meetings)
		const { series, categories } = healthSeries(points)
		expect(series).toHaveLength(2)
		expect(series[0].data).toEqual([93, 87])
		expect(series[1].data).toEqual([85, 72])
		expect(categories).toEqual(['2026-04-23', '2026-05-21'])
	})

	it('empty in ⇒ empty series out', () => {
		const { series } = healthSeries([])
		expect(series[0].data).toEqual([])
		expect(series[1].data).toEqual([])
	})
})

describe('pendingInRange / withinDeadlineRange (date-range pills)', () => {
	const rounds = [
		{ id: 'r1', votingDeadline: '2026-06-10T12:00:00Z' },
		{ id: 'r2', votingDeadline: '2026-06-20T12:00:00Z' },
		{ id: 'r3' }, // no deadline
	]

	it('no range (or All) → every round matches, undated included', () => {
		expect(pendingInRange(rounds, null)).toHaveLength(3)
		expect(pendingInRange(rounds, { from: '', to: '' })).toHaveLength(3)
	})

	it('bounded window keeps rounds whose deadline is inside it', () => {
		const range = { from: '2026-06-01T00:00:00Z', to: '2026-06-15T23:59:59Z' }
		expect(pendingInRange(rounds, range).map((r) => r.id)).toEqual(['r1'])
	})

	it('bounded window excludes rounds with no parseable deadline', () => {
		const range = { from: '2026-06-01T00:00:00Z', to: '2026-06-30T23:59:59Z' }
		expect(withinDeadlineRange({ id: 'x' }, range)).toBe(false)
		expect(pendingInRange(rounds, range).map((r) => r.id)).toEqual(['r1', 'r2'])
	})

	it('an open-ended bound treats the missing side as unbounded', () => {
		expect(
			pendingInRange(rounds, { from: '2026-06-15T00:00:00Z', to: '' }).map(
				(r) => r.id,
			),
		).toEqual(['r2'])
	})
})
