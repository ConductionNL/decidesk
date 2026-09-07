/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest — member-import helpers (CSV parse, validation preview,
 * duplicate handling) for the governance-body CSV/group import dialogs.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	DEFAULT_ROLE,
	markGroupDuplicates,
	MAX_IMPORT_ROWS,
	parseCsv,
	parseMemberCsv,
	validateMemberRows,
} from '../../src/utils/memberImport.js'

describe('parseCsv', () => {
	it('parses simple rows with LF endings', () => {
		expect(parseCsv('a,b,c\nd,e,f\n')).toEqual([
			['a', 'b', 'c'],
			['d', 'e', 'f'],
		])
	})

	it('parses CRLF endings and skips blank lines', () => {
		expect(parseCsv('a,b\r\n\r\nc,d\r\n')).toEqual([
			['a', 'b'],
			['c', 'd'],
		])
	})

	it('handles quoted fields with commas, newlines and escaped quotes', () => {
		const text = '"de Vries, Anna",anna@x.nl\n"say ""hi""","multi\nline"'
		expect(parseCsv(text)).toEqual([
			['de Vries, Anna', 'anna@x.nl'],
			['say "hi"', 'multi\nline'],
		])
	})

	it('strips a UTF-8 BOM', () => {
		expect(parseCsv('﻿name,email\na,b')).toEqual([
			['name', 'email'],
			['a', 'b'],
		])
	})

	it('returns [] for empty input', () => {
		expect(parseCsv('')).toEqual([])
		expect(parseCsv(null)).toEqual([])
	})
})

describe('parseMemberCsv', () => {
	it('maps header columns case-insensitively and in any order', () => {
		const { rows, error } = parseMemberCsv('Email,Role,Name\na@x.nl,chair,Anna')
		expect(error).toBeNull()
		expect(rows).toEqual([
			{ name: 'Anna', email: 'a@x.nl', role: 'chair', line: 2 },
		])
	})

	it('defaults a missing role column to empty string', () => {
		const { rows } = parseMemberCsv('name,email\nAnna,a@x.nl')
		expect(rows[0].role).toBe('')
	})

	it('errors on a missing name/email header', () => {
		expect(parseMemberCsv('foo,bar\n1,2').error).toBe('header')
	})

	it('errors on empty input', () => {
		expect(parseMemberCsv('').error).toBe('empty')
	})

	it('caps the import at MAX_IMPORT_ROWS', () => {
		const lines = ['name,email,role']
		for (let i = 0; i <= MAX_IMPORT_ROWS; i++) {
			lines.push(`User ${i},u${i}@x.nl,member`)
		}
		expect(parseMemberCsv(lines.join('\n')).error).toBe('too-many-rows')
	})

	it('accepts exactly MAX_IMPORT_ROWS rows', () => {
		const lines = ['name,email,role']
		for (let i = 0; i < MAX_IMPORT_ROWS; i++) {
			lines.push(`User ${i},u${i}@x.nl,member`)
		}
		const { rows, error } = parseMemberCsv(lines.join('\n'))
		expect(error).toBeNull()
		expect(rows).toHaveLength(MAX_IMPORT_ROWS)
	})
})

describe('validateMemberRows', () => {
	const row = (over = {}) => ({
		name: 'Anna',
		email: 'a@x.nl',
		role: 'chair',
		line: 2,
		...over,
	})

	it('accepts a valid row', () => {
		const [out] = validateMemberRows([row()])
		expect(out.status).toBe('ok')
		expect(out.role).toBe('chair')
	})

	it('defaults an empty role to DEFAULT_ROLE', () => {
		const [out] = validateMemberRows([row({ role: '' })])
		expect(out.status).toBe('ok')
		expect(out.role).toBe(DEFAULT_ROLE)
	})

	it('flags a missing name', () => {
		const [out] = validateMemberRows([row({ name: '' })])
		expect(out).toMatchObject({ status: 'invalid', reason: 'missing-name' })
	})

	it('flags a malformed email', () => {
		const [out] = validateMemberRows([row({ email: 'not-an-email' })])
		expect(out).toMatchObject({ status: 'invalid', reason: 'invalid-email' })
	})

	it('flags an unknown role', () => {
		const [out] = validateMemberRows([row({ role: 'king' })])
		expect(out).toMatchObject({ status: 'invalid', reason: 'invalid-role' })
	})

	it('treasurer is a valid role (spec scenario)', () => {
		const [out] = validateMemberRows([row({ role: 'treasurer' })])
		expect(out.status).toBe('ok')
	})

	it('flags duplicates against existing members case-insensitively', () => {
		const [out] = validateMemberRows([row()], [{ email: 'A@X.NL' }])
		expect(out).toMatchObject({ status: 'duplicate', reason: 'already-member' })
	})

	it('flags duplicates within the file', () => {
		const [first, second] = validateMemberRows([row(), row({ line: 3 })])
		expect(first.status).toBe('ok')
		expect(second).toMatchObject({
			status: 'duplicate',
			reason: 'duplicate-in-file',
		})
	})

	it('an invalid row does not claim its email for duplicate detection', () => {
		const [bad, good] = validateMemberRows([row({ name: '' }), row({ line: 3 })])
		expect(bad.status).toBe('invalid')
		expect(good.status).toBe('ok')
	})
})

describe('markGroupDuplicates', () => {
	const members = [
		{ uid: 'anna', displayName: 'Anna', email: 'a@x.nl' },
		{ uid: 'bram', displayName: 'Bram', email: '' },
	]

	it('marks duplicates by Nextcloud uid', () => {
		const out = markGroupDuplicates(members, [{ nextcloudUserId: 'anna' }])
		expect(out[0].duplicate).toBe(true)
		expect(out[1].duplicate).toBe(false)
	})

	it('marks duplicates by email when the uid differs', () => {
		const out = markGroupDuplicates(members, [
			{ nextcloudUserId: 'other', email: 'A@x.nl' },
		])
		expect(out[0].duplicate).toBe(true)
	})

	it('never matches members without an email on empty existing emails', () => {
		const out = markGroupDuplicates(members, [{ email: '' }])
		expect(out[1].duplicate).toBe(false)
	})
})
