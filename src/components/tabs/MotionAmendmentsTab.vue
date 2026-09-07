<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: amendments proposed against a Motion.

 Posture: full CRUD. Motions own their amendments via `parentMotion`;
 this tab fetches amendments where `parentMotion === parent.id`,
 lists them with lifecycle badges, and lets users add / edit / delete.
 Lifecycle transitions, voting, and conflict warnings remain on the
 standalone /amendments/:id detail page.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--amendments"
		data-testid="motion-amendments-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Amendments') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="motion-amendments-add"
				:aria-label="t('decidiq', 'Submit amendment')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Submit amendment') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load amendments')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No amendments for this motion yet.')"
			:loadingText="t('decidiq', 'Loading amendments…')"
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
			:schema="amendmentSchema"
			:item="editTarget"
			:dialogTitle="
				editTarget
					? t('decidiq', 'Edit amendment')
					: t('decidiq', 'Submit amendment')
			"
			:excludeFields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			nameField="title"
			:dialogTitle="t('decidiq', 'Delete amendment')"
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
	name: 'MotionAmendmentsTab',
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
			amendmentSchema: null,
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
		 * not a choice: every row here is a Decision of decisionType=amendment
		 * and onConfirm already writes the value itself. Leaving it on the
		 * form showed an EMPTY required picker — decision-types-as-configuration
		 * (#1099) dropped the enum from the stored schema, so the select had
		 * no options and blocked the save.
		 *
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		excludedFields() {
			return [
				'id',
				'uuid',
				'parentMotion',
				'decisionType',
				'created',
				'updated',
			]
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
				const store = ensureRelationType('amendment')
				if (!this.amendmentSchema)
					this.amendmentSchema = await store.fetchSchema('amendment')
				const items = await store.fetchCollection('amendment', {
					decisionType: 'amendment',
					amends: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load amendments.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openCreate() {
			const store = ensureRelationType('amendment')
			if (!this.amendmentSchema)
				this.amendmentSchema = await store.fetchSchema('amendment')
			this.editTarget = null
			this.formOpen = true
		},

		/**
		 * @param row The decision row to edit.
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async openEdit(row) {
			const store = ensureRelationType('amendment')
			if (!this.amendmentSchema)
				this.amendmentSchema = await store.fetchSchema('amendment')
			this.editTarget = { ...row }
			this.formOpen = true
		},

		/**
		 * @param formData The submitted form values.
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async onConfirm(formData) {
			const store = ensureRelationType('amendment')
			try {
				await store.saveObject('amendment', {
					...formData,
					decisionType: 'amendment',
					amends: this.objectId,
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
			const store = ensureRelationType('amendment')
			try {
				await store.deleteObject('amendment', this.deleteTarget.id)
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
