// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the minutes editor logic module (pure functions +
// debounced autosave scheduler) — minutes-ui-v1.
//
// @spec openspec/specs/resolution-minutes/spec.md

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	availableWorkflowActions,
	canSuggestCorrections,
	correctionCounts,
	createAutosaver,
	getItemNote,
	LIFECYCLE_STAGES,
	mergeItemNote,
} from '../../src/components/minutesEditor/minutesEditor.js'

describe('itemNotes helpers', () => {
	it('returns empty defaults for an agenda item without notes', () => {
		expect(getItemNote(undefined, 'ai-1')).toEqual({
			agendaItem: 'ai-1',
			notes: '',
			decisions: '',
		})
		expect(getItemNote([], 'ai-1')).toEqual({
			agendaItem: 'ai-1',
			notes: '',
			decisions: '',
		})
	})

	it('finds the entry for the requested agenda item', () => {
		const notes = [
			{ agendaItem: 'ai-1', notes: 'Discussed', decisions: '' },
			{ agendaItem: 'ai-2', notes: '', decisions: 'Adopted' },
		]
		expect(getItemNote(notes, 'ai-2').decisions).toBe('Adopted')
	})

	it('merges a patch immutably, preserving other items', () => {
		const original = [{ agendaItem: 'ai-1', notes: 'Old', decisions: 'Keep' }]
		const merged = mergeItemNote(original, 'ai-1', { notes: 'New' })
		expect(merged).toEqual([
			{ agendaItem: 'ai-1', notes: 'New', decisions: 'Keep' },
		])
		expect(original[0].notes).toBe('Old')
	})

	it('creates an entry when the agenda item had none yet', () => {
		const merged = mergeItemNote([], 'ai-3', { decisions: 'Postponed' })
		expect(merged).toEqual([
			{ agendaItem: 'ai-3', notes: '', decisions: 'Postponed' },
		])
	})

	it('drops an entry once both notes and decisions are empty', () => {
		const original = [
			{ agendaItem: 'ai-1', notes: 'x', decisions: '' },
			{ agendaItem: 'ai-2', notes: 'keep', decisions: '' },
		]
		const merged = mergeItemNote(original, 'ai-1', { notes: '' })
		expect(merged).toEqual([
			{ agendaItem: 'ai-2', notes: 'keep', decisions: '' },
		])
	})
})

describe('createAutosaver (debounce, queueing, dirty/flush)', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('debounces: only the latest payload is saved once the delay elapses', async () => {
		const save = vi.fn().mockResolvedValue()
		const autosaver = createAutosaver({ save, delay: 1000 })

		autosaver.schedule(['v1'])
		vi.advanceTimersByTime(500)
		autosaver.schedule(['v2'])
		vi.advanceTimersByTime(999)
		expect(save).not.toHaveBeenCalled()

		vi.advanceTimersByTime(1)
		await vi.runAllTimersAsync()

		expect(save).toHaveBeenCalledTimes(1)
		expect(save).toHaveBeenCalledWith(['v2'])
	})

	it('reports state transitions pending → saving → saved', async () => {
		const states = []
		const autosaver = createAutosaver({
			save: vi.fn().mockResolvedValue(),
			delay: 100,
			onStateChange: (state) => states.push(state),
		})

		autosaver.schedule(['x'])
		expect(states).toEqual(['pending'])
		await vi.runAllTimersAsync()
		expect(states).toEqual(['pending', 'saving', 'saved'])
		expect(autosaver.getState()).toBe('saved')
	})

	it('reports error state when the save rejects', async () => {
		const autosaver = createAutosaver({
			save: vi.fn().mockRejectedValue(new Error('boom')),
			delay: 100,
		})

		autosaver.schedule(['x'])
		await vi.runAllTimersAsync()
		expect(autosaver.getState()).toBe('error')
	})

	it('queues a payload scheduled while a save is in flight (no concurrent writes)', async () => {
		let resolveFirst
		const save = vi
			.fn()
			.mockImplementationOnce(
				() =>
					new Promise((resolve) => {
						resolveFirst = resolve
					}),
			)
			.mockResolvedValue()
		const autosaver = createAutosaver({ save, delay: 100 })

		autosaver.schedule(['first'])
		await vi.advanceTimersByTimeAsync(100)
		expect(save).toHaveBeenCalledTimes(1)

		// While the first save is pending, a new edit arrives and its timer fires.
		autosaver.schedule(['second'])
		await vi.advanceTimersByTimeAsync(100)
		expect(save).toHaveBeenCalledTimes(1)

		resolveFirst()
		await vi.runAllTimersAsync()

		expect(save).toHaveBeenCalledTimes(2)
		expect(save).toHaveBeenLastCalledWith(['second'])
	})

	it('flush() saves a pending payload immediately', async () => {
		const save = vi.fn().mockResolvedValue()
		const autosaver = createAutosaver({ save, delay: 60000 })

		autosaver.schedule(['unsaved'])
		await autosaver.flush()

		expect(save).toHaveBeenCalledTimes(1)
		expect(save).toHaveBeenCalledWith(['unsaved'])
	})

	it('cancel() discards the pending payload', async () => {
		const save = vi.fn().mockResolvedValue()
		const autosaver = createAutosaver({ save, delay: 100 })

		autosaver.schedule(['discard-me'])
		autosaver.cancel()
		await vi.runAllTimersAsync()

		expect(save).not.toHaveBeenCalled()
		expect(autosaver.getState()).toBe('idle')
	})
})

describe('corrections state helpers', () => {
	it('counts corrections by status', () => {
		expect(
			correctionCounts([
				{ status: 'proposed' },
				{ status: 'proposed' },
				{ status: 'accepted' },
				{ status: 'rejected' },
				{ status: 'weird' },
				null,
			]),
		).toEqual({ proposed: 2, accepted: 1, rejected: 1 })
		expect(correctionCounts(undefined)).toEqual({
			proposed: 0,
			accepted: 0,
			rejected: 0,
		})
	})

	it('allows suggestions only while draft or review', () => {
		expect(canSuggestCorrections('draft')).toBe(true)
		expect(canSuggestCorrections('review')).toBe(true)
		expect(canSuggestCorrections('approved')).toBe(false)
		expect(canSuggestCorrections('signed')).toBe(false)
		expect(canSuggestCorrections('published')).toBe(false)
	})
})

describe('workflow actions', () => {
	it('exposes the five lifecycle stages in forward order', () => {
		expect(LIFECYCLE_STAGES).toEqual([
			'draft',
			'review',
			'approved',
			'signed',
			'published',
		])
	})

	it('offers submit from draft', () => {
		expect(availableWorkflowActions('draft')).toEqual([
			{ action: 'submit', target: 'review' },
		])
	})

	it('offers approve and reject from review (the guarded backward step)', () => {
		expect(availableWorkflowActions('review')).toEqual([
			{ action: 'approve', target: 'approved' },
			{ action: 'reject', target: 'draft' },
		])
	})

	it('offers sign from approved and publish from signed', () => {
		expect(availableWorkflowActions('approved')).toEqual([
			{ action: 'sign', target: 'signed' },
		])
		expect(availableWorkflowActions('signed')).toEqual([
			{ action: 'publish', target: 'published' },
		])
	})

	it('offers nothing from published or unknown states', () => {
		expect(availableWorkflowActions('published')).toEqual([])
		expect(availableWorkflowActions('garbage')).toEqual([])
	})
})
