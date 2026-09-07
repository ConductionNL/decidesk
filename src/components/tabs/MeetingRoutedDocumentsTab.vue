<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting-scoped facet: incoming documents routed onto this meeting's agenda
 — meeting-facet-composition, REQ-MDV-013. Read-only.

 Two-hop join no declarative object-list `filter` can express (design.md
 Decision 4): (1) fetch this meeting's own agenda items, (2) fetch
 raadsinformatiebrief scoped server-side by `agendaItem` IN those ids, (3)
 fetch ingekomen-stuk and filter client-side for `targetAgendaItem` /
 `listAgendaItem` membership (two possible ref fields — no single-request
 OR filter exists). Three sequential network fetches total, accepted per
 design.md's Trade-offs (typical agenda size is small, and this is a
 per-page-load cost, not a per-row cost). The join/merge logic itself lives
 in the importable, Vitest-covered routedDocumentsJoin.js sibling module —
 this component only owns the fetch sequencing and rendering.

 Uses the `cnObjectContext` inject (the same channel CnObjectListWidget
 itself resolves `@objectId`/`@object.<field>` tokens from) rather than
 relying solely on an `objectId` prop, so this facet's fetch is not
 sensitive to which body-widget render path (manifest `slots` map vs.
 auto-body) mounts it.

 @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-013-routed-incoming-documents-facet-read-only
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--routed-documents"
		data-testid="meeting-routed-documents-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Incoming documents') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load routed documents')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="
				t('decidiq', 'No incoming documents routed to this meeting yet.')
			"
			:loadingText="t('decidiq', 'Loading routed documents…')"
			@rowClick="openDetail" />
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard } from '@conduction/nextcloud-vue'
import {
	buildRoutedDocumentRows,
	collectAgendaItemIds,
	filterRoutedIngekomenStukken,
	ROUTE_BY_TYPE,
} from './routedDocumentsJoin.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingRoutedDocumentsTab',
	components: { CnDataTable, CnNoteCard },
	inject: {
		cnObjectContext: { default: null },
	},

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
		}
	},

	computed: {
		/**
		 * The current meeting's object id. Prefers an explicit `objectId`
		 * prop (test/reuse override); falls back to the CnDetailPage-provided
		 * `cnObjectContext` inject, which stays reliable regardless of the
		 * widget-grid slot render path.
		 *
		 * @spec openspec/specs/meeting-detail-view/spec.md#requirement-req-mdv-013-routed-incoming-documents-facet-read-only
		 * @return {string}
		 */
		resolvedObjectId() {
			if (this.objectId) return String(this.objectId)
			const ctx = this.cnObjectContext
			const value =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (value && value.objectId) || ''
		},

		/** @spec openspec/specs/meeting-detail-view/spec.md#scenario-documents-routed-onto-the-meetings-agenda */
		columns() {
			return [
				{
					key: 'typeLabel',
					label: this.t('decidiq', 'Type'),
					widget: 'badge',
					widgetProps: { colorMap: this.typeColors },
				},
				{ key: 'title', label: this.t('decidiq', 'Title') },
				{ key: 'category', label: this.t('decidiq', 'Category') },
				{
					key: 'lifecycle',
					label: this.t('decidiq', 'Status'),
					widget: 'badge',
				},
			]
		},

		/** @spec openspec/specs/meeting-detail-view/spec.md#scenario-documents-routed-onto-the-meetings-agenda */
		typeColors() {
			return {
				Raadsinformatiebrief: 'info',
				'Ingekomen stuk': 'default',
			}
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/** @spec openspec/specs/meeting-detail-view/spec.md#scenario-documents-routed-onto-the-meetings-agenda */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/specs/meeting-detail-view/spec.md#scenario-documents-routed-onto-the-meetings-agenda */
		async refresh() {
			if (!this.resolvedObjectId) return
			this.loading = true
			this.error = ''
			try {
				const agendaStore = ensureRelationType('agenda-item')
				const agendaItems = await agendaStore.fetchCollection(
					'agenda-item',
					{
						meeting: this.resolvedObjectId,
						_limit: 100,
					},
				)
				const agendaItemIds = collectAgendaItemIds(agendaItems)

				if (agendaItemIds.length === 0) {
					this.rows = []
					return
				}

				const ribStore = ensureRelationType('raadsinformatiebrief')
				const raadsinformatiebrieven = await ribStore.fetchCollection(
					'raadsinformatiebrief',
					{ agendaItem: agendaItemIds, _limit: 100 },
				)

				const stukStore = ensureRelationType('ingekomen-stuk')
				const allIngekomenStukken = await stukStore.fetchCollection(
					'ingekomen-stuk',
					{ _limit: 200 },
				)
				const routedIngekomenStukken = filterRoutedIngekomenStukken(
					allIngekomenStukken,
					agendaItemIds,
				)

				this.rows = buildRoutedDocumentRows(
					raadsinformatiebrieven,
					routedIngekomenStukken,
				)
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load routed documents.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Navigate to the correct detail page for a row, per its own `type`.
		 *
		 * @param {object} row Combined row (must carry `id` and `type`).
		 * @spec openspec/specs/meeting-detail-view/spec.md#scenario-documents-routed-onto-the-meetings-agenda
		 */
		openDetail(row) {
			const id = row && row.id
			const routeName = row && ROUTE_BY_TYPE[row.type]
			if (!id || !routeName) return
			this.$router.push({ name: routeName, params: { id } })
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
