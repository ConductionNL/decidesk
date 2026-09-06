<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: motions tied to an Agenda Item.

 Posture: full CRUD. Agenda items own their motions; this tab fetches
 motions whose `agendaItem === parent.id`, lists them with lifecycle
 badges, and lets users add / edit / delete inline. Lifecycle
 transitions and voting still live on /motions/:id (see MotionDetail
 page) — this tab is the structural CRUD surface only.
-->
<template>
	<div class="decidiq-tab decidiq-tab--motions" data-testid="agenda-motions-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Motions') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="agenda-motions-add"
				:aria-label="t('decidiq', 'Add motion')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Add motion') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load motions')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No motions for this agenda item yet.')"
			:loadingText="t('decidiq', 'Loading motions…')"
			@rowClick="openEdit">
			<template #column-lifecycle="{ value }">
				<CnStatusBadge
					v-if="value"
					:label="value"
					:colorMap="lifecycleColors" />
			</template>
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<CnFormDialog
			v-if="formOpen"
			ref="formDialog"
			:schema="motionSchema"
			:item="editTarget"
			:dialogTitle="
				editTarget ? t('decidiq', 'Edit motion') : t('decidiq', 'Add motion')
			"
			:excludeFields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			nameField="title"
			:dialogTitle="t('decidiq', 'Delete motion')"
			@confirm="confirmDelete"
			@close="deleteTarget = null" />
	</div>
</template>

<script>
import {
	CnDataTable,
	CnDeleteDialog,
	CnFormDialog,
	CnNoteCard,
	CnRowActions,
	CnStatusBadge,
} from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { DECISION_LIFECYCLE_COLORS } from '../../constants/decisionLifecycle.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'AgendaMotionsTab',
	components: {
		CnDataTable,
		CnDeleteDialog,
		CnFormDialog,
		CnNoteCard,
		CnRowActions,
		CnStatusBadge,
		NcButton,
		Plus,
	},

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			motionSchema: null,
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
		}
	},

	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'title', label: this.t('decidiq', 'Title') },
				{ key: 'proposer', label: this.t('decidiq', 'Proposer') },
				{ key: 'lifecycle', label: this.t('decidiq', 'Status') },
			]
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		lifecycleColors() {
			return DECISION_LIFECYCLE_COLORS
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rowActions() {
			return [
				{
					label: this.t('decidiq', 'Edit'),
					icon: Pencil,
					handler: (row) => this.openEdit(row),
				},
				{
					label: this.t('decidiq', 'Delete'),
					icon: TrashCanOutline,
					destructive: true,
					handler: (row) => {
						this.deleteTarget = { ...row }
					},
				},
			]
		},

		/**
		 * `decisionType` is excluded because it is this tab's DISCRIMINATOR,
		 * not a choice: every row here is a Decision of decisionType=motion
		 * (the fetch filters on it) and onConfirm writes the value itself.
		 * Leaving it on the form showed an EMPTY required picker —
		 * decision-types-as-configuration (#1099) dropped the enum from the
		 * stored schema, so the select had no options and blocked the save.
		 *
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		excludedFields() {
			return ['id', 'uuid', 'agendaItem', 'decisionType', 'created', 'updated']
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/relation-tab-ui/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('motion')
				if (!this.motionSchema)
					this.motionSchema = await store.fetchSchema('motion')
				const items = await store.fetchCollection('motion', {
					decisionType: 'motion',
					agendaItem: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load motions.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openCreate() {
			const store = ensureRelationType('motion')
			if (!this.motionSchema)
				this.motionSchema = await store.fetchSchema('motion')
			this.editTarget = null
			this.formOpen = true
		},

		/**
		 * @param row The decision row to edit.
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async openEdit(row) {
			const store = ensureRelationType('motion')
			if (!this.motionSchema)
				this.motionSchema = await store.fetchSchema('motion')
			this.editTarget = { ...row }
			this.formOpen = true
		},

		/**
		 * @param formData The submitted form values.
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async onConfirm(formData) {
			const store = ensureRelationType('motion')
			try {
				// The discriminator is written HERE, not picked on the form —
				// see excludedFields. Without this, a create carried no
				// decisionType at all and the row fell out of this tab's own
				// decisionType=motion filter on the next refresh.
				await store.saveObject('motion', {
					...formData,
					decisionType: 'motion',
					agendaItem: this.objectId,
				})
				this.$refs.formDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.formDialog?.setResult({
					error: e?.message || this.t('decidiq', 'Save failed.'),
				})
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async confirmDelete() {
			const store = ensureRelationType('motion')
			try {
				await store.deleteObject('motion', this.deleteTarget.id)
				this.$refs.deleteDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.deleteDialog?.setResult({
					error: e?.message || this.t('decidiq', 'Delete failed.'),
				})
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
</style>
