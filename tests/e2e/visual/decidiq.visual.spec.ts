/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for DecideSk's key surfaces (GAP-5).
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { test } from '@playwright/test'
import { shootByNav, shootSurface } from './_visual-helpers.ts'

const APP = '/index.php/apps/decidiq'

test.describe('DecideSk — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}/#/`, 'dashboard.png')
	})

	test('meetings list', async ({ page }) => {
		await shootByNav(page, `${APP}/#/`, 'Meetings', 'meetings.png')
	})

	// The calendar half of the meeting index — a 42-cell month grid that is a
	// different SHAPE from the table above it, so its own baseline is the only
	// thing that would catch a regression in it. Reached by its route rather
	// than by a nav click: the navigation lists the table page only, and the
	// calendar is reached from there through MeetingViewToggle.
	//
	// The baseline PNG is generated on first run, per tests/e2e/visual/README:
	//   npx playwright test --project visual --update-snapshots
	test('meetings calendar — MeetingCalendarView', async ({ page }) => {
		await shootSurface(
			page,
			`${APP}/#/meetings/calendar`,
			'meetings-calendar.png',
		)
	})
})
