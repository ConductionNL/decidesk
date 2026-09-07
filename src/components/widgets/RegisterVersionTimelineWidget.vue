<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RegisterVersionTimelineWidget — content-driven `version-timeline` catalog
 widget (register-detail-optimisation, design D1). One reusable component
 serving GoverningDocumentDetail (governing-document-versie). It used to serve
 RegelingDetail too, and that it could serve both unchanged is part of how the
 duplication was found: fold-regulations-into-governing-documents retired
 `regeling`, which modelled the same thing in a council's words. Resolves every
 version object whose
 `content.parentRefField` points at the current record, sorts ascending by
 `content.effectiveDateField`, and renders version number / effective+lapse
 dates / status badge / a resolved link to the enacting Decision
 (`content.decisionRefField`) plus any `content.extraFields` (e.g. the
 governing-document-versie notarial-deed metadata).

 Registered into @conduction/nextcloud-vue's shared dashboardWidgetRegistry
 as type "version-timeline" (renderer-only, surfaces: ['detail-page']) by
 registerDetailWidgets.js. Resolved by CnDetailPage's generic content-driven
 catalog-widget fallback, which passes `content` both as a prop AND spread
 (v-bind) onto this component — so `content.title` / `content.icon` land on
 this component's own `title` / `icon` props without extra wiring.

 @spec openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
 @spec openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail
-->
<template>
	<CnWidgetWrapper
		:title="title"
		:widgetId="widgetId"
		:refreshing="loading"
		titleIconPosition="left"
		flush>
		<template #title-icon>
			<CnIcon :name="icon" :size="20" />
		</template>

		<div class="cn-version-timeline">
			<p v-if="loading && !rows.length" class="cn-version-timeline__loading">
				{{ t('decidiq', 'Loading versions…') }}
			</p>

			<CnNoteCard
				v-else-if="error"
				type="error"
				:heading="t('decidiq', 'Could not load versions')"
				data-testid="version-timeline-error">
				{{ error }}
			</CnNoteCard>

			<CnNoteCard
				v-else-if="!rows.length"
				type="info"
				:heading="t('decidiq', 'No versions yet')"
				data-testid="version-timeline-empty">
				{{
					t(
						'decidiq',
						'No versions have been recorded for this record yet.',
					)
				}}
			</CnNoteCard>

			<ol
				v-else
				class="cn-version-timeline__list"
				data-testid="version-timeline-list">
				<li
					v-for="row in rows"
					:key="row.id"
					class="cn-version-timeline__item"
					data-testid="version-timeline-item">
					<span class="cn-version-timeline__marker" aria-hidden="true" />
					<div class="cn-version-timeline__body">
						<div class="cn-version-timeline__line1">
							<span class="cn-version-timeline__version">
								{{
									t('decidiq', 'Version {n}', {
										n: row.versionNumber,
									})
								}}
							</span>
							<CnStatusBadge
								v-if="row.status"
								:label="statusLabel(row.status)"
								:colorMap="statusColorMap"
								size="small" />
						</div>
						<div class="cn-version-timeline__line2">
							<span
								v-if="row.effectiveDate"
								class="cn-version-timeline__date">
								{{
									t('decidiq', 'In force from {date}', {
										date: formatDate(row.effectiveDate),
									})
								}}
							</span>
							<span
								v-if="row.lapseDate"
								class="cn-version-timeline__date">
								{{
									t('decidiq', 'until {date}', {
										date: formatDate(row.lapseDate),
									})
								}}
							</span>
						</div>
						<div
							v-if="row.extra.length"
							class="cn-version-timeline__extra">
							<span
								v-for="entry in row.extra"
								:key="entry.label"
								class="cn-version-timeline__extra-item">
								{{ entry.label }}: {{ entry.display }}
							</span>
						</div>
						<NcButton
							v-if="row.decisionId"
							variant="tertiary"
							class="cn-version-timeline__decision"
							data-testid="version-timeline-decision-link"
							@click="openDecision(row.decisionId)">
							{{
								decisionLabels[row.decisionId]
								|| t('decidiq', 'View decision')
							}}
						</NcButton>
					</div>
				</li>
			</ol>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import {
	CnIcon,
	CnNoteCard,
	CnStatusBadge,
	CnWidgetWrapper,
	useObjectStore,
} from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import {
	resolveObjectLabel,
	sortVersionsByEffectiveDate,
} from './registerDetailWidgets.js'

/** Shared version-lifecycle status enum across regeling-versie / governing-document-versie. */
const STATUS_LABELS = {
	draft: () => t('decidiq', 'concept'),
	adopted: () => t('decidiq', 'adopted'),
	'in-effect': () => t('decidiq', 'in force'),
	replaced: () => t('decidiq', 'replaced'),
	lapsed: () => t('decidiq', 'lapsed'),
}

export default {
	name: 'RegisterVersionTimelineWidget',

	components: { CnWidgetWrapper, CnIcon, CnNoteCard, CnStatusBadge, NcButton },

	props: {
		/**
		 * `{ versionRegister?, versionSchema, parentRefField, effectiveDateField,
		 * versionNumberField?, statusField?, decisionRefField?, lapseDateField?,
		 * decisionRoute?, extraFields?: Array<{field,label,format?}> }`
		 *
		 * @type {object}
		 */
		content: { type: Object, default: () => ({}) },
		objectId: { type: [String, Number], default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
		objectData: { type: Object, default: () => ({}) },
		objectType: { type: String, default: '' },
		/** Effective object store (forwarded by CnDetailPage). Falls back to the library's default. */
		store: { type: Object, default: null },
		/** Card title. Filled from `content.title` via the host's v-bind spread when set. */
		title: { type: String, default: () => t('decidiq', 'Version timeline') },
		/** Card icon (must be registered in src/icons.js, ADR-077). */
		icon: { type: String, default: 'Timeline' },
	},

	data() {
		return {
			versions: [],
			loading: false,
			error: '',
			/** Resolved Decision titles, keyed by decision id. */
			decisionLabels: {},
		}
	},

	computed: {
		/**
		 * Merged content config with defaults.
		 *
		 * @spec exclude config-merge helper (field-name defaults), no behavioural requirement of its own
		 */
		cfg() {
			const c = this.content || {}
			return {
				versionRegister: c.versionRegister || this.register || 'decidiq',
				versionSchema: c.versionSchema || '',
				parentRefField: c.parentRefField || '',
				effectiveDateField: c.effectiveDateField || 'effectiveDate',
				lapseDateField: c.lapseDateField || '',
				versionNumberField: c.versionNumberField || 'versionNumber',
				statusField: c.statusField || 'status',
				decisionRefField: c.decisionRefField || '',
				decisionRoute: c.decisionRoute || 'DecisionDetail',
				extraFields: Array.isArray(c.extraFields) ? c.extraFields : [],
			}
		},

		/**
		 * The current object's id — explicit prop wins, else derived from objectData.
		 *
		 * @spec exclude defensive object-id accessor, no behavioural requirement of its own
		 */
		resolvedObjectId() {
			const data =
				this.objectData && typeof this.objectData === 'object'
					? this.objectData
					: {}
			const self = data['@self'] || {}
			return this.objectId || data.id || self.id || ''
		},

		/**
		 * The current object's slug, when it has one.
		 *
		 * OpenRegister's seed importer stores `$ref` values as raw SLUG
		 * strings rather than resolved UUIDs, so a version row seeded against
		 * this record carries the parent's slug in `parentRefField`, not its
		 * id. Live-verified 2026-08-19 on regeling-versie, and again 2026-09-05 on
		 * agenda-item: rows hold `meeting: "raadsvergadering-2025-01-15"` while
		 * the parent's id is a UUID — filtering on the id alone returned nothing and the
		 * widget rendered an empty shell.
		 *
		 * @spec exclude defensive object-slug accessor, no behavioural requirement of its own
		 */
		resolvedObjectSlug() {
			const data =
				this.objectData && typeof this.objectData === 'object'
					? this.objectData
					: {}
			const self = data['@self'] || {}
			return self.slug || data.slug || ''
		},

		/** @spec exclude widget-id plumbing for CnWidgetWrapper, no behavioural requirement of its own */
		widgetId() {
			return this.cfg.versionSchema || 'version-timeline'
		},

		/** @spec exclude presentation color-mapping helper for the status badge, no behavioural requirement of its own */
		statusColorMap() {
			return {
				[STATUS_LABELS.draft()]: 'default',
				[STATUS_LABELS.adopted()]: 'primary',
				[STATUS_LABELS['in-effect']()]: 'success',
				[STATUS_LABELS.replaced()]: 'default',
				[STATUS_LABELS.lapsed()]: 'error',
			}
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
		 * @spec openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail
		 */
		sortedVersions() {
			return sortVersionsByEffectiveDate(
				this.versions,
				this.cfg.effectiveDateField,
			)
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
		 * @spec openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail
		 */
		rows() {
			const {
				versionNumberField,
				effectiveDateField,
				lapseDateField,
				statusField,
				decisionRefField,
				extraFields,
			} = this.cfg
			return this.sortedVersions.map((v) => {
				const self = v['@self'] || {}
				return {
					id: v.id || self.id,
					versionNumber: v[versionNumberField],
					effectiveDate: v[effectiveDateField],
					lapseDate: lapseDateField ? v[lapseDateField] : null,
					status: statusField ? v[statusField] : '',
					decisionId: decisionRefField ? v[decisionRefField] : null,
					extra: extraFields
						.map((ef) => ({
							label: ef.label,
							display: this.formatExtra(v[ef.field], ef.format),
						}))
						.filter((entry) => entry.display !== ''),
				}
			})
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/**
			 * @spec openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
			 * @spec openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * The object store to query — explicit prop wins, else the library's
		 * default instance (defensive fallback; CnDetailPage always forwards
		 * one in practice).
		 *
		 * @spec exclude store-resolution plumbing, no behavioural requirement of its own
		 * @return {object|null}
		 */
		getStore() {
			if (this.store) return this.store
			try {
				return useObjectStore()
			} catch {
				return null
			}
		},

		/**
		 * Fetch the version collection filtered on `parentRefField == currentId`,
		 * then batch-resolve the (deduplicated) Decision links.
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
		 * @spec openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail
		 * @return {Promise<void>}
		 */
		async load() {
			const id = this.resolvedObjectId
			const { versionSchema, versionRegister, parentRefField } = this.cfg
			if (!id || !versionSchema || !parentRefField) {
				this.versions = []
				return
			}
			const store = this.getStore()
			if (!store) {
				this.error = t('decidiq', 'No object store available')
				return
			}
			this.loading = true
			this.error = ''
			try {
				if (
					!store.objectTypeRegistry
					|| !store.objectTypeRegistry[versionSchema]
				) {
					store.registerObjectType(
						versionSchema,
						versionSchema,
						versionRegister,
					)
				}
				this.versions = await store.fetchCollection(versionSchema, {
					[parentRefField]: id,
					_limit: 200,
				})
				// Seeded rows reference their parent by SLUG (see
				// resolvedObjectSlug) — retry once on the slug before
				// concluding this record simply has no versions.
				if (
					Array.isArray(this.versions)
					&& this.versions.length === 0
					&& this.resolvedObjectSlug
				) {
					this.versions = await store.fetchCollection(versionSchema, {
						[parentRefField]: this.resolvedObjectSlug,
						_limit: 200,
					})
				}
				await this.resolveDecisionLabels(store)
			} catch (e) {
				this.error =
					e?.message
					|| t('decidiq', 'Failed to load the version timeline.')
				this.versions = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Batch-resolve every unique amending-Decision id referenced by the
		 * loaded versions (single Promise.all pass, not one request per row).
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
		 * @spec openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail
		 * @param {object} store The object store.
		 * @return {Promise<void>}
		 */
		async resolveDecisionLabels(store) {
			const { decisionRefField, versionRegister } = this.cfg
			if (!decisionRefField) return
			const ids = [
				...new Set(
					this.versions.map((v) => v[decisionRefField]).filter(Boolean),
				),
			]
			const labels = { ...this.decisionLabels }
			await Promise.all(
				ids.map(async (id) => {
					if (labels[id]) return
					labels[id] = await resolveObjectLabel(
						store,
						'decision',
						'decision',
						versionRegister,
						id,
						'title',
					)
				}),
			)
			this.decisionLabels = labels
		},

		/**
		 * Translated label for a version-lifecycle status value.
		 *
		 * @spec exclude presentation label helper for a status value, no behavioural requirement of its own
		 * @param {string} status The raw status value.
		 * @return {string}
		 */
		statusLabel(status) {
			const fn = STATUS_LABELS[status]
			return fn ? fn() : status
		},

		/**
		 * Locale date, empty-safe (never a raw ISO string — REQ-VOR-011).
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-011-computed-in-force-date-columns-render-formatted-not-raw
		 * @param {string} value ISO date string.
		 * @return {string}
		 */
		formatDate(value) {
			if (!value) return ''
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString()
		},

		/**
		 * Render an extraFields entry value, date-formatted when configured.
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail
		 * @param {string|number|null|undefined} value The raw field value.
		 * @param {string} [format] `'date'` to format through formatDate.
		 * @return {string}
		 */
		formatExtra(value, format) {
			if (value === null || value === undefined || value === '') return ''
			return format === 'date' ? this.formatDate(value) : String(value)
		},

		/**
		 * Navigate to the enacting Decision's detail page.
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/verordeningenregister/spec.md#req-vor-009-version-timeline-widget-on-regelingdetail
		 * @spec openspec/changes/register-detail-optimisation/specs/governing-documents-register/spec.md#req-gdr-009-version-timeline-widget-on-governingdocumentdetail
		 * @param {string} decisionId The Decision id.
		 * @return {void}
		 */
		openDecision(decisionId) {
			if (!decisionId) return
			this.$router.push({
				name: this.cfg.decisionRoute,
				params: { id: decisionId },
			})
		},
	},
}
</script>

<style scoped>
.cn-version-timeline__loading {
	padding: calc(2 * var(--default-grid-baseline, 4px));
	color: var(--color-text-maxcontrast);
}

.cn-version-timeline__list {
	list-style: none;
	margin: 0;
	padding: calc(2 * var(--default-grid-baseline, 4px));
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.cn-version-timeline__item {
	display: flex;
	gap: 10px;
}

.cn-version-timeline__marker {
	width: 10px;
	height: 10px;
	margin-top: 5px;
	border-radius: 50%;
	background: var(--color-primary-element);
	flex-shrink: 0;
}

.cn-version-timeline__body {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.cn-version-timeline__line1,
.cn-version-timeline__line2,
.cn-version-timeline__extra {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.cn-version-timeline__version {
	font-weight: bold;
}

.cn-version-timeline__date,
.cn-version-timeline__extra-item {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.cn-version-timeline__decision {
	align-self: flex-start;
	margin-top: 2px;
}
</style>
