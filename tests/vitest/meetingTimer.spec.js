/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Exhaustive unit tests for src/utils/meetingTimer.js — the pure agenda-item
 * timer state machine (meeting-efficiency / agenda item timer). Tick math,
 * pause/resume accounting, extensions, over-time, finish snapshot, clock
 * formatting. `now` is injected so no fake timers are needed.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	createTimer,
	elapsedSeconds,
	extendTimer,
	finishTimer,
	formatClock,
	isOverTime,
	pausedSeconds,
	pauseTimer,
	remainingSeconds,
	resumeTimer,
	startTimer,
} from '../../src/utils/meetingTimer.js'

const T0 = 1_000_000_000_000 // arbitrary fixed epoch
const sec = (n) => T0 + n * 1000

describe('createTimer', () => {
	it('normalises a positive allocation to floored seconds', () => {
		const t = createTimer(900.7)
		expect(t.allocatedSeconds).toBe(900)
		expect(t.startedAt).toBeNull()
		expect(t.pausedAt).toBeNull()
		expect(t.pausedTotalMs).toBe(0)
		expect(t.extensionsSeconds).toBe(0)
		expect(t.finished).toBe(false)
	})

	it('treats null / 0 / negative as no allocation (informational item)', () => {
		expect(createTimer(null).allocatedSeconds).toBeNull()
		expect(createTimer(0).allocatedSeconds).toBeNull()
		expect(createTimer(-5).allocatedSeconds).toBeNull()
		expect(createTimer().allocatedSeconds).toBeNull()
	})
})

describe('startTimer', () => {
	it('records the start timestamp', () => {
		const t = startTimer(createTimer(900), sec(0))
		expect(t.startedAt).toBe(sec(0))
	})

	it('is a no-op when already started or finished', () => {
		const started = startTimer(createTimer(900), sec(0))
		expect(startTimer(started, sec(5))).toBe(started)
		const finished = finishTimer(started, sec(10))
		expect(startTimer(finished, sec(20))).toBe(finished)
	})
})

describe('elapsedSeconds excluding pauses', () => {
	it('counts active time only', () => {
		const t = startTimer(createTimer(900), sec(0))
		expect(elapsedSeconds(t, sec(0))).toBe(0)
		expect(elapsedSeconds(t, sec(75))).toBe(75)
	})

	it('returns 0 before start', () => {
		expect(elapsedSeconds(createTimer(900), sec(50))).toBe(0)
	})

	it('freezes while paused and resumes after', () => {
		let t = startTimer(createTimer(900), sec(0))
		t = pauseTimer(t, sec(60)) // 60s active, then paused
		expect(elapsedSeconds(t, sec(60))).toBe(60)
		expect(elapsedSeconds(t, sec(120))).toBe(60) // frozen during pause
		t = resumeTimer(t, sec(120)) // 60s paused
		expect(elapsedSeconds(t, sec(180))).toBe(120) // 60 + 60 active again
	})

	it('accounts multiple pause cycles', () => {
		let t = startTimer(createTimer(900), sec(0))
		t = pauseTimer(t, sec(10))
		t = resumeTimer(t, sec(15)) // 5s paused
		t = pauseTimer(t, sec(25))
		t = resumeTimer(t, sec(40)) // 15s paused -> 20s total paused
		expect(elapsedSeconds(t, sec(50))).toBe(30) // 50 gross - 20 paused
	})
})

describe('pause / resume', () => {
	it('does not pause an unstarted, already-paused, or finished timer', () => {
		const fresh = createTimer(900)
		expect(pauseTimer(fresh, sec(5))).toBe(fresh)
		let t = startTimer(fresh, sec(0))
		t = pauseTimer(t, sec(10))
		expect(pauseTimer(t, sec(20))).toBe(t) // already paused
		const finished = finishTimer(startTimer(fresh, sec(0)), sec(30))
		expect(pauseTimer(finished, sec(40))).toBe(finished)
	})

	it('resume is a no-op when not paused', () => {
		const t = startTimer(createTimer(900), sec(0))
		expect(resumeTimer(t, sec(10))).toBe(t)
	})

	it('tracks total paused seconds separately', () => {
		let t = startTimer(createTimer(900), sec(0))
		t = pauseTimer(t, sec(30))
		expect(pausedSeconds(t, sec(90))).toBe(60) // open pause counts
		t = resumeTimer(t, sec(90))
		expect(pausedSeconds(t, sec(200))).toBe(60) // completed pause, no growth
	})
})

describe('extendTimer', () => {
	it('adds extension seconds (chair +5 / +10 min)', () => {
		let t = extendTimer(createTimer(900), 300)
		t = extendTimer(t, 600)
		expect(t.extensionsSeconds).toBe(900)
	})

	it('is a no-op for unallocated items, finished timers and bad input', () => {
		const noAlloc = createTimer(null)
		expect(extendTimer(noAlloc, 300)).toBe(noAlloc)
		const t = createTimer(900)
		expect(extendTimer(t, 0)).toBe(t)
		expect(extendTimer(t, -10)).toBe(t)
		expect(extendTimer(t, NaN)).toBe(t)
		const finished = finishTimer(startTimer(t, sec(0)), sec(10))
		expect(extendTimer(finished, 300)).toBe(finished)
	})
})

describe('remaining / over-time', () => {
	it('counts down against the allocation', () => {
		const t = startTimer(createTimer(900), sec(0))
		expect(remainingSeconds(t, sec(0))).toBe(900)
		expect(remainingSeconds(t, sec(600))).toBe(300)
	})

	it('goes negative and flags over-time once exceeded (spec 15-min scenario)', () => {
		const t = startTimer(createTimer(900), sec(0)) // 15 minutes
		expect(isOverTime(t, sec(899))).toBe(false)
		expect(isOverTime(t, sec(900))).toBe(false) // exactly at limit, 0 remaining
		expect(isOverTime(t, sec(960))).toBe(true)
		expect(remainingSeconds(t, sec(960))).toBe(-60)
	})

	it('extensions push the over-time threshold out (Extend 5 min)', () => {
		let t = startTimer(createTimer(900), sec(0))
		t = extendTimer(t, 300) // +5 min -> 1200s budget
		expect(isOverTime(t, sec(960))).toBe(false)
		expect(remainingSeconds(t, sec(960))).toBe(240)
	})

	it('never over-time and remaining null for informational items', () => {
		const t = startTimer(createTimer(null), sec(0))
		expect(remainingSeconds(t, sec(9999))).toBeNull()
		expect(isOverTime(t, sec(9999))).toBe(false)
	})
})

describe('finishTimer snapshot', () => {
	it('freezes elapsed + paused at close', () => {
		let t = startTimer(createTimer(900), sec(0))
		t = pauseTimer(t, sec(60))
		t = resumeTimer(t, sec(90)) // 30s paused
		t = finishTimer(t, sec(150)) // elapsed = 150 - 30 = 120
		expect(t.finished).toBe(true)
		expect(elapsedSeconds(t, sec(999))).toBe(120) // stable after finish
		expect(pausedSeconds(t, sec(999))).toBe(30)
	})

	it('folds an open pause in on finish', () => {
		let t = startTimer(createTimer(900), sec(0))
		t = pauseTimer(t, sec(40))
		t = finishTimer(t, sec(100)) // 60s open pause folded; elapsed = 40
		expect(elapsedSeconds(t, sec(500))).toBe(40)
		expect(pausedSeconds(t, sec(500))).toBe(60)
	})

	it('is idempotent', () => {
		const t = finishTimer(startTimer(createTimer(900), sec(0)), sec(60))
		expect(finishTimer(t, sec(120))).toBe(t)
	})
})

describe('formatClock', () => {
	it('formats m:ss', () => {
		expect(formatClock(75)).toBe('1:15')
		expect(formatClock(5)).toBe('0:05')
		expect(formatClock(0)).toBe('0:00')
	})

	it('formats h:mm:ss for >= 1 hour', () => {
		expect(formatClock(3675)).toBe('1:01:15')
	})

	it('formats negative (over-time) with a leading minus', () => {
		expect(formatClock(-90)).toBe('-1:30')
		expect(formatClock(-5)).toBe('-0:05')
	})

	it('is NaN-safe', () => {
		expect(formatClock(NaN)).toBe('0:00')
		expect(formatClock(undefined)).toBe('0:00')
	})
})
