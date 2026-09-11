/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * p3-citizen-participation: the API and permission scenarios, proved through
 * the surfaces that actually carry them.
 *
 * WHERE THE SPEC AND THE BUILT SURFACE PART WAYS, AND WHY THESE TESTS FOLLOW
 * THE BUILT ONE. The p3 spec names an app-local public API under
 * `/api/citizens/...`. None of it was ever routed (appinfo/routes.php has no
 * `citizens` route, and every task in the archived 2026-05-11 change is
 * unticked). The canonical citizen-participation spec later settled the
 * question the other way: "the only anonymous read path is OR/OpenCatalogi's
 * publication surface" (citizen-participation::no-app-local-public-surface),
 * and the citizen WRITE actions live under `/api/participation/...`. So:
 *
 *   - a scenario whose THEN is a visibility rule (public decisions are seen,
 *     internal ones are not) is asserted on the OpenRegister object API as an
 *     anonymous caller, where the register's public-group rules enforce it,
 *     and on the ORI feed;
 *   - a scenario whose THEN is a citizen action (submit, vote) is asserted on
 *     the `/api/participation` route that performs it;
 *   - a scenario whose THEN needs an app-local endpoint, filter or shape that
 *     was never built is NOT here. It is listed as unbuilt in the PR that
 *     added this file, with its evidence.
 *
 * Every actor is a real identity (see ../support/api-actors.ts): the citizen
 * is an account created for this run in no group, staff is the admin, and the
 * public is a context with no identity at all.
 */

import type { Actor } from '../support/api-actors.ts'

import { expect, test } from '@playwright/test'
import {
	adminActor,
	anonymousActor,
	APP_API,
	appPost,
	createObject,
	daysFromNow,
	describe as describeResponse,
	disposeActors,
	listObjects,
	newRunId,
	ObjectLedger,
	provisionAccount,
	readObject,
	removeAccounts,
	replaceObject,
	uuidOf,
} from '../support/api-actors.ts'

const RUN = newRunId()
const TAG = `e2e-p3-${RUN}`

/** Children before parents. */
const TEARDOWN = [
	'citizen-vote',
	'consultation-reaction',
	'budget-proposal',
	'participatory-budget',
	'public-consultation',
	'notification-preference',
	'decision',
]

let admin: Actor
let anon: Actor
let citizen: Actor
const ledger = new ObjectLedger(TEARDOWN)

test.beforeAll(async ({ playwright }) => {
	admin = await adminActor(playwright)
	anon = await anonymousActor(playwright)
	citizen = await provisionAccount(playwright, admin, `dq-p3-citizen-${RUN}`)
})

test.afterAll(async () => {
	// Objects the app wrote on the citizen's behalf never passed through the
	// ledger. They are found by the run-unique account they belong to, which
	// makes them this run's objects and nobody else's.
	for (const [schema, field] of [
		['citizen-vote', 'voterId'],
		['notification-preference', 'person'],
	]) {
		const { results } = await listObjects(admin, schema, { [field]: citizen.uid })
		for (const obj of results) {
			if (obj?.[field] === citizen.uid) {
				ledger.track(schema, uuidOf(obj))
			}
		}
	}
	await ledger.cleanup(admin)
	await removeAccounts(admin, [citizen.uid])
	await disposeActors(admin, anon, citizen)
})

/**
 * A participatory budget round, created by staff straight into a phase.
 *
 * @param fields Overrides.
 * @return The round's UUID.
 */
async function round(fields: Record<string, unknown> = {}): Promise<string> {
	const obj = await createObject(admin, ledger, 'participatory-budget', {
		currency: 'EUR',
		name: `${TAG}-round`,
		status: 'submission',
		submissionDeadline: daysFromNow(14),
		totalAmount: 1000,
		votingDeadline: daysFromNow(28),
		...fields,
	})
	return uuidOf(obj)
}

/**
 * Submit a proposal as the citizen, and track what the app created.
 *
 * @param budgetId The round.
 * @param title    The proposal title.
 * @param amount   The requested amount.
 * @return The raw response.
 */
async function submitProposal(budgetId: string, title: string, amount: number) {
	const resp = await appPost(citizen, `/participation/budgets/${budgetId}/proposals`, {
		amount,
		description: `${TAG} proposal body`,
		title,
	})
	if (resp.status() === 201) {
		ledger.track('budget-proposal', uuidOf((await resp.json()).proposal))
	}
	return resp
}

/**
 * The proposals staff can see under a title.
 *
 * @param title The proposal title to look for.
 * @return The matching proposals.
 */
async function proposalsTitled(title: string): Promise<any[]> {
	const { results } = await listObjects(admin, 'budget-proposal', { _search: title })
	return results.filter((p) => p?.title === title)
}

test.describe('Participatory budget: citizen proposals and votes', () => {
	// @e2e p3-citizen-participation::citizen-submits-a-valid-proposal
	test('a citizen submits a valid proposal and it is stored as submitted, under their name', async () => {
		const budgetId = await round()
		const title = `${TAG}-valid`

		const resp = await submitProposal(budgetId, title, 250)
		expect(resp.status(), `${citizen.uid} submitting: ${await describeResponse(resp)}`).toBe(201)

		const stored = await proposalsTitled(title)
		expect(stored, 'exactly one proposal was created').toHaveLength(1)
		expect(stored[0].status).toBe('submitted')
		expect(stored[0].requestedAmount).toBe(250)
		// The session's account, never a value the request could claim.
		expect(stored[0].submitter).toBe(citizen.uid)
	})

	// @e2e p3-citizen-participation::submission-rejected-after-deadline
	test('a proposal is refused with 400 once the deadline has passed or the round has left submission', async () => {
		const expired = await round({ submissionDeadline: daysFromNow(-1) })
		const lateTitle = `${TAG}-late`
		const late = await submitProposal(expired, lateTitle, 100)
		expect(late.status(), `after the deadline: ${await describeResponse(late)}`).toBe(400)
		expect((await late.json()).message).toBe('This budget round is not open for proposal submission')

		const voting = await round({ status: 'voting' })
		const wrongPhase = await submitProposal(voting, `${TAG}-wrong-phase`, 100)
		expect(wrongPhase.status(), `in the voting phase: ${await describeResponse(wrongPhase)}`).toBe(400)

		expect(await proposalsTitled(lateTitle), 'nothing was created').toHaveLength(0)
	})

	// @e2e p3-citizen-participation::oversized-proposal-rejected
	test('a proposal asking for more than the round holds is refused with 422 naming requestedAmount', async () => {
		const budgetId = await round({ totalAmount: 1000 })
		const title = `${TAG}-oversized`

		const resp = await submitProposal(budgetId, title, 5000)
		expect(resp.status(), await describeResponse(resp)).toBe(422)
		expect((await resp.json()).message).toContain('requestedAmount')
		expect(await proposalsTitled(title), 'nothing was created').toHaveLength(0)
	})

	// @e2e p3-citizen-participation::citizen-votes-on-a-proposal
	// @e2e p3-citizen-participation::vote-rejected-outside-voting-phase
	test('a citizen vote outside the voting phase is refused; inside it, it counts', async () => {
		const budgetId = await round()
		const title = `${TAG}-votable`

		// The proposal goes through the real intake, so its link to the round is
		// whatever the app writes, not whatever a fixture would.
		const submitted = await submitProposal(budgetId, title, 300)
		expect(submitted.status(), await describeResponse(submitted)).toBe(201)
		const proposalId = uuidOf((await submitted.json()).proposal)

		const validated = await appPost(admin, `/participation/proposals/${proposalId}/validate`, { approve: true })
		expect(validated.status(), `staff validating: ${await describeResponse(validated)}`).toBe(200)

		// Outside the voting phase: the round is still taking submissions.
		const early = await appPost(citizen, `/participation/proposals/${proposalId}/vote`, { value: 'voor' })
		expect(early.status(), `voting during submission: ${await describeResponse(early)}`).toBe(400)
		expect((await early.json()).message).toBe('Voting is closed for this budget round')
		expect((await proposalsTitled(title))[0].votesFor, 'the refused vote did not count').toBe(0)

		const opened = await appPost(admin, `/participation/budgets/${budgetId}/transition`, { status: 'voting' })
		expect(opened.status(), `staff opening voting: ${await describeResponse(opened)}`).toBe(200)

		const vote = await appPost(citizen, `/participation/proposals/${proposalId}/vote`, { value: 'voor' })
		expect(vote.status(), `${citizen.uid} voting: ${await describeResponse(vote)}`).toBe(201)
		expect((await vote.json()).votesFor).toBe(1)
		expect((await proposalsTitled(title))[0].votesFor, 'the stored tally moved').toBe(1)
	})
})

test.describe('Consultation feedback', () => {
	/**
	 * A consultation, created by staff straight into a state.
	 *
	 * @param fields Overrides.
	 * @return Its UUID.
	 */
	async function consultation(fields: Record<string, unknown> = {}): Promise<string> {
		const obj = await createObject(admin, ledger, 'public-consultation', {
			moderationPolicy: 'pre-moderation',
			status: 'open',
			submissionDeadline: daysFromNow(14),
			title: `${TAG}-consultation`,
			...fields,
		})
		return uuidOf(obj)
	}

	/**
	 * Submit a reaction as the citizen, and track it.
	 *
	 * @param consultationId The consultation.
	 * @param body           The reaction text.
	 * @return The raw response.
	 */
	async function react(consultationId: string, body: string) {
		const resp = await appPost(citizen, `/participation/consultations/${consultationId}/reactions`, { body })
		if (resp.status() === 201) {
			ledger.track('consultation-reaction', uuidOf((await resp.json()).reaction))
		}
		return resp
	}

	// @e2e p3-citizen-participation::feedback-submission-rejected-after-deadline
	test('feedback after the deadline is refused with 400 and a static message', async () => {
		const closedId = await consultation({ submissionDeadline: daysFromNow(-1) })
		const body = `${TAG} too late`

		const resp = await react(closedId, body)
		expect(resp.status(), await describeResponse(resp)).toBe(400)
		expect((await resp.json()).message).toBe('This consultation is not open for submissions')

		const { results } = await listObjects(admin, 'consultation-reaction', { _search: body })
		expect(results.filter((r) => r?.body === body), 'nothing was created').toHaveLength(0)
	})

	// @e2e p3-citizen-participation::published-feedback-visible-after-deadline
	test('feedback staff published is readable by the public; feedback they did not publish is not', async () => {
		const consultationId = await consultation()
		const publishedBody = `${TAG} published feedback`
		const heldBody = `${TAG} unpublished feedback`

		const published = await react(consultationId, publishedBody)
		const held = await react(consultationId, heldBody)
		expect(published.status(), await describeResponse(published)).toBe(201)
		expect(held.status(), await describeResponse(held)).toBe(201)
		const publishedId = uuidOf((await published.json()).reaction)
		const heldId = uuidOf((await held.json()).reaction)

		// Both approved, only one published: approval alone is not publication.
		for (const id of [publishedId, heldId]) {
			const approved = await appPost(admin, `/participation/reactions/${id}/approve`)
			expect(approved.status(), `approving ${id}: ${await describeResponse(approved)}`).toBe(200)
		}
		const publish = await appPost(admin, `/participation/reactions/${publishedId}/publish`)
		expect(publish.status(), `publishing: ${await describeResponse(publish)}`).toBe(200)

		const seen = await listObjects(anon, 'consultation-reaction', {
			'_relations.public-consultation': consultationId,
		})
		expect(seen.status, 'the public read is answered').toBe(200)
		const bodies = seen.results.map((r) => r?.body)
		expect(bodies).toContain(publishedBody)
		expect(bodies).not.toContain(heldBody)
	})
})

test.describe('Decision publication status on the public surfaces', () => {
	const ids: Record<string, string> = {}
	const term = `${TAG}-windmolens`

	test.beforeAll(async () => {
		for (const [key, isPublished] of [
			['public', 'public'],
			['internal', 'internal'],
			['confidential', 'confidential'],
			['unset', undefined],
		] as const) {
			const data: Record<string, unknown> = {
				decisionType: 'motion',
				text: `${term} motion text`,
				title: `${term} ${key}`,
			}
			if (isPublished !== undefined) {
				data.isPublished = isPublished
			}
			ids[key] = uuidOf(await createObject(admin, ledger, 'decision', data))
		}
	})

	/**
	 * The decision ids an anonymous search for this run's term returns.
	 *
	 * @return The status and the ids found.
	 */
	async function anonymousSearch(): Promise<{ status: number, found: string[] }> {
		const { status, results } = await listObjects(anon, 'decision', { _search: term })
		return { found: results.map(uuidOf), status }
	}

	/**
	 * The ORI motions feed, read anonymously.
	 *
	 * @return The decoded feed.
	 */
	async function oriMotions(): Promise<any> {
		const resp = await anon.ctx.get(`${APP_API}/ori/v1/motions`)
		expect(resp.status(), await describeResponse(resp)).toBe(200)
		return resp.json()
	}

	// @e2e p3-citizen-participation::anonymous-search-returns-only-public-decisions
	test('an anonymous search returns only the public decision matching the term', async () => {
		const { status, found } = await anonymousSearch()
		expect(status).toBe(200)
		expect(found).toEqual([ids.public])
	})

	// @e2e p3-citizen-participation::internal-decisions-excluded
	// @e2e p3-citizen-participation::internal-decision-hidden-from-citizens
	test('internal and confidential decisions, and one with no publication state, stay hidden from the public', async () => {
		const { found } = await anonymousSearch()
		const onFeed = (await oriMotions()).items.map((i: any) => i.id)

		for (const key of ['internal', 'confidential', 'unset']) {
			expect(found, `${key} is not in the anonymous search`).not.toContain(ids[key])
			expect(onFeed, `${key} is not on the ORI feed`).not.toContain(ids[key])
			const direct = await readObject(anon, 'decision', ids[key])
			expect(direct.status(), `${key} is not readable by id`).toBe(404)
		}
	})

	// @e2e p3-citizen-participation::public-decision-appears-in-transparency-portal
	// @e2e p3-citizen-participation::ori-decisions-endpoint-returns-public-decisions
	// @e2e p3-citizen-participation::non-public-decisions-excluded-from-ori-endpoint
	test('a public decision is served anonymously and on the ORI feed as JSON-LD; the others are not', async () => {
		const direct = await readObject(anon, 'decision', ids.public)
		expect(direct.status(), await describeResponse(direct)).toBe(200)

		const feed = await oriMotions()
		expect(feed['@type']).toBe('Motion')
		const item = feed.items.find((i: any) => i.id === ids.public)
		expect(item, 'the public motion is on the ORI feed').toBeTruthy()
		expect(item['@context']).toBeTruthy()
		expect(item['@type']).toBe('Motion')
		expect(item.name).toBe(`${term} public`)
		expect(item.text).toBe(`${term} motion text`)

		const onFeed = feed.items.map((i: any) => i.id)
		expect(onFeed).not.toContain(ids.internal)
		expect(onFeed).not.toContain(ids.confidential)
	})

	// @e2e p3-citizen-participation::existing-decisions-default-to-internal-on-upgrade
	test('a decision stored without a publication state reads as internal and staff keep working with it', async () => {
		const staffRead = await readObject(admin, 'decision', ids.unset)
		expect(staffRead.status(), await describeResponse(staffRead)).toBe(200)
		const stored = await staffRead.json()
		expect(stored.isPublished).toBe('internal')

		const edited = await replaceObject(admin, 'decision', ids.unset, {
			decisionType: 'motion',
			isPublished: stored.isPublished,
			text: stored.text,
			title: `${term} unset, edited`,
		})
		expect(edited.status(), `staff editing it: ${await describeResponse(edited)}`).toBe(200)
		expect((await anonymousSearch()).found).not.toContain(ids.unset)
	})

	// @e2e p3-citizen-participation::citizenvotingmethod-defaults-to-simple
	test('a motion opened to citizen voting without a method uses simple', async () => {
		const obj = await createObject(admin, ledger, 'decision', {
			citizenVotingAllowed: true,
			decisionType: 'motion',
			text: `${term} citizen vote`,
			title: `${term} citizen vote`,
		})
		const stored = await (await readObject(admin, 'decision', uuidOf(obj))).json()
		expect(stored.citizenVotingAllowed).toBe(true)
		expect(stored.citizenVotingMethod).toBe('simple')
	})
})

test.describe('Notification preferences', () => {
	// @e2e p3-citizen-participation::citizen-retrieves-preferences
	test('a citizen reads their own channel and per-type preferences; the public has none', async () => {
		const resp = await citizen.ctx.get(`${APP_API}/notification-preference`)
		expect(resp.status(), await describeResponse(resp)).toBe(200)
		const prefs = await resp.json()
		expect(['in-app', 'email', 'both']).toContain(prefs.deliveryMethod)
		expect(typeof prefs.votingOpened).toBe('boolean')
		expect(prefs.person).toBe(citizen.uid)

		const anonymous = await anon.ctx.get(`${APP_API}/notification-preference`)
		expect(anonymous.status(), await describeResponse(anonymous)).toBe(401)
	})
})

test.describe('Consultation types and the tender lifecycle', () => {
	/**
	 * Move a consultation to `status` the way the SPA's edit form saves it.
	 *
	 * @param id     The consultation.
	 * @param fields The whole object minus status.
	 * @param status The target status.
	 * @return The raw response.
	 */
	function advance(id: string, fields: Record<string, unknown>, status: string) {
		return replaceObject(admin, 'public-consultation', id, { ...fields, status })
	}

	// @e2e p3-citizen-participation::default-type-for-legacy-consultations
	test('a consultation stored without a type is a citizen-participation consultation', async () => {
		const title = `${TAG}-untyped`
		const id = uuidOf(await createObject(admin, ledger, 'public-consultation', { status: 'draft', title }))

		const stored = await (await readObject(admin, 'public-consultation', id)).json()
		expect(stored.consultationType).toBe('citizen-participation')

		// The hub's type filter is a server-side filter on that stored value.
		const { results } = await listObjects(admin, 'public-consultation', {
			_search: title,
			consultationType: 'citizen-participation',
		})
		expect(results.map(uuidOf)).toContain(id)
	})

	// @e2e p3-citizen-participation::tender-phase-progression
	test('staff move a tender through questions, submission and evaluation, one stored phase at a time', async () => {
		const fields = { consultationType: 'tender', title: `${TAG}-tender` }
		const id = uuidOf(await createObject(admin, ledger, 'public-consultation', { ...fields, status: 'published' }))

		// A jump over the phases is refused, so the steps below are the
		// lifecycle's doing and not an unguarded field write.
		const jump = await advance(id, fields, 'awarded')
		expect(jump.status(), `published -> awarded: ${await describeResponse(jump)}`).toBe(422)

		for (const status of ['questions', 'submission', 'evaluation']) {
			const step = await advance(id, fields, status)
			expect(step.status(), `-> ${status}: ${await describeResponse(step)}`).toBe(200)
			const stored = await (await readObject(admin, 'public-consultation', id)).json()
			expect(stored.status).toBe(status)
			// The stored phase is what the hub's status filter and badge read.
			const { results } = await listObjects(admin, 'public-consultation', { _search: fields.title, status })
			expect(results.map(uuidOf)).toContain(id)
		}
	})

	// @e2e p3-citizen-participation::award-is-recorded-as-a-decision-outcome
	test('recording the winning party on a tender in evaluation awards it', async () => {
		const fields = { consultationType: 'tender', title: `${TAG}-award` }
		const id = uuidOf(await createObject(admin, ledger, 'public-consultation', { ...fields, status: 'submission' }))
		const toEvaluation = await advance(id, fields, 'evaluation')
		expect(toEvaluation.status(), await describeResponse(toEvaluation)).toBe(200)

		const awarded = await advance(id, { ...fields, awardedTo: `${TAG} Bidder BV` }, 'awarded')
		expect(awarded.status(), await describeResponse(awarded)).toBe(200)

		const stored = await (await readObject(admin, 'public-consultation', id)).json()
		expect(stored.awardedTo).toBe(`${TAG} Bidder BV`)
		expect(stored.status).toBe('awarded')
	})
})
