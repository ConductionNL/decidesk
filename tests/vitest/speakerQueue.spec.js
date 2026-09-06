/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Exhaustive unit tests for src/utils/speakerQueue.js — the pure speaker-queue
 * logic (meeting-efficiency / speaking time management). Add dedup, remove,
 * chair reorder, give-floor auto-switch with recorded duration, per-speaker
 * elapsed, over-limit. `now` is injected.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	addSpeaker,
	currentSpeaker,
	isOverLimit,
	moveSpeaker,
	removeSpeaker,
	speakerElapsedSeconds,
	startSpeaker,
	stopSpeaker,
} from '../../src/utils/speakerQueue.js'

const T0 = 1_000_000_000_000
const sec = (n) => T0 + n * 1000
const p = (id, name) => ({ id, displayName: name || id })

describe('addSpeaker', () => {
	it('appends in request order', () => {
		let q = addSpeaker([], p('a', 'Alice'), sec(0))
		q = addSpeaker(q, p('b', 'Bob'), sec(1))
		q = addSpeaker(q, p('c', 'Carol'), sec(2))
		q = addSpeaker(q, p('d', 'Dan'), sec(3))
		expect(q.map((e) => e.participantId)).toEqual(['a', 'b', 'c', 'd'])
		expect(q[0].displayName).toBe('Alice')
		expect(q[0].requestedAt).toBe(sec(0))
		expect(q[0].speaking).toBe(false)
	})

	it('dedups a participant who is already queued', () => {
		let q = addSpeaker([], p('a'), sec(0))
		const before = q
		q = addSpeaker(q, p('a'), sec(5))
		expect(q).toBe(before)
		expect(q).toHaveLength(1)
	})

	it('ignores entries without an id', () => {
		const q = addSpeaker([], { displayName: 'No id' }, sec(0))
		expect(q).toHaveLength(0)
	})

	it('falls back to id as display name', () => {
		const q = addSpeaker([], { id: 'x' }, sec(0))
		expect(q[0].displayName).toBe('x')
	})
})

describe('removeSpeaker', () => {
	it('removes by id', () => {
		let q = addSpeaker([], p('a'), sec(0))
		q = addSpeaker(q, p('b'), sec(1))
		q = removeSpeaker(q, 'a')
		expect(q.map((e) => e.participantId)).toEqual(['b'])
	})

	it('is a harmless no-op for unknown ids', () => {
		const q = addSpeaker([], p('a'), sec(0))
		expect(removeSpeaker(q, 'zzz').map((e) => e.participantId)).toEqual(['a'])
	})
})

describe('moveSpeaker (chair reorder)', () => {
	const build = () => {
		let q = addSpeaker([], p('a'), sec(0))
		q = addSpeaker(q, p('b'), sec(1))
		q = addSpeaker(q, p('c'), sec(2))
		return q
	}

	it('moves a speaker up', () => {
		const q = moveSpeaker(build(), 'c', -1)
		expect(q.map((e) => e.participantId)).toEqual(['a', 'c', 'b'])
	})

	it('moves a speaker down', () => {
		const q = moveSpeaker(build(), 'a', 1)
		expect(q.map((e) => e.participantId)).toEqual(['b', 'a', 'c'])
	})

	it('clamps out-of-range moves', () => {
		const q = build()
		expect(moveSpeaker(q, 'a', -1)).toBe(q) // already first
		expect(moveSpeaker(q, 'c', 1)).toBe(q) // already last
		expect(moveSpeaker(q, 'zzz', 1)).toBe(q) // unknown
	})
})

describe('startSpeaker / stopSpeaker / currentSpeaker', () => {
	const build = () => {
		let q = addSpeaker([], p('a', 'Alice'), sec(0))
		q = addSpeaker(q, p('b', 'Bob'), sec(1))
		return q
	}

	it('gives the floor and highlights the current speaker', () => {
		const { queue, stopped } = startSpeaker(build(), 'a', sec(10))
		expect(stopped).toBeNull()
		expect(currentSpeaker(queue).participantId).toBe('a')
		expect(queue.find((e) => e.participantId === 'a').startedAt).toBe(sec(10))
	})

	it('auto-stops the current speaker and reports their duration on switch', () => {
		const { queue } = startSpeaker(build(), 'a', sec(0))
		const res = startSpeaker(queue, 'b', sec(125)) // Alice spoke 125s
		expect(res.stopped).toEqual({ participantId: 'a', durationSeconds: 125 })
		expect(currentSpeaker(res.queue).participantId).toBe('b')
		expect(res.queue.find((e) => e.participantId === 'a').speaking).toBe(false)
		expect(res.queue.find((e) => e.participantId === 'a').spokenMs).toBe(125000)
	})

	it('re-giving the floor to the current speaker is a no-op', () => {
		const { queue } = startSpeaker(build(), 'a', sec(0))
		const res = startSpeaker(queue, 'a', sec(50))
		expect(res.queue).toBe(queue)
		expect(res.stopped).toBeNull()
	})

	it('starting an unknown participant is a no-op', () => {
		const q = build()
		const res = startSpeaker(q, 'zzz', sec(0))
		expect(res.queue).toBe(q)
		expect(res.stopped).toBeNull()
	})

	it('stopSpeaker folds the running turn into spokenMs and reports rounded seconds', () => {
		const { queue } = startSpeaker(build(), 'a', sec(0))
		const res = stopSpeaker(queue, sec(0) + 1499) // 1.499s -> rounds to 1
		expect(res.stopped).toEqual({ participantId: 'a', durationSeconds: 1 })
		expect(currentSpeaker(res.queue)).toBeNull()
	})

	it('stopSpeaker with nobody speaking returns null', () => {
		const res = stopSpeaker(build(), sec(0))
		expect(res.stopped).toBeNull()
	})

	it('accumulates across multiple turns for the same speaker', () => {
		let { queue } = startSpeaker(build(), 'a', sec(0))
		;({ queue } = stopSpeaker(queue, sec(60))) // 60s
		;({ queue } = startSpeaker(queue, 'a', sec(100)))
		;({ queue } = stopSpeaker(queue, sec(130))) // +30s
		const entry = queue.find((e) => e.participantId === 'a')
		expect(speakerElapsedSeconds(entry, sec(200))).toBe(90)
	})
})

describe('speakerElapsedSeconds', () => {
	it('includes the running turn for the active speaker', () => {
		const { queue } = startSpeaker(addSpeaker([], p('a'), sec(0)), 'a', sec(0))
		const entry = queue.find((e) => e.participantId === 'a')
		expect(speakerElapsedSeconds(entry, sec(45))).toBe(45)
	})

	it('returns 0 for a null entry', () => {
		expect(speakerElapsedSeconds(null, sec(0))).toBe(0)
	})
})

describe('isOverLimit (spec 3-minute scenario)', () => {
	it('flags a speaker over the configured limit', () => {
		const { queue } = startSpeaker(addSpeaker([], p('a'), sec(0)), 'a', sec(0))
		const entry = queue.find((e) => e.participantId === 'a')
		expect(isOverLimit(entry, 180, sec(179))).toBe(false)
		expect(isOverLimit(entry, 180, sec(181))).toBe(true)
	})

	it('never over limit when no limit configured', () => {
		const { queue } = startSpeaker(addSpeaker([], p('a'), sec(0)), 'a', sec(0))
		const entry = queue.find((e) => e.participantId === 'a')
		expect(isOverLimit(entry, 0, sec(9999))).toBe(false)
		expect(isOverLimit(entry, null, sec(9999))).toBe(false)
		expect(isOverLimit(entry, -5, sec(9999))).toBe(false)
	})
})
