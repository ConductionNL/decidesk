// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the decision lifecycle UI helpers (pure functions).
//
// @spec openspec/specs/decision-management/spec.md

import { describe, expect, it } from 'vitest'
import {
	buildTimeline,
	STATE_COLORS,
	STATES,
} from '../../src/components/tabs/decisionLifecycle.js'

describe('decisionLifecycle helpers', () => {
	it('lists the 7 lifecycle states in machine order', () => {
		expect(STATES).toEqual([
			'draft',
			'proposed',
			'deliberating',
			'voting',
			'decided',
			'enacted',
			'archived',
		])
	})

	it('maps every state to a badge color', () => {
		for (const state of STATES) {
			expect(STATE_COLORS[state]).toBeTruthy()
		}
	})

	it('builds a done/current/upcoming timeline around the current state', () => {
		const timeline = buildTimeline('voting')
		expect(timeline).toHaveLength(7)
		expect(timeline.map((s) => s.status)).toEqual([
			'done',
			'done',
			'done',
			'current',
			'upcoming',
			'upcoming',
			'upcoming',
		])
		expect(timeline[3].state).toBe('voting')
	})

	it('marks everything done except current for the final state', () => {
		const timeline = buildTimeline('archived')
		expect(timeline.at(-1).status).toBe('current')
		expect(timeline.slice(0, -1).every((s) => s.status === 'done')).toBe(true)
	})

	it('marks the first state current on draft', () => {
		const timeline = buildTimeline('draft')
		expect(timeline[0].status).toBe('current')
		expect(timeline.slice(1).every((s) => s.status === 'upcoming')).toBe(true)
	})

	it('renders unknown states as all-upcoming with no current marker', () => {
		const timeline = buildTimeline('warp-drive')
		expect(timeline.every((s) => s.status === 'upcoming')).toBe(true)
	})
})
