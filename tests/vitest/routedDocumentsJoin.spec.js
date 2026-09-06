/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure join/merge helpers behind
 * MeetingRoutedDocumentsTab.vue (src/components/tabs/routedDocumentsJoin.js):
 * the two-hop "routed to this meeting's agenda" join.
 *
 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-013-routed-incoming-documents-facet-read-only
 */

import { describe, expect, it } from 'vitest'
import {
	buildRoutedDocumentRows,
	collectAgendaItemIds,
	filterRoutedIngekomenStukken,
	ROUTE_BY_TYPE,
} from '../../src/components/tabs/routedDocumentsJoin.js'

describe('collectAgendaItemIds', () => {
	it('collects ids from a list of agenda-item objects', () => {
		const agendaItems = [{ id: 'a1' }, { id: 'a2' }]
		expect(collectAgendaItemIds(agendaItems)).toEqual(['a1', 'a2'])
	})

	it('falls back to uuid when id is absent', () => {
		expect(collectAgendaItemIds([{ uuid: 'u1' }])).toEqual(['u1'])
	})

	it('drops entries with neither id nor uuid', () => {
		expect(collectAgendaItemIds([{}, { id: 'a1' }, null])).toEqual(['a1'])
	})

	it('returns an empty array for non-array input', () => {
		expect(collectAgendaItemIds(null)).toEqual([])
		expect(collectAgendaItemIds(undefined)).toEqual([])
	})
})

describe("filterRoutedIngekomenStukken (Scenario: documents routed onto the meeting's agenda)", () => {
	const agendaItemIds = ['a1', 'a2']

	it('keeps a stuk routed via targetAgendaItem', () => {
		const stukken = [{ id: 's1', targetAgendaItem: 'a1' }]
		expect(filterRoutedIngekomenStukken(stukken, agendaItemIds)).toEqual(stukken)
	})

	it('keeps a stuk routed via listAgendaItem (the "en bloc" hamerstuk placement)', () => {
		const stukken = [{ id: 's2', listAgendaItem: 'a2' }]
		expect(filterRoutedIngekomenStukken(stukken, agendaItemIds)).toEqual(stukken)
	})

	it('drops a stuk with no agenda-item reference at all', () => {
		const stukken = [{ id: 's3' }]
		expect(filterRoutedIngekomenStukken(stukken, agendaItemIds)).toEqual([])
	})

	it('drops a stuk routed to an agenda item outside this meeting', () => {
		const stukken = [{ id: 's4', targetAgendaItem: 'other-meeting-agenda-item' }]
		expect(filterRoutedIngekomenStukken(stukken, agendaItemIds)).toEqual([])
	})

	it('mixed set: keeps only the routed ones, in order', () => {
		const stukken = [
			{ id: 's1', targetAgendaItem: 'a1' },
			{ id: 's3' },
			{ id: 's2', listAgendaItem: 'a2' },
		]
		const result = filterRoutedIngekomenStukken(stukken, agendaItemIds)
		expect(result.map((s) => s.id)).toEqual(['s1', 's2'])
	})

	it('is null-safe for both arguments', () => {
		expect(filterRoutedIngekomenStukken(null, agendaItemIds)).toEqual([])
		expect(
			filterRoutedIngekomenStukken(
				[{ id: 's1', targetAgendaItem: 'a1' }],
				null,
			),
		).toEqual([])
	})
})

describe('buildRoutedDocumentRows', () => {
	it('normalises raadsinformatiebrief.subject into the shared title column', () => {
		const rows = buildRoutedDocumentRows(
			[
				{
					id: 'r1',
					subject: 'Voortgang zwembad',
					category: 'sport',
					lifecycle: 'sent',
				},
			],
			[],
		)
		expect(rows).toEqual([
			{
				id: 'r1',
				type: 'raadsinformatiebrief',
				typeLabel: 'Raadsinformatiebrief',
				title: 'Voortgang zwembad',
				category: 'sport',
				lifecycle: 'sent',
			},
		])
	})

	it('normalises ingekomen-stuk.title into the shared title column', () => {
		const rows = buildRoutedDocumentRows(
			[],
			[
				{
					id: 's1',
					title: 'Bezwaarschrift',
					category: 'bezwaar',
					lifecycle: 'routed',
				},
			],
		)
		expect(rows).toEqual([
			{
				id: 's1',
				type: 'ingekomen-stuk',
				typeLabel: 'Ingekomen stuk',
				title: 'Bezwaarschrift',
				category: 'bezwaar',
				lifecycle: 'routed',
			},
		])
	})

	it('merges both kinds into one row set, raadsinformatiebrief first', () => {
		const rows = buildRoutedDocumentRows(
			[{ id: 'r1', subject: 'RIB', category: 'x', lifecycle: 'sent' }],
			[{ id: 's1', title: 'Stuk', category: 'y', lifecycle: 'routed' }],
		)
		expect(rows.map((r) => r.id)).toEqual(['r1', 's1'])
	})

	it('is null-safe for both arguments', () => {
		expect(buildRoutedDocumentRows(null, undefined)).toEqual([])
	})

	it('every row type resolves to a real detail route', () => {
		const rows = buildRoutedDocumentRows(
			[{ id: 'r1', subject: 'RIB' }],
			[{ id: 's1', title: 'Stuk' }],
		)
		for (const row of rows) {
			expect(ROUTE_BY_TYPE[row.type]).toBeTruthy()
		}
		expect(ROUTE_BY_TYPE.raadsinformatiebrief).toBe('RaadsinformatiebriefDetail')
		expect(ROUTE_BY_TYPE['ingekomen-stuk']).toBe('IngekomenStukDetail')
	})
})

describe("end-to-end scenario (spec.md: Documents routed onto the meeting's agenda)", () => {
	it('a raadsinformatiebrief on A1, a stuk on A2 via listAgendaItem, and an unrouted stuk', () => {
		const agendaItems = [{ id: 'A1' }, { id: 'A2' }]
		const agendaItemIds = collectAgendaItemIds(agendaItems)

		// The raadsinformatiebrief fetch is server-filtered by agendaItem IN ids —
		// simulated here as already-scoped input.
		const raadsinformatiebrieven = [
			{
				id: 'rib-1',
				subject: 'Voortgang',
				agendaItem: 'A1',
				category: 'x',
				lifecycle: 'sent',
			},
		]

		// The ingekomen-stuk fetch is unscoped; membership is checked client-side.
		const allIngekomenStukken = [
			{
				id: 'stuk-1',
				listAgendaItem: 'A2',
				title: 'Lijst-stuk',
				category: 'y',
				lifecycle: 'routed',
			},
			{ id: 'stuk-2', title: 'Los stuk, niet geagendeerd' },
		]
		const routedIngekomenStukken = filterRoutedIngekomenStukken(
			allIngekomenStukken,
			agendaItemIds,
		)

		const rows = buildRoutedDocumentRows(
			raadsinformatiebrieven,
			routedIngekomenStukken,
		)

		expect(rows.map((r) => r.id)).toEqual(['rib-1', 'stuk-1'])
		expect(rows.find((r) => r.id === 'stuk-2')).toBeUndefined()
	})
})
