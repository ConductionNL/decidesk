/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure helpers behind MeetingAuditStatementTab.vue
 * (src/components/tabs/auditStatementVisibility.js): the assoc-mode
 * visibility gate and the CnObjectListWidget content blob.
 *
 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-audit-statements-facet-assoc-mode-only
 */

import { describe, expect, it } from 'vitest'
import {
	ASSOC_MODE,
	auditStatementContent,
	isAuditStatementVisible,
} from '../../src/components/tabs/auditStatementVisibility.js'

describe('isAuditStatementVisible (REQ-MDV-012)', () => {
	it('is visible in association ("assoc") mode', () => {
		expect(isAuditStatementVisible('assoc')).toBe(true)
	})

	it('is hidden in every other organisatie_modus', () => {
		expect(isAuditStatementVisible('gov')).toBe(false)
		expect(isAuditStatementVisible('corp')).toBe(false)
		expect(isAuditStatementVisible('ops')).toBe(false)
	})

	it('is hidden for an unset/unknown mode (defensive default)', () => {
		expect(isAuditStatementVisible('')).toBe(false)
		expect(isAuditStatementVisible(undefined)).toBe(false)
		expect(isAuditStatementVisible('not-a-real-mode')).toBe(false)
	})

	it('the ASSOC_MODE constant is what the gate checks against', () => {
		expect(isAuditStatementVisible(ASSOC_MODE)).toBe(true)
		expect(ASSOC_MODE).toBe('assoc')
	})
})

describe('auditStatementContent (REQ-MDV-012)', () => {
	it('scopes the filter to the current meeting governanceBody via the @object token', () => {
		const content = auditStatementContent()
		expect(content.filter).toEqual({ governanceBody: '@object.governanceBody' })
	})

	it('targets the audit-statement schema on the decidiq register', () => {
		const content = auditStatementContent()
		expect(content.register).toBe('decidiq')
		expect(content.schema).toBe('audit-statement')
	})

	it('is read-only — no create affordance', () => {
		expect(auditStatementContent().allowCreate).toBe(false)
	})

	it('carries the viewAllRoute/viewAllQuery/rowRoute the top-level register pages declare', () => {
		const content = auditStatementContent()
		expect(content.viewAllRoute).toBe('AuditStatements')
		expect(content.viewAllQuery).toEqual({
			governanceBody: '@object.governanceBody',
		})
		expect(content.rowRoute).toBe('AuditStatementDetail')
	})
})
