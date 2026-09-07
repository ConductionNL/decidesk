<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: schema-driven create/edit form for Decision objects, with the
 decisionType picker fed from the registry.

 This is a manifest `form-dialog` slot replacement for every decidiq
 surface that renders the Decision schema in a form: the Decisions and
 Motions INDEX pages, and the Decision, Motion, Amendment and Decision
 integrations DETAIL pages (wired via each page's `slots` map in
 src/manifest.json). CnIndexPage and CnDetailPage deliberately name the
 slot the same and scope it the same, so one component serves both.
 The built-in dialog those pages otherwise render
 builds its type picker from `properties.decisionType.enum` in the stored
 schema — and decision-types-as-configuration (#1099) deliberately
 emptied that enum, making the `decision_types` app config the only
 authority. The built-in picker therefore showed "No results" while the
 cross-app pickers (CnDecisionsTab / CnDecisionsWidget, fixed in #1104)
 listed the registry's types correctly.

 The wrapper renders the exact same CnFormDialog over the exact same
 schema; the one difference is that the schema copy driving the form is
 enriched with the registry vocabulary through the shared
 withDecisionTypeVocabulary() helper — same fetch, same seeded-13
 fallback, same translated labels as the #1104 surfaces, so the two
 wirings cannot drift. `confirm` and `close` arrive as PROPS (not
 listeners) per the CnIndexPage form-dialog slot contract: a
 manifest-declared replacement is mounted with `v-bind="slotProps"`
 only, so saving goes through the page's own persistence path.

 @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
-->
<template>
	<CnFormDialog
		v-if="show"
		ref="dialog"
		:schema="typedSchema"
		:item="item"
		register="decidiq"
		@confirm="onConfirm"
		@close="close" />
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import {
	listDecisionTypes,
	withDecisionTypeVocabulary,
} from '../integrations/decisionLink.js'
import { settleFormDialogResult } from './formDialogResult.js'

export default {
	name: 'DecisionFormDialog',

	components: {
		CnFormDialog,
	},

	props: {
		/** Whether the page currently shows the form dialog (slot contract). */
		show: { type: Boolean, default: false },
		/** The item being edited, or null in create mode (slot contract). */
		item: { type: Object, default: null },
		/** The effective JSON schema driving the form (slot contract). */
		schema: { type: Object, default: null },
		/**
		 * Persists the form data through the page's own save path (slot
		 * contract — bound as a prop so a manifest-declared replacement
		 * can reach it).
		 */
		confirm: { type: Function, required: true },
		/** Closes the form dialog (slot contract). */
		close: { type: Function, required: true },
	},

	data() {
		return {
			/**
			 * The registry's decisionType vocabulary, or null until the
			 * fetch answers — withDecisionTypeVocabulary() falls back to
			 * the shipped seed in the meantime, so the picker is never
			 * empty.
			 */
			decisionTypes: null,
		}
	},

	computed: {
		/**
		 * The form schema with the registry vocabulary spliced into
		 * `properties.decisionType`.
		 *
		 * @return {object} The enriched schema.
		 *
		 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
		 */
		typedSchema() {
			return withDecisionTypeVocabulary(this.schema, this.decisionTypes)
		},
	},

	/**
	 * Fetch the registry vocabulary once. The slot component mounts with
	 * the page (before the dialog first opens), so the answer is normally
	 * in place by the time anyone clicks Add.
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	async mounted() {
		this.decisionTypes = await listDecisionTypes()
	},

	methods: {
		/**
		 * Save through the page's own persistence path, then hand the
		 * outcome back to the dialog that submitted it.
		 *
		 * The result matters because this dialog is ours, not the page's:
		 * CnFormDialog raises `loading` on submit and only `setResult()`
		 * lowers it, with `no-close` bound to `loading`. On CnDetailPage a
		 * failed edit leaves the form open, so dropping the result would
		 * strand the user in a modal that can neither retry nor close.
		 * CnIndexPage's `confirm` resolves to nothing and closes the dialog
		 * by flipping `show` instead, which settleFormDialogResult() treats
		 * as a normal outcome rather than a fault, so this one component
		 * still serves both pages.
		 *
		 * @param {object} formData The submitted form data.
		 * @param {?object} extra CnFormDialog's second confirm argument
		 *   (extension answers), passed through untouched.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
		 */
		async onConfirm(formData, extra) {
			const result = await this.confirm(formData, extra)
			settleFormDialogResult(this.$refs.dialog, result)
		},
	},
}
</script>
