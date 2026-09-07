<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting-scoped facet: kascommissie verklaringen (VvE/association mode
 only) — meeting-facet-composition, REQ-MDV-012.

 Thin wrapper around the shared CnObjectListWidget (design.md Decision 3):
 the join itself (AuditStatement.governanceBody == this meeting's
 own governanceBody) is expressed declaratively via the `@object.governanceBody`
 filter token CnObjectListWidget already resolves from the CnDetailPage
 `cnObjectContext` inject — this component's only job is the mode gate no
 manifest primitive can express (`visibleWhen` exists for headerActions /
 form fields, never for a `widgets[]` entry; zero hits searching
 @conduction/nextcloud-vue's source).

 Hidden (not deleted): outside `assoc` mode this component renders nothing
 at all, so the grid cell the manifest still allocates for it just stays
 empty — no orphaned ARIA region, no focus trap (spec.md non-functional
 requirements).

 @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-audit-statements-facet-assoc-mode-only
-->
<template>
	<div
		v-if="visible"
		class="decidiq-tab decidiq-tab--kascommissie"
		data-testid="meeting-audit-statements-tab">
		<CnObjectListWidget :content="content" />
	</div>
</template>

<script>
import { CnObjectListWidget } from '@conduction/nextcloud-vue'
import { DEFAULT_MODE } from '../../config/modeLabels.js'
import { useSettingsStore } from '../../store/store.js'
import {
	auditStatementContent,
	isAuditStatementVisible,
} from './auditStatementVisibility.js'

export default {
	name: 'MeetingAuditStatementTab',

	components: { CnObjectListWidget },

	computed: {
		/**
		 * Active organisatie_modus from the settings store, same pattern as
		 * src/App.vue's `organisatieModus`. Defaults to DEFAULT_MODE ('gov')
		 * when not yet configured.
		 *
		 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-audit-statements-facet-assoc-mode-only
		 * @return {string}
		 */
		organisatieModus() {
			const settingsStore = useSettingsStore()
			return settingsStore.getSettings?.organisatie_modus || DEFAULT_MODE
		},

		/**
		 * Whether the facet renders at all — assoc mode only.
		 *
		 * @spec openspec/specs/meeting-detail-view/spec.md#scenario-audit-statement-facet-hidden-outside-association-mode
		 * @return {boolean}
		 */
		visible() {
			return isAuditStatementVisible(this.organisatieModus)
		},

		/**
		 * The CnObjectListWidget content blob for this facet.
		 *
		 * @spec openspec/specs/meeting-detail-view/spec.md#scenario-audit-statement-facet-visible-in-association-mode
		 * @return {object}
		 */
		content() {
			return auditStatementContent()
		},
	},
}
</script>
