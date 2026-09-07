/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/utils/textDiff.js — the pure LCS word-diff behind the
 * amendment diff view and the "most far-reaching first" order suggestion.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	changeMagnitude,
	diffWords,
	suggestVotingOrder,
	tokenizeWords,
} from '../../src/utils/textDiff.js'

describe('tokenizeWords', () => {
	it('returns [] for empty / non-string input', () => {
		expect(tokenizeWords('')).toEqual([])
		expect(tokenizeWords(null)).toEqual([])
		expect(tokenizeWords(undefined)).toEqual([])
	})

	it('splits on arbitrary whitespace without empty tokens', () => {
		expect(tokenizeWords('  a\tb \n c  ')).toEqual(['a', 'b', 'c'])
	})
})

describe('diffWords', () => {
	it('returns no segments when both texts are empty', () => {
		expect(diffWords('', '')).toEqual([])
	})

	it('marks everything added when the original is empty', () => {
		expect(diffWords('', 'geheel nieuwe tekst')).toEqual([
			{ type: 'added', text: 'geheel nieuwe tekst' },
		])
	})

	it('marks everything removed when the proposed text is empty', () => {
		expect(diffWords('oude tekst', '')).toEqual([
			{ type: 'removed', text: 'oude tekst' },
		])
	})

	it('returns a single equal segment for identical texts', () => {
		expect(diffWords('de raad besluit', 'de raad besluit')).toEqual([
			{ type: 'equal', text: 'de raad besluit' },
		])
	})

	it('treats a full replacement as one removal plus one addition', () => {
		expect(diffWords('alpha beta', 'gamma delta')).toEqual([
			{ type: 'removed', text: 'alpha beta' },
			{ type: 'added', text: 'gamma delta' },
		])
	})

	it('detects a single word substitution inside an unchanged sentence', () => {
		expect(
			diffWords(
				'de raad besluit het budget te verlagen',
				'de raad besluit het budget te verhogen',
			),
		).toEqual([
			{ type: 'equal', text: 'de raad besluit het budget te' },
			{ type: 'removed', text: 'verlagen' },
			{ type: 'added', text: 'verhogen' },
		])
	})

	it('detects an insertion without flagging surrounding words', () => {
		expect(
			diffWords(
				'zonnepanelen op gemeentelijke gebouwen',
				'zonnepanelen op alle gemeentelijke gebouwen',
			),
		).toEqual([
			{ type: 'equal', text: 'zonnepanelen op' },
			{ type: 'added', text: 'alle' },
			{ type: 'equal', text: 'gemeentelijke gebouwen' },
		])
	})

	it('handles unicode words (diacritics and non-Latin scripts) as single tokens', () => {
		expect(
			diffWords(
				'financiële situatie van de gemeente',
				'financiële положение van de gemeente',
			),
		).toEqual([
			{ type: 'equal', text: 'financiële' },
			{ type: 'removed', text: 'situatie' },
			{ type: 'added', text: 'положение' },
			{ type: 'equal', text: 'van de gemeente' },
		])
	})

	it('finds common middle ground via LCS, not just prefix/suffix trim', () => {
		const segments = diffWords('a x b y c', 'a q b r c')
		// 'b' in the middle must be recognised as equal.
		expect(segments).toContainEqual({ type: 'equal', text: 'b' })
		expect(segments[0]).toEqual({ type: 'equal', text: 'a' })
		expect(segments[segments.length - 1]).toEqual({ type: 'equal', text: 'c' })
	})

	it('round-trips: equal+removed reconstruct the original, equal+added the proposal', () => {
		const original =
			'de raad verzoekt het college om voor januari een plan te presenteren'
		const proposed =
			'de raad draagt het college op om vóór maart een gedetailleerd plan te presenteren'
		const segments = diffWords(original, proposed)
		const rebuiltOriginal = segments
			.filter((s) => s.type !== 'added')
			.map((s) => s.text)
			.join(' ')
		const rebuiltProposed = segments
			.filter((s) => s.type !== 'removed')
			.map((s) => s.text)
			.join(' ')
		expect(tokenizeWords(rebuiltOriginal)).toEqual(tokenizeWords(original))
		expect(tokenizeWords(rebuiltProposed)).toEqual(tokenizeWords(proposed))
	})

	it('stays bounded on very large inputs (fallback path)', () => {
		const a = Array.from({ length: 2000 }, (_, i) => `w${i}`).join(' ')
		const b = Array.from({ length: 2000 }, (_, i) => `v${i}`).join(' ')
		const segments = diffWords(`start ${a} end`, `start ${b} end`)
		expect(segments[0]).toEqual({ type: 'equal', text: 'start' })
		expect(segments[segments.length - 1]).toEqual({ type: 'equal', text: 'end' })
		expect(segments.some((s) => s.type === 'removed')).toBe(true)
		expect(segments.some((s) => s.type === 'added')).toBe(true)
	})
})

describe('changeMagnitude', () => {
	it('is 0 for identical texts', () => {
		expect(changeMagnitude('a b c', 'a b c')).toBe(0)
	})

	it('counts added plus removed words', () => {
		// one word replaced => 1 removed + 1 added = 2
		expect(changeMagnitude('a b c', 'a x c')).toBe(2)
		// pure insertion => 1
		expect(changeMagnitude('a b', 'a b c')).toBe(1)
	})

	it('counts a full rewrite as all words on both sides', () => {
		expect(changeMagnitude('a b', 'x y z')).toBe(5)
	})
})

describe('suggestVotingOrder', () => {
	const motionText =
		'de raad besluit het budget voor cultuur met tien procent te verhogen'

	it('orders most far-reaching first', () => {
		const small = {
			id: 's',
			title: 'small',
			submittedAt: '2026-01-02',
			proposedText:
				'de raad besluit het budget voor cultuur met twintig procent te verhogen',
		}
		const big = {
			id: 'b',
			title: 'big',
			submittedAt: '2026-01-03',
			proposedText:
				'de gemeenteraad draagt het college op een volledig nieuw cultuurplan op te stellen',
		}
		expect(
			suggestVotingOrder([small, big], motionText).map((a) => a.id),
		).toEqual(['b', 's'])
	})

	it('falls back to the amendment text when proposedText is unset', () => {
		const legacy = {
			id: 'l',
			text: 'volledig andere tekst zonder enige overlap met de motie überhaupt',
			submittedAt: '2026-01-01',
		}
		const minor = {
			id: 'm',
			proposedText:
				'de raad besluit het budget voor cultuur met elf procent te verhogen',
			submittedAt: '2026-01-01',
		}
		expect(
			suggestVotingOrder([minor, legacy], motionText).map((a) => a.id),
		).toEqual(['l', 'm'])
	})

	it('breaks magnitude ties by earlier submittedAt', () => {
		const first = { id: '1', proposedText: 'x', submittedAt: '2026-01-01' }
		const second = { id: '2', proposedText: 'y', submittedAt: '2026-02-01' }
		expect(suggestVotingOrder([second, first], 'x').map((a) => a.id)).toEqual([
			'2',
			'1',
		])
		// equal magnitude case
		const tieA = { id: 'a', proposedText: 'p q', submittedAt: '2026-01-01' }
		const tieB = { id: 'b', proposedText: 'r s', submittedAt: '2026-02-01' }
		expect(suggestVotingOrder([tieB, tieA], 'p q r s').map((a) => a.id)).toEqual(
			['a', 'b'],
		)
	})

	it('does not mutate its input and tolerates empty input', () => {
		const input = [{ id: 'a', proposedText: 'x' }]
		const out = suggestVotingOrder(input, 'y')
		expect(out).not.toBe(input)
		expect(suggestVotingOrder([], 'x')).toEqual([])
		expect(suggestVotingOrder(undefined, 'x')).toEqual([])
	})
})
