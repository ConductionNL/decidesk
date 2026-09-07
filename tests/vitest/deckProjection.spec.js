/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the action-item → Deck-card projection
 * (src/services/deckProjection.js): status→lane normalisation, target
 * board/stack resolution, and the idempotent card-per-VTODO projection
 * that writes the new card id back onto the VTODO.
 *
 * @nextcloud/axios is mocked with a tiny URL-routed fake so we can assert
 * exactly which Deck-leaf endpoints are hit and with what payload.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => get(...a), post: (...a) => post(...a) },
}))

import {
	itemUid,
	LANES,
	projectActionItems,
	resolveTarget,
	statusToLane,
} from '../../src/services/deckProjection.js'

beforeEach(() => {
	get.mockReset()
	post.mockReset()
})

// Wire a default happy-path leaf: no sticky default, one board with three
// status-named stacks, and no cards yet linked to the object.
function wireLeaf({ cards = [] } = {}) {
	get.mockImplementation((url) => {
		if (url.includes('/integrations/deck/default/'))
			return Promise.reject(new Error('no default'))
		if (url.includes('/integrations/deck/boards/')) {
			return Promise.resolve({
				data: {
					results: [
						{ id: 11, title: 'Open', boardId: 5 },
						{ id: 12, title: 'In progress', boardId: 5 },
						{ id: 13, title: 'Done', boardId: 5 },
					],
				},
			})
		}
		if (url.includes('/integrations/deck/boards')) {
			return Promise.resolve({
				data: { results: [{ id: 5, title: 'Board' }] },
			})
		}
		if (url.endsWith('/deck')) {
			return Promise.resolve({ data: { results: cards } })
		}
		return Promise.resolve({ data: {} })
	})
	let nextCard = 100
	post.mockImplementation(() => Promise.resolve({ data: { cardId: nextCard++ } }))
}

describe('statusToLane', () => {
	it('maps canonical statuses', () => {
		expect(statusToLane('open')).toBe('open')
		expect(statusToLane('in-progress')).toBe('in-progress')
		expect(statusToLane('done')).toBe('done')
	})
	it('folds completed/in_progress variants', () => {
		expect(statusToLane('completed')).toBe('done')
		expect(statusToLane('in_progress')).toBe('in-progress')
	})
	it('folds unknown/legacy statuses onto open', () => {
		expect(statusToLane('blocked')).toBe('open')
		expect(statusToLane('')).toBe('open')
		expect(statusToLane(undefined)).toBe('open')
	})
	it('exposes lanes in display order', () => {
		expect(LANES).toEqual(['open', 'in-progress', 'done'])
	})
})

describe('itemUid', () => {
	it('reads uuid, then id, then @self.uuid', () => {
		expect(itemUid({ uuid: 'a' })).toBe('a')
		expect(itemUid({ id: 'b' })).toBe('b')
		expect(itemUid({ '@self': { uuid: 'c' } })).toBe('c')
		expect(itemUid({})).toBe('')
	})
})

describe('resolveTarget', () => {
	it('falls back to the first board and matches stacks to lanes by title', async () => {
		wireLeaf()
		const target = await resolveTarget('decision')
		expect(target.boardId).toBe(5)
		expect(target.laneStacks).toEqual({ open: 11, 'in-progress': 12, done: 13 })
		expect(target.fallbackStackId).toBe(11)
	})

	it('throws when no board exists', async () => {
		get.mockImplementation((url) => {
			if (url.includes('/integrations/deck/default/'))
				return Promise.reject(new Error('no default'))
			if (url.includes('/integrations/deck/boards'))
				return Promise.resolve({ data: { results: [] } })
			return Promise.resolve({ data: {} })
		})
		await expect(resolveTarget('decision')).rejects.toThrow(/No Deck board/)
	})
})

describe('projectActionItems', () => {
	it('creates one card per item in the status-matching stack and writes the id back', async () => {
		wireLeaf()
		const persistCardId = vi.fn().mockResolvedValue({})
		const items = [
			{ uuid: 'u1', title: 'Plan opstellen', taskStatus: 'open' },
			{ uuid: 'u2', title: 'Onderzoek', taskStatus: 'in-progress' },
			{ uuid: 'u3', title: 'Notulen', taskStatus: 'done' },
		]
		const res = await projectActionItems({
			register: 'decidiq',
			schema: 'decision',
			objectId: 'obj-1',
			items,
			persistCardId,
		})
		expect(res.created).toBe(3)
		expect(res.skipped).toBe(0)
		expect(res.errors).toEqual([])
		// Stack ids follow the lane mapping.
		expect(post.mock.calls[0][1]).toMatchObject({
			boardId: 5,
			stackId: 11,
			title: 'Plan opstellen',
		})
		expect(post.mock.calls[1][1]).toMatchObject({ stackId: 12 })
		expect(post.mock.calls[2][1]).toMatchObject({ stackId: 13 })
		// Card id written back onto each VTODO.
		expect(persistCardId).toHaveBeenCalledTimes(3)
		expect(persistCardId).toHaveBeenCalledWith('u1', 100)
	})

	it('is idempotent — skips items whose stored deckCardId is still linked', async () => {
		wireLeaf({ cards: [{ cardId: 100 }] })
		const persistCardId = vi.fn().mockResolvedValue({})
		const items = [
			{
				uuid: 'u1',
				title: 'Already on deck',
				taskStatus: 'open',
				deckCardId: 100,
			},
			{ uuid: 'u2', title: 'New one', taskStatus: 'open' },
		]
		const res = await projectActionItems({
			register: 'decidiq',
			schema: 'decision',
			objectId: 'obj-1',
			items,
			persistCardId,
		})
		expect(res.skipped).toBe(1)
		expect(res.created).toBe(1)
		expect(post).toHaveBeenCalledTimes(1)
		expect(persistCardId).toHaveBeenCalledWith('u2', 100)
	})

	it('re-creates a card when the stored deckCardId is no longer linked', async () => {
		wireLeaf({ cards: [] }) // stored id 100 is not present anymore
		const persistCardId = vi.fn().mockResolvedValue({})
		const items = [
			{ uuid: 'u1', title: 'Stale', taskStatus: 'open', deckCardId: 100 },
		]
		const res = await projectActionItems({
			register: 'decidiq',
			schema: 'decision',
			objectId: 'obj-1',
			items,
			persistCardId,
		})
		expect(res.created).toBe(1)
		expect(res.skipped).toBe(0)
	})

	it('records per-item errors without aborting the batch', async () => {
		wireLeaf()
		post.mockReset()
		post.mockImplementationOnce(() =>
			Promise.reject({ response: { data: { error: 'boom' } } }),
		).mockImplementation(() => Promise.resolve({ data: { cardId: 200 } }))
		const persistCardId = vi.fn().mockResolvedValue({})
		const items = [
			{ uuid: 'u1', title: 'Fails', taskStatus: 'open' },
			{ uuid: 'u2', title: 'Works', taskStatus: 'open' },
		]
		const res = await projectActionItems({
			register: 'decidiq',
			schema: 'decision',
			objectId: 'obj-1',
			items,
			persistCardId,
		})
		expect(res.created).toBe(1)
		expect(res.errors).toEqual([{ uid: 'u1', error: 'boom' }])
	})
})
