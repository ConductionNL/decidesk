/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the dashboard refresh mixin (src/views/dashboard/widgets/
 * dashboardRefreshMixin.js) — the shared load/refresh lifecycle every v2
 * dashboard widget mixes in (REQ-006). The signal mechanism is exercised
 * directly; the option hooks (mounted, watch) are invoked against a fake
 * component context so we can assert they re-run load() without mounting a
 * real SFC (the vitest environment is `node`, no DOM).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	bumpDashboardRefresh,
	dashboardRefreshMixin,
	getDashboardRefreshSignal,
} from '../../../src/views/dashboard/widgets/dashboardRefreshMixin.js'

describe('refresh signal (REQ-006)', () => {
	it('exposes a single shared reactive token', () => {
		expect(getDashboardRefreshSignal()).toBe(getDashboardRefreshSignal())
	})

	it('bumpDashboardRefresh increments the shared token', () => {
		const signal = getDashboardRefreshSignal()
		const before = signal.value
		bumpDashboardRefresh()
		expect(signal.value).toBe(before + 1)
	})
})

describe('mixin option hooks (REQ-006)', () => {
	let ctx

	beforeEach(() => {
		ctx = {
			load: vi.fn(),
			loading: false,
			error: null,
		}
	})

	it('seeds loading / error state via data()', () => {
		const data = dashboardRefreshMixin.data()
		expect(data).toEqual({ loading: false, error: null })
	})

	it('calls load() on mount', () => {
		dashboardRefreshMixin.mounted.call(ctx)
		expect(ctx.load).toHaveBeenCalledTimes(1)
	})

	it('calls load() when the refresh token changes', () => {
		dashboardRefreshMixin.watch.dashboardRefreshToken.call(ctx)
		expect(ctx.load).toHaveBeenCalledTimes(1)
	})

	it('does not throw on mount when the host has no load()', () => {
		expect(() => dashboardRefreshMixin.mounted.call({})).not.toThrow()
	})

	it('computed token mirrors the shared signal value', () => {
		const value = dashboardRefreshMixin.computed.dashboardRefreshToken.call(ctx)
		expect(value).toBe(getDashboardRefreshSignal().value)
	})
})
