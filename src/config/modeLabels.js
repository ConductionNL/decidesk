// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Declarative mode-label map for the organisatie_modus mechanism.
//
// Shape: mode → canonical menu id / label → i18n key to resolve via t().
// Keys are English source strings (i18n convention: English = key, per ADR-005).
// Adding a new mode or relabeling an item is a data edit here only — no nav
// branching needed (ADR-004 Rule 1, ADR-006 §Decision mechanism 1).
//
// C7 scope: wire the Bodies item across all 5 modes; scaffold remaining items
// with their canonical English labels so a follow-up change only fills in rows.
//
// @spec openspec/specs/app-navigation/spec.md#requirement-req-nav-006-mode-aware-label-resolution-at-the-translate-chokepoint

/**
 * Default organisatie_modus when none is configured.
 *
 * @type {string}
 */
export const DEFAULT_MODE = 'gov'

/**
 * Declarative map: organisatie_modus → canonical label → i18n key.
 *
 * Each inner object maps the canonical menu label (as used in manifest.json)
 * to the translated label key that should be passed to t('decidiq', …).
 * When a canonical label is absent from a mode's map the canonical label
 * itself is used as the t() key, which resolves to the standard l10n string.
 *
 * @type {Object<string, Object<string, string>>}
 */
export const MODE_LABELS = {
	/**
	 * Government / municipal mode (default).
	 * Organisation (canonical label since ia-six-clusters; menu id GovernanceBodies) = Organen.
	 * Decisions = Besluiten, Meetings = Vergaderingen.
	 *
	 * ONE CONCEPT, ONE LABEL (configurable-types-domain-model REQ-CTM-010).
	 * This read 'Factions & bodies', which invited the reader to believe decidiq
	 * models two kinds of thing. It does not: a faction IS a GovernanceBody with
	 * bodyType 'faction' — checked against the register, where the two seeded
	 * factions are ordinary GovernanceBody objects with parentBody set and no
	 * second schema exists. The duplication was only ever in this string.
	 */
	gov: {
		Organisation: 'Bodies',
		// Scaffold — canonical labels fall through to standard l10n:
		// Meetings    → 'Meetings'    (resolved as 'Vergaderingen' by nl_NL)
		// Decisions   → 'Decisions'   (resolved as 'Besluiten' by nl_NL)
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},

	/**
	 * Corporate / board mode.
	 * Bodies = Board, Decisions = Resolutions.
	 */
	corp: {
		Organisation: 'Board',
		Decisions: 'Resolutions',
		// Scaffold:
		// Meetings    → 'Meetings'
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},

	/**
	 * Association / member-organisation mode.
	 * Bodies = Organen. Same one-concept rule as gov: a committee is a
	 * GovernanceBody with bodyType 'advisory-body', not a sibling concept.
	 */
	assoc: {
		Organisation: 'Bodies',
		// An audit statement is the generic record; a VvE or vereniging calls
		// the committee that files it the kascommissie. THIS is where that word
		// belongs — in the per-mode label map — rather than in the schema name,
		// which is why generic-audit-statement could rename the schema without
		// taking the Dutch term away from the people who use it.
		'Audit statements': 'Kascommissie verklaringen',
		'Audit statement': 'Kascommissie verklaring',
		// Scaffold:
		// Meetings    → 'Meetings'
		// Decisions   → 'Decisions'
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},

	/**
	 * Operations / internal-team mode.
	 * Bodies = Teams.
	 */
	ops: {
		Organisation: 'Teams',
		// Scaffold:
		// Meetings    → 'Meetings'
		// Decisions   → 'Decisions'
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},

	/**
	 * Citizen portal mode.
	 * No relabeling of Organisation; canonical label used.
	 */
	citizen: {
		Organisation: 'Organisation',
		// Scaffold:
		// Meetings    → 'Meetings'
		// Decisions   → 'Decisions'
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},
}
