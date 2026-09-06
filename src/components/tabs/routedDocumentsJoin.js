/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure join/merge helpers behind MeetingRoutedDocumentsTab.vue
 * (meeting-facet-composition, REQ-MDV-013): the two-hop "routed to this
 * meeting's agenda" join no declarative object-list `filter` can express
 * (design.md Decision 4) — Meeting → its own AgendaItems → raadsinformatiebrief
 * (`agendaItem`) / ingekomen-stuk (`targetAgendaItem` OR `listAgendaItem`).
 *
 * Kept in a plain .js module so Vitest can import it — see
 * auditStatementVisibility.js for why a .vue SFC is unimportable in this
 * repo's vitest.config.js (no @vitejs/plugin-vue registered).
 *
 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-013-routed-incoming-documents-facet-read-only
 */

/**
 * Collect the (non-empty, string) ids of a meeting's own agenda-item objects.
 *
 * @param {Array<object>} agendaItems Fetched agenda-item objects.
 * @return {Array<string>} Their ids (id falling back to uuid).
 */
export function collectAgendaItemIds(agendaItems) {
	if (!Array.isArray(agendaItems)) return []
	return agendaItems
		.map((item) => item && (item.id || item.uuid))
		.filter((id) => typeof id === 'string' && id.length > 0)
}

/**
 * Filter a set of ingekomen-stuk objects down to those routed onto one of
 * the given agenda-item ids, via EITHER `targetAgendaItem` (merged into a
 * substantive agenda item's discussion) OR `listAgendaItem` (placed on the
 * "en bloc" ingekomen-stukken-lijst hamerstuk) — both are legitimate
 * "routed to this meeting" signals (design.md Decision 4). No single
 * OpenRegister query can express this two-field OR, so the ingekomen-stuk
 * fetch itself is unscoped and this membership check runs client-side
 * (bounded: the meeting's own agenda-item count, typically < 30 — the
 * NFR in spec.md explicitly allows this for the two-hop facet only).
 *
 * @param {Array<object>} ingekomenStukken Fetched ingekomen-stuk objects.
 * @param {Array<string>} agendaItemIds The meeting's own agenda-item ids.
 * @return {Array<object>} Only the objects routed to one of those ids.
 * @spec openspec/specs/meeting-detail-view/spec.md#scenario-documents-routed-onto-the-meetings-agenda
 */
export function filterRoutedIngekomenStukken(ingekomenStukken, agendaItemIds) {
	if (!Array.isArray(ingekomenStukken)) return []
	const ids = new Set(Array.isArray(agendaItemIds) ? agendaItemIds : [])
	return ingekomenStukken.filter((stuk) => {
		if (!stuk) return false
		return ids.has(stuk.targetAgendaItem) || ids.has(stuk.listAgendaItem)
	})
}

/** Detail route name per row `type`, for row-click navigation. */
export const ROUTE_BY_TYPE = {
	raadsinformatiebrief: 'RaadsinformatiebriefDetail',
	'ingekomen-stuk': 'IngekomenStukDetail',
}

/**
 * Merge routed raadsinformatiebrief + ingekomen-stuk objects into one row
 * set for the combined read-only table. Normalises each schema's own
 * "what is this called" field (raadsinformatiebrief has `subject`,
 * ingekomen-stuk has `title` — neither schema declares the other's field)
 * into a single `title` column, and tags each row with its own `type` +
 * human `typeLabel` so one CnDataTable can render and route both kinds.
 *
 * @param {Array<object>} raadsinformatiebrieven Routed raadsinformatiebrief objects
 *   (already server-filtered by `agendaItem` membership).
 * @param {Array<object>} ingekomenStukken Routed ingekomen-stuk objects (already
 *   passed through {@link filterRoutedIngekomenStukken}).
 * @return {Array<object>} Combined, normalised rows for CnDataTable.
 * @spec openspec/specs/meeting-detail-view/spec.md#scenario-documents-routed-onto-the-meetings-agenda
 */
export function buildRoutedDocumentRows(raadsinformatiebrieven, ingekomenStukken) {
	const ribRows = (
		Array.isArray(raadsinformatiebrieven) ? raadsinformatiebrieven : []
	).map((rib) => ({
		id: rib.id || rib.uuid,
		type: 'raadsinformatiebrief',
		typeLabel: 'Raadsinformatiebrief',
		title: rib.subject || '',
		category: rib.category || '',
		lifecycle: rib.lifecycle || '',
	}))
	const stukRows = (Array.isArray(ingekomenStukken) ? ingekomenStukken : []).map(
		(stuk) => ({
			id: stuk.id || stuk.uuid,
			type: 'ingekomen-stuk',
			typeLabel: 'Ingekomen stuk',
			title: stuk.title || '',
			category: stuk.category || '',
			lifecycle: stuk.lifecycle || '',
		}),
	)
	return [...ribRows, ...stukRows]
}
