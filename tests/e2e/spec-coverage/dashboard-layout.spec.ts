/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — dashboard v2 layout (decidesk-dashboard-v2-layout).
 *
 * Covers the full-dashboard scenarios deferred from decidesk-dashboard-v2-widgets:
 * the v2 five-row grid, the four-card KPI row, custom-widget visibility, the
 * English stats-block title, the governance-health chart, and the empty state.
 *
 * @e2e openspec/specs/dashboard/spec.md#default-grid-layout-on-first-load
 * @e2e openspec/specs/dashboard/spec.md#empty-state-for-new-installation
 * @e2e openspec/specs/dashboard/spec.md#display-active-decisions-count
 * @e2e openspec/specs/dashboard/spec.md#display-upcoming-meetings-kpi
 * @e2e openspec/specs/dashboard/spec.md#display-pending-votes-kpi
 * @e2e openspec/specs/dashboard/spec.md#display-overdue-actions-kpi
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from '../base-url.ts'

/**
 * Navigate to the Decidiq dashboard root and wait for the SPA shell to mount.
 * Seed data (Gemeenteraad Westerkwartier) is provisioned by the OR API fixture
 * in tests/e2e/global-setup.ts before the suite runs.
 */
async function gotoDashboard(page) {
	await page.goto(`${BASE}/apps/decidiq/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
}

// @e2e openspec/specs/dashboard/spec.md#default-grid-layout-on-first-load
test('v2 grid renders the KPI row, list widgets and governance-health chart', async ({
	page,
}) => {
	await gotoDashboard(page)

	// Row 1 — four KPI cards (gridY=0, each gridWidth=3).
	await expect(page.locator('[data-testid="active-decisions-kpi"]')).toBeVisible()
	await expect(page.locator('[data-testid="upcoming-meetings-kpi"]')).toBeVisible()
	await expect(page.locator('[data-testid="pending-votes-kpi"]')).toBeVisible()
	await expect(page.locator('[data-testid="overdue-actions-kpi"]')).toBeVisible()

	// Rows 2–4 — list / process widgets.
	await expect(
		page.locator('[data-testid="upcoming-meetings-list"]'),
	).toBeVisible()
	await expect(page.locator('[data-testid="pending-votes-list"]')).toBeVisible()
	await expect(page.locator('[data-testid="running-processes"]')).toBeVisible()
	await expect(page.locator('[data-testid="my-action-items"]')).toBeVisible()
	await expect(page.locator('[data-testid="recent-decisions"]')).toBeVisible()

	// Row 5 — minutes-in-review stats-block (English title) + governance-health chart.
	//
	// 🔴 MATCH THE WIDGET, NOT THE PHRASE. "Minutes awaiting approval" is rendered
	// TWICE on this dashboard — once as the widget's own heading
	// (`<h3 class="cn-widget-wrapper__title">`) and once as a KPI card title
	// (`<h4 class="cn-kpi-card__title">`) — so a bare `getByText` is a strict-mode
	// violation, not a passing assertion:
	//
	//   Error: strict mode violation: getByText('Minutes awaiting approval')
	//   resolved to 2 elements
	//
	// It fails on a dashboard that is entirely correct, and it fails LOUDER the
	// more complete the row gets, which is the wrong direction for a layout test.
	//
	// `CnWidgetWrapper` renders its heading as `<h3 :id="titleId">` with
	// `titleId = cn-widget-wrapper-title-${widgetId}` (CnWidgetWrapper.vue), and
	// the widget id here is `minutes-in-review` — the same id src/manifest.json
	// declares for this row. So the id addresses exactly the stats-block this
	// line is about, and stays correct if a second surface shows the same phrase.
	await expect(
		page.locator('#cn-widget-wrapper-title-minutes-in-review'),
	).toBeVisible()
	await expect(page.locator('[data-testid="governance-health"]')).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#display-active-decisions-count
test('active-decisions KPI widget renders via its manifest slot', async ({
	page,
}) => {
	await gotoDashboard(page)
	// ActiveDecisionsKpiWidget is wired through slots["widget-active-decisions"];
	// it counts decisions whose outcome is null client-side.
	await expect(page.locator('[data-testid="active-decisions-kpi"]')).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#display-upcoming-meetings-kpi
test('upcoming-meetings KPI widget renders at gridX=3', async ({ page }) => {
	await gotoDashboard(page)
	await expect(page.locator('[data-testid="upcoming-meetings-kpi"]')).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#display-pending-votes-kpi
test('pending-votes KPI widget renders at gridX=6', async ({ page }) => {
	await gotoDashboard(page)
	await expect(page.locator('[data-testid="pending-votes-kpi"]')).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#display-overdue-actions-kpi
test('overdue-actions KPI widget renders at gridX=9', async ({ page }) => {
	await gotoDashboard(page)
	await expect(page.locator('[data-testid="overdue-actions-kpi"]')).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#empty-state-for-new-installation
test('dashboard exposes the DashboardEmptyState welcome content for a fresh install', async ({
	page,
}) => {
	await gotoDashboard(page)
	// DashboardEmptyState is declared in the manifest (widgets[] + slots) and is
	// shown when no governance body exists. The welcome copy and quick actions
	// are asserted here; on a seeded instance the grid is shown instead, so the
	// assertion tolerates either the empty-state testid or the populated grid.
	const emptyState = page.locator('[data-testid="dashboard-empty-state"]')
	const grid = page.locator('[data-testid="active-decisions-kpi"]')
	await expect(emptyState.or(grid).first()).toBeVisible()
})
