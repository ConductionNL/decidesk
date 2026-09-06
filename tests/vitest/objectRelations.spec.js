/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for src/utils/objectRelations.js — the client-side relation
 * scoping VotingRoundPanel uses to keep one motion's voting round out of
 * another motion's panel.
 *
 * The fixtures below are VERBATIM OpenRegister payloads captured from a CI
 * run (job 99120014050), not invented shapes: the flattened `@self.relations`
 * map is what a collection read returns, and the structured `relations` array
 * is what a write echoes back. A helper that only handled the shape its author
 * imagined is exactly how the panel came to display a stranger's round.
 *
 * @spec openspec/specs/voting-system/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	matching,
	references,
	RELATION_CONTAINS_FILTER,
	relationFilterFor,
} from '../../src/utils/objectRelations.js'

const MOTION = 'd8b133eb-dfcb-4832-8320-22dd5c739b3f'
const OTHER = 'c7d10000-0000-4000-a000-000000000061'

/** A round as a COLLECTION read serves it: link only in the flattened map. */
const collectionRow = {
	id: '6e0a9de9-b4c2-4676-b4dc-01712ab343af',
	relations: null,
	'@self': {
		relations: {
			votingMethod: 'for-against-abstain',
			voteThreshold: 'qualified-majority-three-quarters',
			'relations.0.id': MOTION,
		},
	},
}

/** A round as a WRITE echoes it back: structured array on the body. */
const writeEcho = {
	id: '6e0a9de9-b4c2-4676-b4dc-01712ab343af',
	relations: [{ register: 'decidiq', schema: 'motion', id: MOTION }],
}

/** A seeded round that references nothing — the kind that hijacked the panel. */
const unrelatedRow = {
	id: OTHER,
	relations: null,
	'@self': { relations: null },
}

describe('relationFilterFor', () => {
	it('uses the dot-free key, because PHP mangles dots in parameter names', () => {
		expect(RELATION_CONTAINS_FILTER).toBe('_relations_contains')
		expect(RELATION_CONTAINS_FILTER).not.toContain('.')
		expect(relationFilterFor(MOTION)).toEqual({
			_relations_contains: MOTION,
		})
	})

	it('emits no filter at all for a missing id, rather than an empty one', () => {
		expect(relationFilterFor('')).toEqual({})
		expect(relationFilterFor(undefined)).toEqual({})
	})
})

describe('references', () => {
	it('matches the flattened @self.relations map a collection read returns', () => {
		expect(references(collectionRow, MOTION)).toBe(true)
	})

	it('matches the structured relations array a write echoes back', () => {
		expect(references(writeEcho, MOTION)).toBe(true)
	})

	it('does not match an object that references nothing', () => {
		expect(references(unrelatedRow, MOTION)).toBe(false)
	})

	it('does not match a DIFFERENT related id', () => {
		expect(references(collectionRow, OTHER)).toBe(false)
	})

	it('ignores a matching uuid outside the relation structures', () => {
		// `revoteOfRound` holds a uuid but is not a relation projection; a
		// helper that scanned the whole object would match it and scope the
		// panel to the wrong round all over again.
		expect(references({ revoteOfRound: MOTION }, MOTION)).toBe(false)
	})

	it('is false for a falsy target rather than matching everything', () => {
		expect(references(collectionRow, '')).toBe(false)
		expect(references(collectionRow, undefined)).toBe(false)
	})
})

describe('matching', () => {
	it('keeps only the rows that reference the motion', () => {
		const rows = [unrelatedRow, collectionRow, { id: 'x' }]
		expect(matching(rows, MOTION)).toEqual([collectionRow])
	})

	it('returns EMPTY for a missing id — never the unfiltered page', () => {
		// The whole defect: with no id to scope by, answering with every row
		// on the instance is how another motion's open round ended up in the
		// panel (and how a vote would have been cast against it).
		expect(matching([unrelatedRow, collectionRow], '')).toEqual([])
	})

	it('tolerates a null collection', () => {
		expect(matching(null, MOTION)).toEqual([])
	})
})
