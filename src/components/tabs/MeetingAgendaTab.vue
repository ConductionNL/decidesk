<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: agenda items for a Meeting.

 Posture: full CRUD. Meetings own their agenda items; this tab lists
 them by `meeting === parent.id` in tree order (sub-items nested under
 their parent via the additive `parentItem` field), and lets
 chair/secretary add, edit, and delete items inline. It also warns
 about missing statutory ALV items for `general_assembly` meetings and
 assembles the meeting document package (vergaderstukken). Drag-reorder
 from the deleted MeetingDetail/AgendaBuilder is left to the standalone
 /agenda-items index — out of scope for the sidebar tab.
-->
<template>
	<div class="decidiq-tab decidiq-tab--agenda" data-testid="agenda-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Agenda') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<div class="decidiq-tab__header-actions">
				<NcButton
					data-testid="agenda-assemble-package"
					:disabled="assembling"
					:aria-label="t('decidiq', 'Assemble meeting package')"
					@click="assemblePackage">
					{{
						assembling
							? t('decidiq', 'Assembling…')
							: t('decidiq', 'Assemble meeting package')
					}}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="agenda-add-item"
					:aria-label="t('decidiq', 'Add agenda item')"
					@click="openCreate">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('decidiq', 'Add agenda item') }}
				</NcButton>
			</div>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load agenda items')">
			{{ error }}
		</CnNoteCard>

		<CnNoteCard
			v-if="missingStatutory.length > 0"
			type="warning"
			data-testid="statutory-items-warning"
			:title="t('decidiq', 'Missing statutory ALV agenda items')">
			<p>
				{{
					t(
						'decidiq',
						'This general assembly agenda is missing legally required items:',
					)
				}}
			</p>
			<ul class="decidiq-tab__statutory-list">
				<li v-for="required in missingStatutory" :key="required.id">
					{{ t('decidiq', required.label) }}
				</li>
			</ul>
		</CnNoteCard>

		<CnNoteCard
			v-if="packageError"
			type="error"
			:title="t('decidiq', 'Package assembly failed')">
			{{ packageError }}
		</CnNoteCard>

		<CnNoteCard
			v-if="packageResult"
			type="success"
			data-testid="agenda-package-result"
			:title="t('decidiq', 'Meeting package assembled')">
			<p>{{ packageResult.message }}</p>
			<a
				v-if="packageResult.path"
				:href="packageFolderUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('decidiq', 'Open package folder') }}
			</a>
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No agenda items yet for this meeting.')"
			:loadingText="t('decidiq', 'Loading agenda…')"
			@rowClick="openEdit">
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<CnFormDialog
			v-if="formOpen"
			ref="formDialog"
			:schema="agendaSchema"
			:item="editTarget"
			:dialogTitle="
				editTarget
					? t('decidiq', 'Edit agenda item')
					: t('decidiq', 'Add agenda item')
			"
			:excludeFields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			nameField="title"
			:dialogTitle="t('decidiq', 'Delete agenda item')"
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
} from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import {
	buildAgendaTree,
	flattenTree,
	missingStatutoryItems,
} from '../../services/agendaRules.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingAgendaTab',
	components: {
		CnDataTable,
		CnDeleteDialog,
		CnFormDialog,
		CnNoteCard,
		CnRowActions,
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
			rawRows: [],
			meeting: null,
			agendaSchema: null,
			// uuid → name for the agenda-item types this instance has configured.
			// Empty is a valid state, not a failure: an instance that seeds no
			// types shows the coarse enum and nothing breaks.
			itemTypeNames: {},
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
			assembling: false,
			packageResult: null,
			packageError: '',
		}
	},

	computed: {
		/**
		 * Agenda rows, with the Type column resolved against the configured
		 * kinds.
		 *
		 * Computed rather than stored so the column fills in when the type names
		 * arrive, without the agenda blocking on that fetch.
		 *
		 * @return {Array<object>} The rows to render.
		 *
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		rows() {
			return this.rawRows.map((item) => ({
				...item,
				kindDisplay: this.itemTypeNames[item.type] || item.itemType,
			}))
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{
					key: 'orderNumber',
					label: this.t('decidiq', '#'),
					width: '60px',
				},
				{ key: 'titleDisplay', label: this.t('decidiq', 'Title') },
				// The CONFIGURABLE kind, not the coarse enum. Since
				// questions-as-agenda-items collapsed oral questions and
				// interpellation requests into agenda items, `itemType`
				// (informational/discussion/decision) would render every one of
				// them as "discussion" and the column would stop telling a clerk
				// anything. `kindDisplay` falls back to `itemType` for an item
				// whose type is unset, so an instance that seeds no types reads
				// exactly as it did before.
				{ key: 'kindDisplay', label: this.t('decidiq', 'Type') },
				{
					key: 'estimatedDuration',
					label: this.t('decidiq', 'Duration (min)'),
				},
			]
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		missingStatutory() {
			return missingStatutoryItems(this.meeting?.meetingType || '', this.rows)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		packageFolderUrl() {
			if (!this.packageResult?.path) return ''
			return (
				generateUrl('/apps/files')
				+ '?dir='
				+ encodeURIComponent(this.packageResult.path)
			)
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

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		excludedFields() {
			// Hide system / parent-link fields — we set `meeting` ourselves.
			return ['id', 'uuid', 'meeting', 'created', 'updated']
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
		/** @spec openspec/specs/agenda-management/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('agenda-item')
				if (!this.agendaSchema)
					this.agendaSchema = await store.fetchSchema('agenda-item')
				const items = await store.fetchCollection('agenda-item', {
					meeting: this.objectId,
					_order: JSON.stringify({ orderNumber: 'asc' }),
					_limit: 100,
				})
				// 🔴 NOT AWAITED. The Type column is a label; the agenda is the
				// page. Awaiting this put one more object-list query in front of
				// every render, and on a loaded instance those cost about a
				// second each. `rows` is computed, so the names appear as soon as
				// they arrive and the agenda never waits for them.
				this.loadItemTypes()
				// Tree order: sub-items (`parentItem`) nest under their parent;
				// flattened parent→children order with a nesting indicator.
				const flat = flattenTree(buildAgendaTree(items || []))
				this.rawRows = flat.map((item) => ({
					...item,
					titleDisplay: item.parentItem ? `↳ ${item.title}` : item.title,
				}))
				await this.loadMeeting()
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load agenda.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the configured agenda-item types, so the Type column can name
		 * the kind an item actually is.
		 *
		 * Fail-soft on purpose: a fetch that throws leaves the map empty, every
		 * row falls back to `itemType`, and the agenda still renders. An empty
		 * agenda-item-type collection is the normal state of an instance that
		 * has seeded no example set, so it must not read as an error.
		 *
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		async loadItemTypes() {
			try {
				const typeStore = ensureRelationType('agenda-item-type')
				const types = await typeStore.fetchCollection('agenda-item-type', {
					_limit: 200,
				})
				const names = {}
				for (const type of types || []) {
					if (!type?.name) continue
					// 🔴 INDEXED BY BOTH, BECAUSE A SEED WRITES THE SLUG.
					// A row created through the UI holds the type's uuid, but
					// seedData stores a reference exactly as the file wrote it:
					// measured on a live instance, seeded agenda items carry
					// `meeting: "raadsvergadering-2025-01-15"`, the slug, not a
					// uuid. Keying on `id` alone would leave every example-set
					// item falling back to the coarse enum, so the set that
					// exists to demonstrate configurable kinds would show none.
					if (type.id) names[type.id] = type.name
					const slug = type['@self']?.slug
					if (slug) names[slug] = type.name
				}
				this.itemTypeNames = names
			} catch {
				this.itemTypeNames = {}
			}
		},

		/**
		 * Fetch the parent meeting (for `meetingType` — statutory ALV check).
		 * Fail-soft: the agenda list renders even when the meeting cannot load.
		 *
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async loadMeeting() {
			try {
				const meetingStore = ensureRelationType('meeting')
				this.meeting = await meetingStore.fetchObject(
					'meeting',
					this.objectId,
				)
			} catch (e) {
				console.error('[decidiq] MeetingAgendaTab meeting fetch failed', e)
			}
		},

		/**
		 * Assemble the meeting document package (vergaderstukken) via
		 * POST /api/meetings/{id}/package and surface the folder link.
		 *
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async assemblePackage() {
			this.assembling = true
			this.packageError = ''
			this.packageResult = null
			try {
				const response = await fetch(
					generateUrl(
						`/apps/decidiq/api/meetings/${this.objectId}/package`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				const payload = await response.json()
				if (!response.ok || payload?.success === false) {
					this.packageError =
						payload?.message
						|| this.t('decidiq', 'Package assembly failed.')
					return
				}
				this.packageResult = payload
			} catch (e) {
				this.packageError =
					e?.message || this.t('decidiq', 'Package assembly failed.')
			} finally {
				this.assembling = false
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openCreate() {
			const store = ensureRelationType('agenda-item')
			if (!this.agendaSchema)
				this.agendaSchema = await store.fetchSchema('agenda-item')
			this.editTarget = null
			this.formOpen = true
		},

		/**
		 * @param row
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async openEdit(row) {
			const store = ensureRelationType('agenda-item')
			if (!this.agendaSchema)
				this.agendaSchema = await store.fetchSchema('agenda-item')
			// Strip the presentation-only nesting indicator before editing.

			const { titleDisplay, ...item } = row
			this.editTarget = item
			this.formOpen = true
		},

		/**
		 * @param formData
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async onConfirm(formData) {
			const store = ensureRelationType('agenda-item')
			try {
				await store.saveObject('agenda-item', {
					...formData,
					meeting: this.objectId,
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
			const store = ensureRelationType('agenda-item')
			try {
				await store.deleteObject('agenda-item', this.deleteTarget.id)
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

.decidiq-tab__header-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
}

.decidiq-tab__statutory-list {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
	list-style: disc;
}
</style>
