// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the client-side state-machine graph validation in
// src/store/modules/processTemplates.js (process-config-v1). Mirrors the
// authoritative server-side checks in ProcessTemplateService.
//
// @spec openspec/specs/process-configuration/spec.md

import { describe, expect, it } from 'vitest'
import { validateStateMachineGraph } from '../../src/services/processTemplateGraph.js'

function validTemplate() {
	return {
		initialState: 'draft',
		stateMachine: {
			states: [{ name: 'draft' }, { name: 'proposed' }, { name: 'decided' }],
			transitions: [
				{ from: 'draft', to: 'proposed' },
				{ from: 'proposed', to: 'decided' },
			],
		},
	}
}

describe('validateStateMachineGraph', () => {
	it('accepts a well-formed graph', () => {
		const r = validateStateMachineGraph(validTemplate())
		expect(r.valid).toBe(true)
		expect(r.errors).toEqual([])
	})

	it('rejects a dangling transition target', () => {
		const t = validTemplate()
		t.stateMachine.transitions.push({ from: 'decided', to: 'ghost' })
		const r = validateStateMachineGraph(t)
		expect(r.valid).toBe(false)
		expect(r.errors.join(' ')).toContain('ghost')
	})

	it('rejects an unreachable state', () => {
		const t = validTemplate()
		t.stateMachine.states.push({ name: 'orphan' })
		const r = validateStateMachineGraph(t)
		expect(r.valid).toBe(false)
		expect(r.errors.join(' ').toLowerCase()).toContain('unreachable')
	})

	it('rejects an unknown guard token', () => {
		const t = validTemplate()
		t.stateMachine.transitions[0].guards = ['quorum_met', 'made_up']
		const r = validateStateMachineGraph(t)
		expect(r.valid).toBe(false)
		expect(r.errors.join(' ')).toContain('made_up')
	})

	it('rejects an initialState not declared in states', () => {
		const t = validTemplate()
		t.initialState = 'nowhere'
		const r = validateStateMachineGraph(t)
		expect(r.valid).toBe(false)
		expect(r.errors.join(' ')).toContain('nowhere')
	})

	it('requires a stateMachine object', () => {
		expect(validateStateMachineGraph({}).valid).toBe(false)
		expect(validateStateMachineGraph(null).valid).toBe(false)
	})
})
