/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent e2e — full CRUD-with-persistence for Meeting and
 * Decision driven through the real SPA UI (manifest shell).
 *
 * This is NOT a render-only spec. Each test proves data actually persists:
 *   create -> row appears in the list -> open detail -> assert values ->
 *   delete -> assert the row is gone AND the object is gone from the backend.
 *
 * Create-through-UI nuance (deploy reality, 2026-06-10): the manifest-shell
 * "Create" form keeps its submit button disabled when a *required enum*
 * (NcSelect) field cannot commit its value to the form model under headless
 * Chromium — meeting (meetingType/meetingMode/lifecycle) and decision (outcome)
 * all have required enums. We therefore:
 *   - seed the row through the OpenRegister object API (the same backend the UI
 *     writes to) so the *persistence + read-back + detail + delete* path is
 *     exercised end-to-end through the UI, and
 *   - additionally drive the UI Create dialog and assert the form renders and
 *     accepts input, flagging the disabled-submit defect as a BUG (test.fixme).
 *
 * @e2e openspec/specs/meeting-management/spec.md#create-a-board-meeting-with-physical-location
 * @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
 */
import type { SeedLedger } from './governance-fixture.ts'

import { expect, test } from '@playwright/test'
import {
	BASE,
	cleanupAll,
	createObject,
	getObject,
	newLedger,
	objId,
} from './governance-fixture.ts'

let ledger: SeedLedger

test.beforeAll(() => {
	ledger = newLedger()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// ── Evidence recorder ────────────────────────────────────────────────────────
//
// Every failure in this file is of the shape "the UI did not end up in the
// state the backend says it should be in", and none of them says WHY: a row
// that never appears and a row that appears on page 2 fail identically, as do
// a delete that 403s and a delete that hangs. So record what the app actually
// puts on the wire and print it on failure. This adds evidence only — it
// changes no assertion, no timeout, and no control flow of the tests.

interface ApiCall {
	method: string
	url: string
	body?: string
	status?: number
	response?: string
}

let traffic: ApiCall[] = []

/** Attach request/response/console recorders for OpenRegister API traffic. */
function recordTraffic(page): ApiCall[] {
	const calls: ApiCall[] = []
	page.on('request', (req) => {
		if (!req.url().includes('/apps/openregister/api/')) return
		calls.push({
			method: req.method(),
			url: req.url(),
			body: req.postData()?.slice(0, 1500) ?? undefined,
		})
	})
	page.on('response', async (res) => {
		if (!res.url().includes('/apps/openregister/api/')) return
		// Match the newest still-unanswered request for this URL.
		const entry = [...calls]
			.reverse()
			.find((c) => c.url === res.url() && c.status === undefined)
		if (!entry) return
		entry.status = res.status()
		entry.response = await res
			.text()
			.then((t) => t.slice(0, 1200))
			.catch(() => '<body unreadable>')
	})
	page.on('console', (msg) => {
		if (msg.type() !== 'error') return
		calls.push({ method: 'CONSOLE-ERROR', url: msg.text().slice(0, 400) })
	})
	page.on('pageerror', (err) => {
		calls.push({ method: 'PAGE-ERROR', url: String(err).slice(0, 400) })
	})
	return calls
}

test.beforeEach(({ page }) => {
	traffic = recordTraffic(page)
})

// ⚠️ The first parameter MUST be an object-destructuring pattern, even when no
// fixture is used. Playwright parses the hook's signature statically to work out
// which fixtures to build, and a plain identifier is a hard COLLECTION error:
//   "First argument must use the object destructuring pattern: _args"
// That error aborts the WHOLE SUITE before a single test runs — 0 collected, no
// tally, and the failure names this file rather than anything under test. `{}`
// is the documented way to say "no fixtures, but give me testInfo".
// Playwright requires the first argument to BE an object destructuring
// pattern; a plain identifier makes the file fail to load, so an empty one
// is how a callback says it needs no fixtures.
// eslint-disable-next-line no-empty-pattern
test.afterEach(async ({}, testInfo) => {
	if (testInfo.status === testInfo.expectedStatus) return
	const dump = traffic
		.map((c) =>
			c.method.endsWith('ERROR')
				? `  ${c.method}: ${c.url}`
				: `  ${c.method} ${c.url} -> ${c.status ?? '(no response)'}`
					+ (c.body ? `\n      request: ${c.body}` : '')
					+ (c.response ? `\n      response: ${c.response}` : ''),
		)
		.join('\n')
	console.log(
		`\n[diag] OpenRegister traffic for "${testInfo.title}":\n${dump || '  (none recorded)'}\n`,
	)
	await testInfo.attach('openregister-traffic.txt', {
		body: dump,
		contentType: 'text/plain',
	})
})

/**
 * Print what the list endpoint the UI reads actually returns for `schema`,
 * so a "row never appeared" failure distinguishes "the object is not in the
 * collection" from "it is, but not in the first page the UI asked for".
 * CnIndexPage's self-fetch mode (useListView) requests `_limit=20&_page=1`.
 */
async function dumpListWindow(page, schema: string, needle: string): Promise<void> {
	const url = `${BASE}/index.php/apps/openregister/api/objects/decidiq/${schema}?_limit=20&_page=1`
	const resp = await page.request
		.get(url, { headers: { Accept: 'application/json' } })
		.catch(() => null)
	if (!resp) {
		console.log(`[diag] ${schema}: list probe request failed outright`)
		return
	}
	const body = await resp.json().catch(() => null)
	const rows = body?.results ?? body?.items ?? []
	const names = rows.map(
		(o: any) => o?.title ?? o?.name ?? o?.['@self']?.id ?? o?.id,
	)
	console.log(
		`[diag] ${schema} first UI page (${url}) -> HTTP ${resp.status()}`
			+ ` total=${body?.total ?? body?.pagination?.total ?? 'n/a'}`
			+ ` pages=${body?.pages ?? body?.pagination?.pages ?? 'n/a'}`
			+ ` returned=${rows.length}`
			+ ` containsNeedle=${names.some((n: any) => String(n).includes(needle))}`,
	)
	console.log(`[diag] ${schema} first-page names: ${JSON.stringify(names)}`)

	// And what the TABLE actually rendered. "the object is not in the collection",
	// "it is, but the table drew zero rows" and "the table drew rows but not this
	// one" are three different bugs that the row locator reports identically.
	const rendered = await page
		.getByTestId('cn-object-row')
		.count()
		.catch(() => -1)
	const emptyState = await page
		.getByTestId('cn-object-list-empty')
		.count()
		.catch(() => -1)
	const rowText = await page
		.getByTestId('cn-object-row')
		.allInnerTexts()
		.then((t: string[]) =>
			t.slice(0, 25).map((s) => s.replace(/\s+/g, ' ').slice(0, 120)),
		)
		.catch(() => ['<unreadable>'])
	console.log(
		`[diag] ${schema} table: cn-object-row count=${rendered}`
			+ ` cn-object-list-empty count=${emptyState}`,
	)
	console.log(`[diag] ${schema} rendered rows: ${JSON.stringify(rowText)}`)
}

/** Open a list page in the SPA and wait for the manifest shell to mount. */
async function gotoList(page, path: string) {
	await page.goto(`${BASE}/apps/decidiq/${path}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await page.waitForSelector('[data-testid="cn-object-list"]', { timeout: 15_000 })
}

/** Delete a tracked row through the UI row-action menu + confirm dialog. */
async function deleteRowViaUi(page, title: string): Promise<void> {
	const row = page.getByTestId('cn-object-row').filter({ hasText: title }).first()
	await expect(row, `row "${title}" should be present before delete`).toBeVisible({
		timeout: 10_000,
	})
	await row.getByTestId('cn-row-actions').locator('button').first().click()
	await page.getByRole('menuitem', { name: 'Delete' }).click()
	const confirm = page.getByRole('dialog')
	await expect(confirm).toBeVisible({ timeout: 8_000 })
	await confirm.getByRole('button', { name: /^\s*Delete\s*$/ }).click()
	// CnDeleteDialog is two-phase and, on success, DWELLS: `setResult()` shows
	// the "Item successfully deleted." NoteCard plus a Close button and only
	// then arms a 2 s auto-close. Sitting out that dwell is two seconds of pure
	// waiting inside a 20 s per-test budget that three full list loads have
	// already spent most of — measured on a live instance, the delete itself
	// answers 204 in well under a second and the dialog is still on screen at
	// t+1.5 s purely because of the timer.
	//
	// So assert the success phase (a stronger check than "something vanished":
	// a dialog stuck in `phase=confirm` or showing an error NoteCard now fails
	// HERE, naming the phase) and then dismiss it the way a user does, instead
	// of waiting for the timer to do it.
	await expect(
		page.locator(
			'[data-testid-modal="cn-delete-dialog"][data-testid-phase="result"]',
		),
	).toBeVisible({ timeout: 8_000 })
	await expect(page.getByRole('dialog')).toContainText('successfully deleted')
	// TWO buttons in this dialog answer to the accessible name "Close": the
	// action-footer button CnDeleteDialog renders in the result phase
	// (`closeLabel`, default "Close") and the X in NcModal's own chrome
	// (`.modal-container__close`, `aria-label="Close"`). An unscoped
	// `getByRole('button', { name: 'Close' })` therefore raises a strict-mode
	// violation, which Playwright keeps RETRYING until the per-test budget dies —
	// so it surfaces as a bare 20 s timeout, not as an ambiguous-locator error.
	// Exclude the modal chrome by the class the failing run PRINTED
	// (`modal-container__close`) rather than by scoping to a container class that
	// looked right in the library source but was never observed in this app's DOM —
	// an unobserved container would resolve to zero elements and fail as another
	// bare timeout, which is the thing being fixed. Strictness is preserved: a third
	// button named "Close" would still raise. `exact: true` because `name` matches a
	// SUBSTRING by default, so the loose form would also accept a future
	// "Close cycle"-style button.
	await page
		.getByRole('dialog')
		.getByRole('button', { name: 'Close', exact: true })
		.and(page.locator(':not(.modal-container__close)'))
		.click()
	try {
		await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 8_000 })
	} catch (err) {
		// CnDeleteDialog is two-phase: the parent (CnIndexPage) performs the
		// delete and hands back a result, and only a `{ success: true }` result
		// arms the 2 s auto-close. A dialog that is still on screen after the
		// wait is therefore either stuck in `phase=confirm` (the confirm event
		// never produced a result at all) or sitting in `phase=result` showing an
		// error NoteCard. Those are different bugs and the bare
		// "expected hidden, got visible" cannot tell them apart — so read the
		// phase marker and the dialog copy before re-raising. Timeout unchanged.
		const phase = await page
			.locator('[data-testid-modal="cn-delete-dialog"]')
			.getAttribute('data-testid-phase')
			.catch(() => null)
		const text = await page
			.getByRole('dialog')
			.first()
			.innerText()
			.catch(() => '')
		console.log(
			`[diag] delete dialog for "${title}" did not close.`
				+ ` phase=${phase ?? '<marker absent>'} text=${JSON.stringify(text.slice(0, 600))}`,
		)
		throw err
	}
}

// ── MEETING: full CRUD-with-persistence ──────────────────────────────────────

// @e2e openspec/specs/meeting-management/spec.md#create-a-board-meeting-with-physical-location
test('Meeting: create persists, appears in list, detail shows values, delete removes it', async ({
	page,
}) => {
	// BUDGET, not a hang — and the cap was sized from a sample that did not
	// contain this test passing.
	//
	// This test performs THREE full SPA loads (the list via `gotoList`, the
	// detail page, then the list again after the edit), on top of 2 writes,
	// 3 `getObject` reads, a `toPass` poll capped at 10 s, and the multi-step
	// delete dialog. It is the joint-heaviest test in the file.
	//
	// MEASURED, per-test durations from the list reporter in two CI runs of this
	// same file (⚠️ read the whole table before concluding anything from the two
	// failing cells — the controls are the point):
	//
	//                     274 Meeting  370 edit  403 Decision  512 dialog  568 edit   suite
	//   run 31907724887      18.3s ✓     9.3s ✓     18.0s ✓      5.5s ✓     9.4s ✓   20.9m
	//   run 31979999077      22.1s ✘    11.2s ✓     22.6s ✘      7.3s ✓    11.7s ✓   27.9m
	//   factor                1.21       1.20        1.26         1.33      1.24      1.33
	//
	// EVERY test in the file — the three that keep passing as well as the two
	// that fail — slowed by the SAME 1.20-1.33x factor, in step with total suite
	// wall clock. That is CI runner speed, not a decidiq regression: a slow code
	// path could not also make the 5.5 s dialog test a third slower.
	//
	// So these two are NOT structurally over budget. They sit 1.7-2.0 s UNDER a
	// 20 s cap on a fast runner and 2.1-2.6 s OVER it on a slow one — a coin flip
	// decided by the runner. The 20 s in tests/e2e/playwright.config.ts:103 was
	// derived from run 31022933529, where "the slowest pass in the entire suite"
	// was 7.6 s; that sample was taken while these two tests were still FAILING,
	// so their real cost was never in the sample the cap was computed from.
	//
	// 45 s is ~2x the slowest observed run (22.6 s), which clears runner variance
	// while still failing honestly if a request genuinely hangs. Trimming a load
	// to fit 20 s would delete the list-reflects-the-edit assertion to satisfy a
	// stopwatch.
	test.setTimeout(45_000)

	const tag = `e2e-${ledger.runId}`
	const title = `${tag}-meeting-crud`

	// CREATE (backend write the UI itself performs — see file header for the
	// headless NcSelect caveat).
	const created = await createObject(page, ledger, 'meeting', {
		title,
		meetingType: 'committee',
		scheduledDate: '2026-10-15T14:30:00Z',
		meetingMode: 'hybrid',
		lifecycle: 'scheduled',
		location: 'Council Chamber A',
		quorumRequired: 5,
	})
	const id = objId(created)
	expect(id, 'created meeting should have an id').toBeTruthy()

	// READ: the row appears in the UI list.
	await gotoList(page, 'meetings')
	const row = page.getByTestId('cn-object-row').filter({ hasText: title }).first()
	try {
		await expect(
			row,
			'newly created meeting row should appear in the list',
		).toBeVisible({ timeout: 10_000 })
	} catch (err) {
		await dumpListWindow(page, 'meeting', title)
		throw err
	}

	// DETAIL: navigate to detail and assert the persisted values render.
	await page.goto(`${BASE}/apps/decidiq/meetings/${id}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.getByText(title).first()).toBeVisible({ timeout: 10_000 })
	// Persisted enum + scalar values surface somewhere in the detail view.
	await expect(page.getByText('committee', { exact: false }).first()).toBeVisible()
	await expect(
		page.getByText('Council Chamber A', { exact: false }).first(),
	).toBeVisible()

	// Re-assert persistence at the source of truth.
	const fetched = await getObject(page, 'meeting', id)
	expect(fetched?.title).toBe(title)
	expect(fetched?.meetingType).toBe('committee')
	expect(fetched?.meetingMode).toBe('hybrid')
	expect(Number(fetched?.quorumRequired)).toBe(5)

	// UPDATE: edit + persist. The manifest-shell *edit* dialog cannot save a
	// meeting (its own validator rejects the persisted space-formatted
	// scheduledDate — see the dedicated BUG test below), so we perform the
	// authoritative update through the OR API and assert the change persisted
	// and surfaces in the UI.
	const newTitle = `${title}-edited`
	// OR update = POST with the id in the body (verified: updates in place, no
	// duplicate).
	await createObject(page, ledger, 'meeting', {
		id,
		title: newTitle,
		meetingType: 'committee',
		scheduledDate: '2026-10-15T14:30:00Z',
		meetingMode: 'hybrid',
		lifecycle: 'scheduled',
		location: 'Council Chamber A',
		quorumRequired: 5,
	})
	await expect(async () => {
		const after = await getObject(page, 'meeting', id)
		expect(after?.title).toBe(newTitle)
	}).toPass({ timeout: 10_000 })
	await gotoList(page, 'meetings')
	await expect(
		page.getByTestId('cn-object-row').filter({ hasText: newTitle }).first(),
		'edited title should surface in the list',
	).toBeVisible({ timeout: 10_000 })

	// DELETE: remove through the UI and assert the row + object are gone.
	await deleteRowViaUi(page, newTitle)
	await expect(
		page.getByTestId('cn-object-row').filter({ hasText: newTitle }),
	).toHaveCount(0, { timeout: 10_000 })
	const gone = await getObject(page, 'meeting', id)
	expect(gone, 'deleted meeting should no longer exist in the backend').toBeNull()
})

// @e2e openspec/specs/meeting-management/spec.md#create-a-board-meeting-with-physical-location
// BUG (decidiq, deploy-confirmed 2026-06-10): the manifest-shell EDIT dialog
// for a meeting cannot be saved. OpenRegister stores scheduledDate
// space-separated ("2026-10-15 14:30:00"), but the edit form re-validates that
// persisted value against the schema's `date-time` format and rejects it —
// raising a permanent alert ("'scheduledDate' should match format 'date-time'")
// that blocks Save, even when the user only changes the title. Re-entering the
// datetime-local value does not clear it (the control does not rebind the model
// value). Un-fixme once the edit form normalises / accepts the stored date-time.
test('Meeting edit dialog saves a title change without a scheduledDate format error', async ({
	page,
}) => {
	const tag = `e2e-${ledger.runId}`
	const title = `${tag}-meeting-editbug`
	const created = await createObject(page, ledger, 'meeting', {
		title,
		meetingType: 'regular',
		scheduledDate: '2026-10-15T14:30:00Z',
		meetingMode: 'in-person',
		lifecycle: 'scheduled',
	})
	const id = objId(created)
	await gotoList(page, 'meetings')
	const row = page.getByTestId('cn-object-row').filter({ hasText: title }).first()
	await row.getByTestId('cn-row-actions').locator('button').first().click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	// Edit on an index row NAVIGATES to the detail page now; it no longer
	// opens a modal over the list. A record that has its own detail page is
	// edited there, where its nested collections are reachable, instead of
	// through a dialog that shows only the schema's flat scalars
	// (@conduction/nextcloud-vue 2.21.0). The form is one click further on.
	await page.getByTestId('cn-detail-page-edit').click()
	const editDialog = page.getByRole('dialog')
	await expect(editDialog).toBeVisible({ timeout: 8_000 })
	await editDialog
		.locator('input[placeholder="Meeting title"]')
		.fill(`${title}-edited`)
	// EXPECTED once fixed: no format alert, Save enabled, save succeeds.
	await expect(editDialog.getByRole('alert')).toHaveCount(0)
	await editDialog.getByRole('button', { name: /Save|Update/ }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 8_000 })
	const after = await getObject(page, 'meeting', id)
	expect(after?.title).toBe(`${title}-edited`)
})

// ── DECISION: full CRUD-with-persistence ─────────────────────────────────────

// @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
test('Decision: create persists, appears in list, detail shows values, delete removes it', async ({
	page,
}) => {
	// Same measurement as the Meeting test above — see the duration table there,
	// which covers this test (column "403 Decision") and its three passing
	// controls. Same three full SPA loads: the decisions list, the detail page,
	// and the list again after the edit.
	//
	// ⚠️ Do NOT read this raise as "the app regressed". It did not: on run
	// 31907724887 this test passed in 18.0 s under the same 20 s cap, and on the
	// slower run 31979999077 it took 22.6 s while three tests that never came
	// near the cap slowed by the same factor. The cap sits inside this test's
	// normal run-to-run spread, which is the defect being fixed.
	test.setTimeout(45_000)

	const tag = `e2e-${ledger.runId}`
	const title = `${tag}-decision-crud`

	// The note that used to live here recorded a hand-configured dev instance
	// ("register 18 / schema 96") whose `decision` schema was a besluit-style
	// variant carrying no `text`/`outcome`. That is not the schema this suite
	// runs against any more: CI provisions the register from THIS repo's
	// `lib/Settings/decidesk_register.json`, where Decision declares
	// `required: [title, text, decisionType]`. Omitting one of those is a hard
	// 400 from OpenRegister ("The required properties (text) are missing"),
	// which surfaces as a fixture failure, not as an assertion failure — so it
	// accuses the CRUD path rather than the payload. `decisionDate` and
	// `outcome` are deliberately NOT in that list — an in-flight decision has
	// neither — but this payload is a terminal meeting-outcome, so it carries
	// both.
	//
	// The assertions below are unchanged; only the payload now satisfies the
	// schema the register actually declares.
	const created = await createObject(page, ledger, 'decision', {
		title,
		text: `${tag} decision body text`,
		outcome: 'adopted',
		decisionType: 'meeting-outcome',
		explanation: `${tag} decision rationale text`,
		decisionDate: '2026-10-20T00:00:00Z',
	})
	const id = objId(created)
	expect(id, 'created decision should have an id').toBeTruthy()

	// READ: row appears in the UI list.
	await gotoList(page, 'decisions')
	try {
		await expect(
			page.getByTestId('cn-object-row').filter({ hasText: title }).first(),
			'newly created decision row should appear',
		).toBeVisible({ timeout: 10_000 })
	} catch (err) {
		// The create above returned 2xx (createObject throws on >= 300), so the
		// object exists. Whether it is in the window the UI reads is a separate
		// question the assertion cannot answer on its own.
		await dumpListWindow(page, 'decision', title)
		throw err
	}

	// DETAIL: navigate + assert persisted values.
	await page.goto(`${BASE}/apps/decidiq/decisions/${id}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.getByText(title).first()).toBeVisible({ timeout: 10_000 })

	const fetched = await getObject(page, 'decision', id)
	expect(fetched?.title).toBe(title)
	// decisionDate persists (normalised to date-only by the schema).
	expect(String(fetched?.decisionDate)).toContain('2026-10-20')

	// UPDATE via the OR API (the manifest-shell edit dialog cannot save a
	// decision — see the dedicated BUG test below), then assert persistence and
	// that the new title surfaces in the UI list.
	const newTitle = `${title}-edited`
	// OpenRegister's save is PUT-semantic, so every required property has to be
	// carried forward on an update too — omitting them nulls them, which the
	// schema then rejects.
	await createObject(page, ledger, 'decision', {
		id,
		title: newTitle,
		text: `${tag} decision body text`,
		outcome: 'adopted',
		decisionType: 'meeting-outcome',
		explanation: `${tag} decision rationale text`,
		decisionDate: '2026-10-20T00:00:00Z',
	})
	await expect(async () => {
		const after = await getObject(page, 'decision', id)
		expect(after?.title).toBe(newTitle)
	}).toPass({ timeout: 10_000 })
	await gotoList(page, 'decisions')
	await expect(
		page.getByTestId('cn-object-row').filter({ hasText: newTitle }).first(),
		'edited decision title should surface in the list',
	).toBeVisible({ timeout: 10_000 })

	// DELETE through the UI. No second `gotoList` here: the assertion above
	// already left the browser on the decisions list with the edited row
	// visible, so reloading it was a duplicate full page load (~3.7 s measured)
	// inside a 20 s per-test budget — which is precisely where this test ran
	// out of clock, at `gotoList`'s app-root wait rather than at anything it
	// asserts. The Meeting test above deletes from the list it is already on.
	await deleteRowViaUi(page, newTitle)
	await expect(
		page.getByTestId('cn-object-row').filter({ hasText: newTitle }),
	).toHaveCount(0, { timeout: 10_000 })
	expect(
		await getObject(page, 'decision', id),
		'deleted decision should be gone',
	).toBeNull()
})

// ── BUG: UI Create dialog cannot submit (required NcSelect never commits) ─────

// @e2e openspec/specs/meeting-management/spec.md#create-a-board-meeting-with-physical-location
// BUG (decidiq, deploy-confirmed 2026-06-10): the manifest-shell Create form's
// "Create" button stays disabled even after every required field — including the
// required enum NcSelects (meetingType/meetingMode/lifecycle) — is filled and
// shows a selected chip. Selecting via mouse-click, keyboard (ArrowDown+Enter),
// and pre-filtered search all fail to commit the enum value to the form model,
// so a meeting/decision with required enum fields cannot be created through the
// UI at all. Un-fixme once the NcSelect value binds to the create-form model.
test('Meeting Create dialog submit enables once required enums are selected', async ({
	page,
}) => {
	await gotoList(page, 'meetings')
	await page.getByTestId('cn-cta-primary').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })

	await dialog
		.locator('input[placeholder="Meeting title"]')
		.fill(`e2e-${ledger.runId}-ui-create`)
	// Fill the REQUIRED scheduledDate datetime field by its id (the form also
	// renders an optional `endDate` datetime, so target this one explicitly).
	await dialog.locator('#cn-form-scheduledDate').fill('2026-09-01T10:00')
	// Select every required enum (meetingType / meetingMode / lifecycle). Each
	// must commit its value to the form model for the Create button to enable.
	//
	// Target each required enum by its OWN select and pick a value the register
	// actually declares. CnFormDialog gives every field the stable input id
	// `cn-form-<key>` (`:input-id="'cn-form-' + field.key"` on its NcSelect),
	// and `getEnumOptions()` renders one option per enum member with the raw
	// value as its label — so `#cn-form-meetingType` + option "committee" is an
	// exact, order-independent handle.
	//
	// The previous version walked EVERY `input[type="search"]` in the dialog and
	// clicked `getByRole('option').first()`. The Meeting form has four NcSelects,
	// not three: `governanceBody` is a `$ref: GovernanceBody` property, which
	// `resolveWidget()` also renders as a select — but one whose options are
	// fetched from the governance-body register. `tests/e2e/ci-seed.sh`
	// provisions the register and its schemas and creates NO objects, so on CI
	// that dropdown legitimately has ZERO options and
	// `getByRole('option').first()` can never become clickable. The test then
	// died in its own setup, on a click timeout, without ever reaching the
	// assertion it exists to make — an empty optional relation picker was
	// reported as "required enums do not commit".
	for (const [key, option] of [
		['meetingType', 'committee'],
		['meetingMode', 'hybrid'],
		['lifecycle', 'scheduled'],
	] as const) {
		await dialog.locator(`#cn-form-${key}`).click()
		await page.getByRole('option', { name: option, exact: true }).click()
	}
	// EXPECTED once fixed: the selected enums commit, so the Create button enables.
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeEnabled({
		timeout: 5_000,
	})
})

// @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
// BUG (decidiq, deploy-confirmed 2026-06-10): the manifest-shell EDIT dialog
// for a decision cannot be saved either. Its first text input is the `case`
// field (a uuid-format relation), and the form re-validates the persisted
// values, raising format alerts (e.g. "'case' should match format 'uuid'") that
// block Save. Un-fixme once the decision edit form binds title correctly and
// stops rejecting its own persisted relation/date values.
test('Decision edit dialog saves a title change without a format error', async ({
	page,
}) => {
	const tag = `e2e-${ledger.runId}`
	const title = `${tag}-decision-editbug`
	const created = await createObject(page, ledger, 'decision', {
		title,
		text: 'x',
		outcome: 'adopted',
		decisionType: 'meeting-outcome',
		explanation: 'x',
		decisionDate: '2026-10-20T00:00:00Z',
	})
	const id = objId(created)
	await gotoList(page, 'decisions')
	const row = page.getByTestId('cn-object-row').filter({ hasText: title }).first()
	// Assert the row is there BEFORE reaching into it. Without this the failure
	// surfaces as a click timeout naming `cn-row-actions`, which reads as
	// "the row-actions menu is broken" when the actual state is "the row this
	// test just created is not in the list" — the same condition the Decision
	// CRUD test above hits. Naming it here keeps the two failures legible as one
	// cause instead of two.
	try {
		await expect(
			row,
			`decision row "${title}" should be in the list before editing`,
		).toBeVisible({ timeout: 10_000 })
	} catch (err) {
		await dumpListWindow(page, 'decision', title)
		throw err
	}
	await row.getByTestId('cn-row-actions').locator('button').first().click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	// Edit on an index row NAVIGATES to the detail page now; it no longer
	// opens a modal over the list. A record that has its own detail page is
	// edited there, where its nested collections are reachable, instead of
	// through a dialog that shows only the schema's flat scalars
	// (@conduction/nextcloud-vue 2.21.0). The form is one click further on.
	await page.getByTestId('cn-detail-page-edit').click()
	const editDialog = page.getByRole('dialog')
	await expect(editDialog).toBeVisible({ timeout: 8_000 })
	// EXPECTED once fixed: a labelled title field exists and editing it + saving
	// raises no format alert.
	await editDialog.getByRole('textbox', { name: /title/i }).fill(`${title}-edited`)
	await expect(editDialog.getByRole('alert')).toHaveCount(0)
	await editDialog.getByRole('button', { name: /Save|Update/ }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 8_000 })
	expect((await getObject(page, 'decision', id))?.title).toBe(`${title}-edited`)
})
