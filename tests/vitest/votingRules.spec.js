/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for src/utils/votingRules.js — the pure voting-rule helpers
 * (base computation, effective-rule defaulting, label maps) backing
 * VotingRoundPanel's rule selectors and rules/base display.
 *
 * @spec openspec/specs/voting-system/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	ABSTENTION_MODES,
	computeBase,
	effectiveRules,
	ruleLabels,
	TIE_BREAK_RULES,
	VOTE_THRESHOLDS,
} from '../../src/utils/votingRules.js'

describe('votingRules enums', () => {
	it('mirror the backend enums exactly', () => {
		expect(VOTE_THRESHOLDS).toEqual([
			'simple-majority',
			'qualified-majority-two-thirds',
			'qualified-majority-three-quarters',
			'unanimous',
		])
		expect(ABSTENTION_MODES).toEqual(['exclude', 'count'])
		expect(TIE_BREAK_RULES).toEqual(['rejected', 'chair-decides', 'revote'])
	})
})

describe('computeBase', () => {
	it('excludes abstentions by default (spec worked example: 14/5/1 -> 19)', () => {
		expect(computeBase({ votesFor: 14, votesAgainst: 5, votesAbstain: 1 })).toBe(
			19,
		)
	})

	it('excludes abstentions when abstentionHandling is exclude', () => {
		expect(
			computeBase({
				votesFor: 14,
				votesAgainst: 5,
				votesAbstain: 1,
				abstentionHandling: 'exclude',
			}),
		).toBe(19)
	})

	it('counts abstentions toward the base when abstentionHandling is count', () => {
		expect(
			computeBase({
				votesFor: 14,
				votesAgainst: 5,
				votesAbstain: 1,
				abstentionHandling: 'count',
			}),
		).toBe(20)
	})

	it('treats missing counts as zero', () => {
		expect(computeBase({})).toBe(0)
		expect(computeBase(null)).toBe(0)
		expect(computeBase({ votesFor: 3 })).toBe(3)
	})

	it('falls back to exclude for unknown abstention modes', () => {
		expect(
			computeBase({
				votesFor: 2,
				votesAgainst: 1,
				votesAbstain: 5,
				abstentionHandling: 'bogus',
			}),
		).toBe(3)
	})
})

describe('effectiveRules', () => {
	it('applies the documented defaults for pre-voting-rules rounds', () => {
		expect(effectiveRules({})).toEqual({
			voteThreshold: 'simple-majority',
			abstentionHandling: 'exclude',
			tieBreakRule: 'rejected',
		})
		expect(effectiveRules(null)).toEqual({
			voteThreshold: 'simple-majority',
			abstentionHandling: 'exclude',
			tieBreakRule: 'rejected',
		})
	})

	it('passes through valid configured rules', () => {
		expect(
			effectiveRules({
				voteThreshold: 'qualified-majority-two-thirds',
				abstentionHandling: 'count',
				tieBreakRule: 'chair-decides',
			}),
		).toEqual({
			voteThreshold: 'qualified-majority-two-thirds',
			abstentionHandling: 'count',
			tieBreakRule: 'chair-decides',
		})
	})

	it('falls back to defaults for unknown values (fail closed)', () => {
		expect(
			effectiveRules({
				voteThreshold: 'plurality',
				abstentionHandling: 'half',
				tieBreakRule: 'coin-flip',
			}),
		).toEqual({
			voteThreshold: 'simple-majority',
			abstentionHandling: 'exclude',
			tieBreakRule: 'rejected',
		})
	})
})

describe('ruleLabels', () => {
	it('provides a label for every enum value through the translate function', () => {
		const seen = []
		const labels = ruleLabels((text) => {
			seen.push(text)
			return text
		})
		VOTE_THRESHOLDS.forEach((value) =>
			expect(labels.voteThreshold[value]).toBeTruthy(),
		)
		ABSTENTION_MODES.forEach((value) =>
			expect(labels.abstentionHandling[value]).toBeTruthy(),
		)
		TIE_BREAK_RULES.forEach((value) =>
			expect(labels.tieBreakRule[value]).toBeTruthy(),
		)
		// Every label went through t() so the strings are translatable.
		expect(seen.length).toBe(
			VOTE_THRESHOLDS.length
				+ ABSTENTION_MODES.length
				+ TIE_BREAK_RULES.length,
		)
	})
})
