/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the dashboardData service (src/services/dashboardData.js):
 * the centralised OpenRegister fetch helpers the dashboard widgets call. The
 * shared object store is mocked so we can assert each helper dispatches
 * fetchCollection with the correct logical type and filter params (REQ-006
 * data layer; Task 1).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchCollection = vi.fn()

vi.mock('../../../src/store/store.js', () => ({
	useObjectStore: () => ({ fetchCollection }),
}))

import {
	getActionItems,
	getDecisions,
	getGovernanceBodies,
	getMeetings,
	getMinutes,
	getMotions,
	getParticipants,
	getVotes,
	getVotingRounds,
} from '../../../src/services/dashboardData.js'

beforeEach(() => {
	fetchCollection.mockReset()
	fetchCollection.mockResolvedValue([])
})

describe('dashboardData fetch helpers', () => {
	it('getMeetings fetches the meeting type with the given params', async () => {
		await getMeetings({ lifecycle: 'scheduled' })
		expect(fetchCollection).toHaveBeenCalledWith('meeting', {
			lifecycle: 'scheduled',
		})
	})

	it('getVotingRounds fetches the voting-round type', async () => {
		await getVotingRounds({ lifecycle: 'open' })
		expect(fetchCollection).toHaveBeenCalledWith('voting-round', {
			lifecycle: 'open',
		})
	})

	it('getVotes fetches the vote type', async () => {
		await getVotes({ participant: 'p1' })
		expect(fetchCollection).toHaveBeenCalledWith('vote', { participant: 'p1' })
	})

	it('getActionItems fetches the action-item type', async () => {
		await getActionItems({
			assignee: 'avries',
			taskStatus: ['open', 'in-progress'],
		})
		expect(fetchCollection).toHaveBeenCalledWith('action-item', {
			assignee: 'avries',
			taskStatus: ['open', 'in-progress'],
		})
	})

	it('getMotions fetches the motion type', async () => {
		await getMotions({ lifecycle: ['submitted', 'voting'] })
		expect(fetchCollection).toHaveBeenCalledWith('motion', {
			lifecycle: ['submitted', 'voting'],
		})
	})

	it('getDecisions fetches the decision type', async () => {
		await getDecisions()
		expect(fetchCollection).toHaveBeenCalledWith('decision', {})
	})

	it('getParticipants fetches the participant type', async () => {
		await getParticipants()
		expect(fetchCollection).toHaveBeenCalledWith('participant', {})
	})

	it('getMinutes fetches the minutes type', async () => {
		await getMinutes({ lifecycle: 'review' })
		expect(fetchCollection).toHaveBeenCalledWith('minutes', {
			lifecycle: 'review',
		})
	})

	it('getGovernanceBodies fetches the governance-body type', async () => {
		await getGovernanceBodies()
		expect(fetchCollection).toHaveBeenCalledWith('governance-body', {})
	})

	it('defaults to an empty params object when none is given', async () => {
		await getMeetings()
		expect(fetchCollection).toHaveBeenCalledWith('meeting', {})
	})

	it('resolves to the store result array', async () => {
		fetchCollection.mockResolvedValue([{ id: 'm1' }])
		await expect(getMeetings()).resolves.toEqual([{ id: 'm1' }])
	})
})
