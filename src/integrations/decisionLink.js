// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared helpers for the "Besluitvorming" (decisions) integration leaf.
//
// The leaf surfaces a host object's decidiq decisions — proposals,
// advice and final decisions — on ANY consuming object's detail page or
// sidebar (ADR-022 / ADR-019). It is deliberately generic: it reads the
// host object identity from the registry-supplied integration context
// ({ register, schema, objectId }) and never hard-codes procest.
//
// Case-linking mechanism: decidiq's Decision schema already carries the
// back-reference fields raised by the contract-decision hub
// (decidesk-contract-decision-hub, REQ-DCDH-001): `subjectRegister`,
// `subjectSchema`, `subjectId`, `subjectLabel`, `sourceApp` and
// `externalReference`. A decision linked to a host object stores that
// object's UUID in `subjectId`; this leaf lists decisions filtered on
// `subjectId == objectId`. No new schema field is needed.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The decidiq logical object type backing the leaf. Registered against
 * the shared object store the first time the leaf renders (the store
 * may not have been booted by decidiq's own main.js when the leaf is
 * mounted inside a foreign app's page, e.g. a procest case detail).
 *
 * @type {string}
 */
export const DECISION_TYPE = 'decision'

/**
 * decidiq `decisionType` discriminator values grouped into the three
 * presentation buckets the leaf renders (Voorstellen / Adviezen /
 * Besluiten). `meeting-outcome` and anything unmatched fall through to
 * "Besluiten" (the catch-all final-decision bucket).
 *
 * @type {{ proposals: string[], advice: string[] }}
 */
export const KIND_GROUPS = {
	proposals: ['motion', 'amendment', 'policy', 'management-point'],
	advice: ['report-adoption', 'appointment', 'advice'],
}

/**
 * Lifecycle states that read as "still a proposal" (not yet a binding
 * decision) — used to label a decision as a draft proposal in the UI.
 *
 * @type {string[]}
 */
export const PROPOSAL_LIFECYCLE = ['draft', 'proposed', 'deliberating', 'voting']

/**
 * OpenRegister objects endpoint for decidiq `decision` objects.
 *
 * @return {string} The resolved `/apps/openregister/api/objects/decidiq/decision` URL.
 */
function decisionsUrl() {
	return generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
		register: 'decidiq',
		schema: 'decision',
	})
}

/**
 * List decidiq decisions linked to a host object, via the shared
 * OpenRegister objects API — NOT a Pinia store.
 *
 * The leaf is rendered inline on ANY app's detail page (a pipelinq lead, a
 * procest case, …). It therefore must not depend on decidiq's own Pinia
 * object store: that store lives in decidiq's bundle and its `getActivePinia`
 * is not the host app's active Pinia, so `useObjectStore()` threw
 * `reading '_s' of undefined` when hosted in a foreign app (ADR-019: an
 * integration leaf must be host-agnostic). A direct API call needs no store.
 *
 * @param {string} subjectId The host object's UUID (`subjectId` filter).
 * @param {number} [limit]   Max rows (default 100).
 *
 * @return {Promise<object[]>} The decision objects, or `[]`.
 */
export async function listHostDecisions(subjectId, limit = 100) {
	if (!subjectId) return []
	const res = await axios.get(decisionsUrl(), {
		params: { subjectId, _limit: limit },
	})
	const data = res && res.data
	if (Array.isArray(data)) return data
	if (data && Array.isArray(data.results)) return data.results
	return []
}

/**
 * Create a decidiq decision pre-linked to a host object, via the shared
 * OpenRegister objects API (see {@link listHostDecisions} for why not a store).
 *
 * @param {object} payload The decision object to persist (already carrying the
 *                         `subject*` back-reference fields).
 *
 * @return {Promise<object|null>} The created object, or null.
 */
export async function createHostDecision(payload) {
	const res = await axios.post(decisionsUrl(), payload)
	return (res && res.data) || null
}

/**
 * The shipped decision-type seed, mirrored from
 * `DecisionTypeRegistry::DEFAULT_TYPES`. NOT a second authority: the registry
 * (app-config `decision_types`) decides, and this list only carries the
 * picker through the window before {@link listDecisionTypes} has answered —
 * or when it cannot answer at all. Failing back to the seed keeps the
 * create-proposal form usable on a broken network, at the cost of admin-added
 * types until the endpoint is reachable again.
 *
 * @type {string[]}
 */
export const FALLBACK_DECISION_TYPES = [
	'motion',
	'amendment',
	'resolution',
	'contract',
	'contract-renewal',
	'report-adoption',
	'appointment',
	'management-point',
	'policy',
	'meeting-outcome',
	'advice',
	'bezwaar-decision',
	'woo-decision',
]

/**
 * List the configured decisionType vocabulary from the registry endpoint.
 *
 * The pickers used to hardcode five types, so a type an administrator added
 * to the `decision_types` app config validated fine at the write path and
 * never appeared in any picker. This asks the registry itself, falling back
 * to the shipped seed when the endpoint is unreachable.
 *
 * @return {Promise<string[]>} The configured types, or the shipped seed.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
export async function listDecisionTypes() {
	try {
		const res = await axios.get(
			generateUrl('/apps/decidiq/api/v1/decision-types'),
		)
		const types = res?.data?.types
		if (Array.isArray(types)) {
			const clean = types.filter(
				(v) => typeof v === 'string' && v.trim() !== '',
			)
			if (clean.length > 0) {
				return clean
			}
		}
	} catch {
		// Fall through to the seed: an unreachable registry should degrade the
		// picker to the shipped vocabulary, never block creating a proposal.
	}
	return FALLBACK_DECISION_TYPES
}

/**
 * Display labels for the shipped decision types, keyed by raw value.
 *
 * An admin-added type has no label here and renders as its own slug — the
 * registry stores values, not labels, so the slug IS its name.
 *
 * @return {Object<string, string>} Raw type value to translated label.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
export function decisionTypeLabels() {
	return {
		motion: t('decidiq', 'Motion'),
		amendment: t('decidiq', 'Amendment'),
		resolution: t('decidiq', 'Resolution'),
		contract: t('decidiq', 'Contract'),
		'contract-renewal': t('decidiq', 'Contract renewal'),
		'report-adoption': t('decidiq', 'Report adoption'),
		appointment: t('decidiq', 'Appointment'),
		'management-point': t('decidiq', 'Management point'),
		policy: t('decidiq', 'Policy'),
		'meeting-outcome': t('decidiq', 'Meeting outcome'),
		advice: t('decidiq', 'Advice'),
		'bezwaar-decision': t('decidiq', 'Objection decision'),
		'woo-decision': t('decidiq', 'Woo decision'),
	}
}

/**
 * The create-proposal form schema, over the given decision types.
 *
 * Shared by CnDecisionsTab and CnDecisionsWidget so the two pickers cannot
 * drift. `motion` stays the default when the vocabulary carries it; a
 * vocabulary without it defaults to its own first type.
 *
 * @param {string[]} types The decisionType vocabulary to offer.
 *
 * @return {object} A CnFormDialog schema.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal form schema.
 */
export function proposalFormSchema(types) {
	const offered =
		Array.isArray(types) && types.length > 0 ? types : FALLBACK_DECISION_TYPES
	return {
		title: t('decidiq', 'Proposal'),
		properties: {
			title: { type: 'string', title: t('decidiq', 'Title') },
			text: {
				type: 'string',
				title: t('decidiq', 'Rationale'),
				widget: 'textarea',
			},

			decisionType: {
				type: 'string',
				title: t('decidiq', 'Type'),
				enum: offered,
				enumLabels: decisionTypeLabels(),
				default: offered.includes('motion') ? 'motion' : offered[0],
			},
		},

		required: ['title'],
	}
}

/**
 * Inject the registry's decisionType vocabulary into an OpenRegister
 * decision schema.
 *
 * decision-types-as-configuration (#1099) deliberately dropped the `enum`
 * from the stored schema declaration — the `decision_types` app config is
 * the only authority. That left every schema-driven form (the built-in
 * create/edit dialog on the Decisions and Motions index pages) rendering an
 * empty type picker: the select widget reads `properties.decisionType.enum`
 * and found nothing. This helper closes the gap the same way the cross-app
 * pickers were closed in #1104: the vocabulary comes from
 * {@link listDecisionTypes} (registry endpoint, seed fallback) and gets
 * spliced into the schema right before the form renders. The schema on the
 * SERVER stays enum-free; only the client-side copy driving the picker is
 * enriched.
 *
 * @param {object} schema The OpenRegister decision schema (as handed to the
 *                        form dialog).
 * @param {?string[]} types The registry vocabulary, or null while it loads —
 *                          the shipped seed fills in.
 *
 * @return {object} A shallow clone with `properties.decisionType` carrying
 *                  the vocabulary as `enum` + translated `enumLabels`, or
 *                  the input untouched when it has no decisionType property.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
export function withDecisionTypeVocabulary(schema, types) {
	if (!schema || typeof schema !== 'object') return schema
	const properties = schema.properties
	if (!properties || typeof properties !== 'object' || !properties.decisionType) {
		return schema
	}
	const offered =
		Array.isArray(types) && types.length > 0 ? types : FALLBACK_DECISION_TYPES
	return {
		...schema,
		properties: {
			...properties,
			decisionType: {
				...properties.decisionType,
				enum: offered,
				enumLabels: decisionTypeLabels(),
			},
		},
	}
}

/**
 * Classify a decision into one of the three presentation buckets.
 *
 * @param {object} decision A decidiq Decision object.
 *
 * @return {'proposals'|'advice'|'decisions'} The bucket key.
 */
export function decisionBucket(decision) {
	const type = String(decision?.decisionType ?? decision?.data?.decisionType ?? '')
	if (KIND_GROUPS.proposals.includes(type)) {
		return 'proposals'
	}
	if (KIND_GROUPS.advice.includes(type)) {
		return 'advice'
	}
	return 'decisions'
}

/**
 * Whether the decision is still in a proposal lifecycle state.
 *
 * @param {object} decision A decidiq Decision object.
 *
 * @return {boolean} True when lifecycle reads as a draft proposal.
 */
export function isProposal(decision) {
	const lifecycle = String(
		decision?.lifecycle ?? decision?.data?.lifecycle ?? 'draft',
	)
	return PROPOSAL_LIFECYCLE.includes(lifecycle)
}

/**
 * Resolve a stable object id from an OR object (id or @self.uuid).
 *
 * @param {object} obj An OR object.
 *
 * @return {string} The id, or '' when absent.
 */
export function objId(obj) {
	return String(
		obj?.id ?? obj?.uuid ?? obj?.['@self']?.id ?? obj?.['@self']?.uuid ?? '',
	)
}
