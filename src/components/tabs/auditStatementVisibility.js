/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers behind MeetingAuditStatementTab.vue (meeting-facet-composition,
 * REQ-MDV-012): the assoc-mode visibility gate and the CnObjectListWidget
 * content blob for the audit-statement facet.
 *
 * Kept in a plain .js module (not the .vue SFC) specifically so Vitest can
 * import it — this repo's vitest.config.js runs on plain Vite with no
 * @vitejs/plugin-vue registered, so a .vue file cannot be imported by a
 * Vitest spec (confirmed empirically, see tests/vitest/registerDetailWidgets.spec.js
 * and tests/vitest/ensureRelationType.spec.js, which use the same pattern —
 * logic lives in an importable .js module the .vue component then calls).
 *
 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-audit-statements-facet-assoc-mode-only
 */

/** The one organisatie_modus value that shows this facet (VvE/association tenants). */
export const ASSOC_MODE = 'assoc'

/**
 * Whether the kascommissie facet should render for the given organisatie_modus.
 * No manifest widget primitive can gate a `widgets[]` entry's visibility on a
 * settings value today (`visibleWhen` only exists for `headerActions` / form
 * fields — verified by source search of @conduction/nextcloud-vue, zero
 * hits), so this decision is made in code (design.md Decision 3).
 *
 * @param {string} organisatieModus The tenant's active organisatie_modus setting.
 * @return {boolean} True only in association ('assoc') mode.
 * @spec openspec/specs/meeting-detail-view/spec.md#scenario-audit-statement-facet-hidden-outside-association-mode
 */
export function isAuditStatementVisible(organisatieModus) {
	return organisatieModus === ASSOC_MODE
}

/**
 * The CnObjectListWidget `content` blob for the kascommissie facet. Scoped
 * to the current meeting's own governanceBody via the `@object.governanceBody`
 * filter token — CnObjectListWidget resolves it from the `cnObjectContext`
 * inject the same way `Post`'s `x-relation-filter` does
 * (lib/Settings/decidesk_register.json:1817-1819). Read-only
 * (`allowCreate: false`): AuditStatement's `required` list
 * (financialYear, verdict, governanceBody) has no meeting-shaped field — a
 * verklaring is prepared by the kascommissie independently of any specific
 * meeting — so no in-context create affordance fits here.
 *
 * @return {object} The widget content blob passed to CnObjectListWidget.
 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-audit-statements-facet-assoc-mode-only
 */
export function auditStatementContent() {
	return {
		register: 'decidiq',
		schema: 'audit-statement',
		filter: { governanceBody: '@object.governanceBody' },
		sort: { field: 'financialYear', dir: 'desc' },
		columns: [
			{ key: 'financialYear', label: 'Financial year' },
			{ key: 'verdict', label: 'Verdict', widget: 'badge' },
		],
		limit: 10,
		allowCreate: false,
		viewAllRoute: 'AuditStatements',
		viewAllQuery: { governanceBody: '@object.governanceBody' },
		rowRoute: 'AuditStatementDetail',
		emptyText: 'No kascommissie statements for this association yet.',
	}
}
