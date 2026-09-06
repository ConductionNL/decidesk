/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent e2e — the decision/voting workflow with QUORUM and
 * TALLY correctness, plus the chair AUTHORIZATION guard.
 *
 * decidiq has a documented history of voting/quorum logic gaps
 * (isTransitionAllowed / validateQuorum defined-but-uncalled, fail-open auth
 * resolvers), so this spec exercises the state-change + quorum + tally paths
 * hard and asserts the *exact* computed outcomes — not just that pages render.
 *
 * It drives the REAL decidiq endpoints through Playwright's authenticated
 * request context (carries the admin session cookie + CSRF token):
 *   POST /api/voting-rounds            (open — chair-guarded, quorum-gated)
 *   POST /api/voting-rounds/{id}/cast  (cast a for/against/abstain vote)
 *   POST /api/voting-rounds/{id}/close (tally votes + drive motion lifecycle)
 *   POST /api/voting-rounds/{id}/tally (show-of-hands aggregate tally)
 * Fixtures are seeded via the OpenRegister object API (real verbs).
 *
 * ── DEPLOY REALITY (re-measured 2026-08-06 against CI run 31083903075) ────────
 * The older header here described "BUG-A" as decidiq filtering on
 * `relations.<schema>` where OpenRegister wanted `_relations.<schema>`. That
 * diagnosis was wrong in its second half, and the wrong half is why the tally
 * assertions kept failing after the "fix": the call sites HAD been changed to
 * `_relations.<schema-slug>`, and a slug-keyed filter still matches nothing.
 *
 * The real mechanism: decidiq writes links as a structured
 * `relations: [{register, schema, id}]` array, and OpenRegister's
 * SaveObject::scanForRelations() flattens that into the `_relations` JSONB keyed
 * by the PROPERTY PATH it walked — `relations.0.id` — never by the related
 * schema's slug. Its `_relations.<field>` filter resolves to
 * `kv.value = <id> AND (kv.key = '<field>' OR kv.key LIKE '<field>.%')`, so
 * `_relations.voting-round` cannot match a key named `relations.0.id`. Every
 * such query returned ZERO rows on a healthy HTTP 200, with nothing logged:
 * tallyResults saw no ballots and computed 0/0/0 = "invalid" no matter what was
 * cast. Fixed by keying the filter on `relations` (ObjectRelationFilter).
 *
 * "BUG-B" (saveShowOfHandsTally/castVote typed `: array` but returning an
 * ObjectEntity) is asserted directly by the last two tests below rather than
 * described here — if it regresses they go red on the 500.
 *
 * @e2e openspec/specs/meeting-management/spec.md#quorum-not-met-meeting-cannot-proceed-to-voting
 * @e2e openspec/specs/decision-management/spec.md#transition-a-decision-from-draft-to-proposed
 */
import type { SeedLedger } from './governance-fixture.ts'

import { expect, test } from '@playwright/test'
import {
	BASE,
	cleanupAll,
	createObject,
	getObject,
	listObjects,
	MOTION_SCHEMA,
	newLedger,
	objId,
	seedGovernanceScenario,
	writeHeaders,
} from './governance-fixture.ts'

const API = `${BASE}/index.php/apps/decidiq/api`

let ledger: SeedLedger

test.beforeAll(() => {
	ledger = newLedger()
})

test.beforeEach(async ({ page }) => {
	await page.goto(`${BASE}/apps/decidiq/`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// ── AUTHORIZATION GUARD: open is chair-gated and fail-CLOSED (REAL, green) ────

// @e2e openspec/specs/meeting-management/spec.md#quorum-not-met-meeting-cannot-proceed-to-voting
// The per-meeting chair/secretary guard MUST block an unauthorized open. This
// asserts the guard is wired and fail-CLOSED (not fail-open): the caller is not
// a resolvable chair/secretary of the meeting, so open is rejected with 403 and
// NO voting-round is persisted.
test('open voting round is blocked (403) when caller is not a meeting chair/secretary', async ({
	page,
}) => {
	// Seed a meeting whose only members are plain members — the admin caller is
	// NOT a chair/secretary of this body.
	const s = await seedGovernanceScenario(page, ledger, {
		quorumRequired: 0,
		memberCount: 2,
		chairIsAdmin: false,
	})

	const before = (await listObjects(page, 'voting-round')).length

	const resp = await page.request.post(`${API}/voting-rounds`, {
		headers: await writeHeaders(page),
		data: {
			motionId: s.motionId,
			meetingId: s.meetingId,
			votingMethod: 'for-against-abstain',
			isSecret: false,
		},
	})

	// Guard must reject — fail CLOSED, not open.
	expect(resp.status(), 'unauthorized open must be 403').toBe(403)
	const body = await resp.json()
	expect(body.message).toMatch(/chair or secretary/i)

	// And crucially: no voting-round leaked into the store.
	const after = (await listObjects(page, 'voting-round')).length
	expect(after, 'no voting-round may be created when the guard blocks').toBe(
		before,
	)
})

// With the relation filter fixed (BUG-A), a participant seeded as chair (role=chair,
// nextcloudUserId=admin) now resolves correctly, so the per-meeting guard ALLOWS the
// authorised chair to open the round. Combined with the fail-CLOSED test above (a
// non-chair caller is rejected with 403), this proves the guard resolves the real
// chair role rather than blanket-blocking or blanket-allowing.
test('open voting round succeeds for a seeded meeting chair (guard resolves the chair)', async ({
	page,
}) => {
	const s = await seedGovernanceScenario(page, ledger, {
		quorumRequired: 0,
		memberCount: 3,
		chairIsAdmin: true,
	})

	const resp = await page.request.post(`${API}/voting-rounds`, {
		headers: await writeHeaders(page),
		data: {
			motionId: s.motionId,
			meetingId: s.meetingId,
			votingMethod: 'for-against-abstain',
			isSecret: false,
		},
	})

	// The authorised chair must be allowed through (201), and a round must be created.
	expect(
		resp.status(),
		`seeded chair must be allowed to open (got ${resp.status()})`,
	).toBe(201)
	const round = await resp.json()
	expect(objId(round), 'an opened round must be returned').toBeTruthy()
})

// ── QUORUM enforcement (REAL where reachable): no round persists when blocked ─

// @e2e openspec/specs/meeting-management/spec.md#quorum-not-met-meeting-cannot-proceed-to-voting
// Whether the rejection comes from the chair guard or the quorum check, the
// invariant under test is the same and security-relevant: a meeting that cannot
// satisfy its preconditions MUST NOT produce a voting round. We seed a high
// quorum requirement that the (broken-resolution) member set can never meet and
// assert open is rejected and nothing is persisted.
test('quorum-not-met / preconditions-unmet meeting cannot open a voting round', async ({
	page,
}) => {
	const s = await seedGovernanceScenario(page, ledger, {
		quorumRequired: 99, // impossible to meet
		memberCount: 3,
		chairIsAdmin: true,
	})

	const before = (await listObjects(page, 'voting-round')).length
	const resp = await page.request.post(`${API}/voting-rounds`, {
		headers: await writeHeaders(page),
		data: {
			motionId: s.motionId,
			meetingId: s.meetingId,
			votingMethod: 'for-against-abstain',
			isSecret: false,
		},
	})

	expect(
		resp.status(),
		'open must be rejected when preconditions are unmet',
	).toBeGreaterThanOrEqual(400)
	const after = (await listObjects(page, 'voting-round')).length
	expect(
		after,
		'no voting-round may be created for a quorum-blocked meeting',
	).toBe(before)

	// The motion must NOT have been advanced to "voting" by a blocked open.
	// ADR-005: a motion IS a Decision (decisionType='motion'); there is no
	// standalone `motion` schema to read it back from.
	const motion = await getObject(page, MOTION_SCHEMA, s.motionId)
	expect(
		motion?.lifecycle,
		'motion lifecycle must not advance on a blocked open',
	).not.toBe('voting')
})

// ── TALLY MATH correctness — exact inputs → expected outcomes ─────────────────
//
// These document the canonical tally rules with concrete inputs and the exact
// expected computed outcome, driven through close() (which runs tallyResults).
//
// (The note that used to sit here said these were `test.fixme` pending BUG-A,
// the relation filter that made every count 0/0/0 → "invalid". BUG-A is fixed
// and the cases have been running for some time; the comment was stale and
// described a state the file no longer had.)

interface TallyCase {
	name: string
	votes: Array<'for' | 'against' | 'abstain'>
	expectedFor: number
	expectedAgainst: number
	expectedAbstain: number
	expectedResult: 'adopted' | 'rejected' | 'tied' | 'invalid'
	/**
	 * The round's `tieBreakRule`. Omitted means the round stores none, which is
	 * NOT the same as "no rule applies": VotingResultCalculator falls back to
	 * the spec's default, `rejected`.
	 */
	tieBreakRule?: 'rejected' | 'chair-decides' | 'revote'
}

// A TIE IS NOT A RESULT — IT IS AN INPUT TO tieBreakRule (openspec/specs/voting-system/spec.md).
//
// "Handle a tie vote" is explicit: *with `rejected` (default) the result MUST be
// "rejected" (the motion fails), with `chair-decides` or `revote` the result MUST
// be "tied"*, and "Configure voting rules" adds *the defaults MUST be simple
// majority, abstentions excluded, and motion fails on a tie*.
//
// The single case here previously created a round carrying NO tieBreakRule and
// asserted `tied`. That is the one answer the spec rules out for that round:
// absent a stored rule the calculator falls back to `rejected`, so the case
// asserted against the default it had itself selected. It ran red on every
// full-scope run while VotingResultCalculator was correct the whole time.
//
// The same wrong expectation is also frozen into
// tests/Unit/Service/VotingServiceTest::testTallyResultsTied — which never
// caught it because that entire class is `markTestSkipped()` in setUp (issue
// #90). A skip is not a pass; it is why this contradiction survived.
//
// So the tie is now covered in BOTH directions, which is strictly more coverage
// than the single case it replaces: the default rule must reject, and an
// explicit `revote` must report `tied`. Either one alone can pass while the
// tie branch is broken.
const TALLY_CASES: TallyCase[] = [
	{
		name: 'majority for → adopted',
		votes: ['for', 'for', 'for', 'against', 'abstain'],
		expectedFor: 3,
		expectedAgainst: 1,
		expectedAbstain: 1,
		expectedResult: 'adopted',
	},
	{
		name: 'majority against → rejected',
		votes: ['against', 'against', 'against', 'for'],
		expectedFor: 1,
		expectedAgainst: 3,
		expectedAbstain: 0,
		expectedResult: 'rejected',
	},
	{
		name: 'equal for/against, default tie-break → rejected (abstain excluded from the comparison)',
		votes: ['for', 'for', 'against', 'against', 'abstain'],
		expectedFor: 2,
		expectedAgainst: 2,
		expectedAbstain: 1,
		expectedResult: 'rejected',
	},
	{
		name: 'equal for/against, tieBreakRule=revote → tied (abstain excluded from the comparison)',
		votes: ['for', 'for', 'against', 'against', 'abstain'],
		expectedFor: 2,
		expectedAgainst: 2,
		expectedAbstain: 1,
		expectedResult: 'tied',
		tieBreakRule: 'revote',
	},
]

for (const c of TALLY_CASES) {
	// @e2e openspec/specs/decision-management/spec.md#view-decision-detail-with-voting-results
	// These are the assertions that caught the relation-filter defect described in
	// the file header: the votes below are linked to the round exactly the way the
	// app links them (VoteBallotFactory writes the same structured `relations`
	// array), so a tally of 0 here means the read-back query matched nothing.
	test(`tally math — ${c.name}`, async ({ page }) => {
		// Orphan round (no motion link) so close()'s auth falls back to the
		// global-admin path and the round is actually closable.
		const vr = await createObject(page, ledger, 'voting-round', {
			votingMethod: 'for-against-abstain',
			isSecret: false,
			openedAt: '2026-09-01T10:00:00Z',
			// Spread, not a fixed key: a case that names no rule must persist NO
			// tieBreakRule, so the assertion exercises the calculator's documented
			// fallback rather than a value the test quietly supplied for it.
			...(c.tieBreakRule !== undefined
				? { tieBreakRule: c.tieBreakRule }
				: {}),
		})
		const vrId = objId(vr)

		for (const value of c.votes) {
			await createObject(page, ledger, 'vote', {
				value,
				weight: 1,
				castAt: '2026-09-01T10:05:00Z',
				relations: [
					{ register: 'decidiq', schema: 'voting-round', id: vrId },
				],
			})
		}

		const resp = await page.request.post(`${API}/voting-rounds/${vrId}/close`, {
			headers: await writeHeaders(page),
			data: {},
		})
		expect(resp.status()).toBe(200)

		const round = await getObject(page, 'voting-round', vrId)
		expect(Number(round?.votesFor)).toBe(c.expectedFor)
		expect(Number(round?.votesAgainst)).toBe(c.expectedAgainst)
		expect(Number(round?.votesAbstain)).toBe(c.expectedAbstain)
		expect(round?.result).toBe(c.expectedResult)
	})
}

// @e2e openspec/specs/decision-management/spec.md#view-decision-detail-with-voting-results
// BUG-B: saveShowOfHandsTally() is typed `: array` but returns the ObjectEntity
// from saveObject() → TypeError 500. The exact expected tally math (for=5,
// against=2 → adopted) is asserted here and will pass once the return type is
// fixed to serialise the entity.
test('show-of-hands tally math — for=5 against=2 abstain=1 → adopted', async ({
	page,
}) => {
	const vr = await createObject(page, ledger, 'voting-round', {
		votingMethod: 'show-of-hands',
		isSecret: false,
	})
	const vrId = objId(vr)

	const resp = await page.request.post(`${API}/voting-rounds/${vrId}/tally`, {
		headers: await writeHeaders(page),
		data: { votesFor: 5, votesAgainst: 2, votesAbstain: 1 },
	})
	expect(resp.status(), 'show-of-hands tally should not 500').toBe(200)
	const round = await resp.json()
	expect(Number(round.votesFor)).toBe(5)
	expect(Number(round.votesAgainst)).toBe(2)
	expect(Number(round.votesAbstain)).toBe(1)
	expect(round.result).toBe('adopted')
})

// BUG-B (cast): castVote() has the same `: array` return-type defect — a real
// vote cast 500s before the tally can ever be computed from cast votes.
test('casting a vote returns the persisted vote (no return-type 500)', async ({
	page,
}) => {
	const s = await seedGovernanceScenario(page, ledger, {
		quorumRequired: 0,
		memberCount: 3,
		chairIsAdmin: true,
	})
	// Even reaching cast requires a round; open is guarded, so this is doubly
	// blocked today. Once open + cast are reachable, casting must return 201 with
	// the persisted vote value.
	const open = await page.request.post(`${API}/voting-rounds`, {
		headers: await writeHeaders(page),
		data: {
			motionId: s.motionId,
			meetingId: s.meetingId,
			votingMethod: 'for-against-abstain',
			isSecret: false,
		},
	})
	expect(open.status()).toBe(201)
	const round = await open.json()
	const cast = await page.request.post(
		`${API}/voting-rounds/${objId(round)}/cast`,
		{
			headers: await writeHeaders(page),
			data: { value: 'for' },
		},
	)
	expect(cast.status(), 'cast must not 500 on a return-type error').toBe(201)
	expect((await cast.json()).value).toBe('for')
})
