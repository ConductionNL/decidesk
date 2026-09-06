<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CnDecisionsWidget — "Besluitvorming" detail-page widget for the
 `decidesk-decisions` integration leaf (ADR-019 / ADR-022).

 Given a host object's identity ({ register, schema, objectId }) supplied
 by the OpenRegister integration registry, this widget lists the decidiq
 decisions linked to that object — proposals, advice and final decisions —
 grouped by kind. It is generic: the host can be a procest case, an
 opencatalogi catalog, or any OR object. The link is the decidiq Decision
 schema's `subjectId` back-reference (decidiq-contract-decision-hub,
 REQ-DCDH-001); this widget fetches decisions where `subjectId == objectId`.

 Read-mostly + link-out: the full decision workflow (deliberation, voting,
 publication) lives in decidiq, so each row deep-links to decidiq's
 DecisionDetail page and a "Create proposal for this case" action opens the
 in-widget CnFormDialog pre-linked to the host object via subjectId.

 Surface-aware (AD-19): on dashboard surfaces it renders a compact count
 headline; on the detail-page surface a grouped clickable list.

 All UI strings pass through t('decidiq', …) (ADR-007); styling uses
 Nextcloud CSS variables only so the nldesign overrides apply (ADR-010).
-->
<template>
	<CnDetailCard :title="cardTitle" :icon="cardIcon" :collapsible="collapsible">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="cn-decisions-widget__error" role="alert">
			{{ error }}
		</div>

		<template v-else>
			<!-- Dashboard surfaces: count headline only. -->
			<div v-if="isDashboardSurface" class="cn-decisions-widget__headline">
				<strong>{{ countLabel }}</strong>
				<a :href="appUrl" class="cn-decisions-widget__open-app">{{
					openInDecidiqLabel
				}}</a>
			</div>

			<!-- Detail-page (and fallback) surface: grouped list. -->
			<template v-else>
				<div
					v-if="decisions.length === 0"
					class="cn-decisions-widget__empty">
					{{ emptyLabel }}
				</div>
				<div
					v-for="group in nonEmptyGroups"
					v-else
					:key="group.key"
					class="cn-decisions-widget__group">
					<h4 class="cn-decisions-widget__group-title">
						{{ group.label }}
						<span class="cn-decisions-widget__group-count"
							>({{ group.items.length }})</span
						>
					</h4>
					<ul class="cn-decisions-widget__list">
						<li
							v-for="decision in group.items"
							:key="rowKey(decision)"
							class="cn-decisions-widget__row">
							<a
								:href="decisionUrl(decision)"
								class="cn-decisions-widget__title"
								:title="rowTitle(decision)"
								>{{ rowTitle(decision) }}</a
							>
							<CnStatusBadge
								size="small"
								:variant="lifecycleVariant(decision)"
								:label="lifecycleLabel(decision)" />
						</li>
					</ul>
				</div>

				<div class="cn-decisions-widget__actions">
					<NcButton
						variant="primary"
						:disabled="!objectId"
						@click="openCreate">
						<template #icon>
							<Plus :size="18" />
						</template>
						{{ createProposalLabel }}
					</NcButton>
					<NcButton variant="tertiary" @click="openApp">
						<template #icon>
							<OpenInNew :size="18" />
						</template>
						{{ openInDecidiqLabel }}
					</NcButton>
				</div>
			</template>
		</template>

		<CnFormDialog
			v-if="createOpen"
			:schema="createSchema"
			:dialogTitle="createProposalLabel"
			@close="createOpen = false"
			@confirm="onCreate" />
	</CnDetailCard>
</template>

<script>
import { CnDetailCard, CnFormDialog, CnStatusBadge } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import {
	createHostDecision,
	decisionBucket,
	isProposal,
	listDecisionTypes,
	listHostDecisions,
	objId,
	proposalFormSchema,
} from './decisionLink.js'

const DASHBOARD_SURFACES = ['user-dashboard', 'app-dashboard']

/**
 * CnDecisionsWidget — surface-aware "Besluitvorming" widget for the
 * `decidesk-decisions` integration leaf. See the file-level docblock for
 * surface-by-surface behaviour and the case-linking mechanism.
 */
export default {
	name: 'CnDecisionsWidget',

	components: {
		CnDetailCard,
		CnStatusBadge,
		CnFormDialog,
		NcButton,
		NcLoadingIcon,
		OpenInNew,
		Plus,
	},

	props: {
		/** Stable integration id (forwarded from the registry — always `'decidesk-decisions'`). */
		integrationId: { type: String, default: 'decidesk-decisions' },
		/** OpenRegister register id of the HOST object (slug or uuid). */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the HOST object (slug or uuid). */
		schema: { type: String, default: '' },
		/** UUID of the HOST object the decisions are linked to. */
		objectId: { type: [String, Number], default: '' },
		/** Whole integration context — used as a fallback when discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
		/** Rendering surface (AD-19). */
		surface: { type: String, default: 'detail-page' },
		/** Human label for the host object (shown as subjectLabel on created decisions). */
		objectLabel: { type: String, default: '' },
		/** Whether the card body is collapsible. */
		collapsible: { type: Boolean, default: true },
	},

	data() {
		return {
			decisions: [],
			decisionTypes: null,
			loading: false,
			creating: false,
			error: '',
			createOpen: false,
		}
	},

	computed: {
		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — Besluitvorming widget title. */
		cardTitle() {
			return t('decidiq', 'Besluitvorming')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — Besluitvorming widget icon. */
		cardIcon() {
			return Gavel
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 link-out to decidiq. */
		appUrl() {
			return generateUrl('/apps/decidiq/decisions')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostObjectId() {
			return String(this.objectId || this.integrationContext.objectId || '')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostRegister() {
			return this.register || this.integrationContext.register || ''
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostSchema() {
			return this.schema || this.integrationContext.schema || ''
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — adapt widget layout to the host surface. */
		isDashboardSurface() {
			return DASHBOARD_SURFACES.includes(String(this.surface).split(':')[0])
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — empty-state copy for the leaf surface. */
		emptyLabel() {
			return t('decidiq', 'No decision-making linked to this object yet.')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal action label. */
		createProposalLabel() {
			return t('decidiq', 'Create proposal for this object')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 link-out to decidiq. */
		openInDecidiqLabel() {
			return t('decidiq', 'Open in decidiq')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — linked-decision count label. */
		countLabel() {
			const total = this.decisions.length
			return this.n(
				'decidiq',
				'{count} decision',
				'{count} decisions',
				total,
				{ count: total },
			)
		},

		/**
		 * Grouped buckets, in presentation order: Voorstellen, Adviezen, Besluiten.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — group host decisions by kind.
		 */
		groups() {
			const buckets = { proposals: [], advice: [], decisions: [] }
			for (const decision of this.decisions) {
				buckets[decisionBucket(decision)].push(decision)
			}
			return [
				{
					key: 'proposals',
					label: t('decidiq', 'Proposals'),
					items: buckets.proposals,
				},
				{
					key: 'advice',
					label: t('decidiq', 'Advice'),
					items: buckets.advice,
				},
				{
					key: 'decisions',
					label: t('decidiq', 'Decisions'),
					items: buckets.decisions,
				},
			]
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — render only populated decision groups. */
		nonEmptyGroups() {
			return this.groups.filter((g) => g.items.length > 0)
		},

		/**
		 * The picker offers the REGISTRY's vocabulary, not a hardcoded list:
		 * a type an administrator adds appears here without a release.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal form schema.
		 */
		createSchema() {
			return proposalFormSchema(this.decisionTypes)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.refresh()
			},
		},

		integrationContext: {
			handler() {
				this.refresh()
			},
		},
	},

	created() {
		this.loadDecisionTypes()
	},

	methods: {
		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — stable list-row key.
		 */
		rowKey(decision) {
			return objId(decision)
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — list-row title fallback.
		 */
		rowTitle(decision) {
			return (
				String(decision?.title ?? decision?.data?.title ?? '').trim()
				|| t('decidiq', 'Untitled decision')
			)
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle label.
		 */
		lifecycleLabel(decision) {
			if (isProposal(decision)) {
				return t('decidiq', 'Proposal')
			}
			const lifecycle = String(
				decision?.lifecycle ?? decision?.data?.lifecycle ?? 'decided',
			)
			switch (lifecycle) {
				case 'decided':
					return t('decidiq', 'Decided')
				case 'enacted':
					return t('decidiq', 'Enacted')
				case 'archived':
					return t('decidiq', 'Archived')
				case 'withdrawn':
					return t('decidiq', 'Withdrawn')
				default:
					return lifecycle
			}
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle badge variant.
		 */
		lifecycleVariant(decision) {
			if (isProposal(decision)) {
				return 'info'
			}
			const lifecycle = String(
				decision?.lifecycle ?? decision?.data?.lifecycle ?? '',
			)
			switch (lifecycle) {
				case 'decided':
				case 'enacted':
					return 'success'
				case 'withdrawn':
					return 'error'
				default:
					return 'default'
			}
		},

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 deep-link to a decision.
		 */
		decisionUrl(decision) {
			const id = objId(decision)
			return id
				? generateUrl(`/apps/decidiq/decisions/${encodeURIComponent(id)}`)
				: this.appUrl
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 open decidiq decisions app. */
		openApp() {
			if (typeof window !== 'undefined') {
				window.open(this.appUrl, '_blank', 'noopener')
			}
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 open create-proposal dialog. */
		openCreate() {
			if (this.hostObjectId) {
				this.createOpen = true
			}
		},

		/**
		 * Load the decisionType vocabulary from the registry endpoint.
		 *
		 * Fire-and-forget from created(): until it answers, the picker offers
		 * the shipped seed, and the schema is reactive so the configured
		 * vocabulary replaces it the moment it arrives.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal form schema.
		 */
		async loadDecisionTypes() {
			this.decisionTypes = await listDecisionTypes()
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 load decisions for the host object. */
		async refresh() {
			if (!this.hostObjectId) {
				this.decisions = []
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.decisions = await listHostDecisions(this.hostObjectId, 100)
			} catch (e) {
				this.error =
					e?.message || t('decidiq', 'Could not load decision-making.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param formData
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create a decision pre-linked to the host.
		 */
		async onCreate(formData) {
			if (!this.hostObjectId || this.creating) {
				return
			}
			this.creating = true
			this.error = ''
			try {
				await createHostDecision({
					title: formData.title || t('decidiq', 'Proposal'),
					text: formData.text || '',
					decisionType: formData.decisionType || 'motion',
					lifecycle: 'proposed',
					decisionDate: new Date().toISOString(),
					outcome: 'pending',
					// Case-linking back-reference (REQ-DCDH-001).
					sourceApp: this.hostSchema || 'openregister',
					subjectRegister: this.hostRegister,
					subjectSchema: this.hostSchema,
					subjectId: this.hostObjectId,
					subjectLabel: this.objectLabel || this.hostObjectId,
				})
				this.createOpen = false
				await this.refresh()
			} catch (e) {
				this.error = e?.message || t('decidiq', 'Could not create proposal.')
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.cn-decisions-widget__error {
	color: var(--color-error);
	font-size: 0.9em;
	padding: 8px 0;
}

.cn-decisions-widget__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	padding: 8px 0;
}

.cn-decisions-widget__headline {
	display: flex;
	align-items: baseline;
	gap: 10px;
	font-size: 1.1em;
}

.cn-decisions-widget__open-app,
.cn-decisions-widget__title {
	color: var(--color-primary-element);
	text-decoration: none;
}

.cn-decisions-widget__open-app:hover,
.cn-decisions-widget__title:hover {
	text-decoration: underline;
}

.cn-decisions-widget__group {
	margin-bottom: 10px;
}

.cn-decisions-widget__group-title {
	margin: 6px 0 4px;
	font-size: 0.85em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.cn-decisions-widget__group-count {
	font-weight: normal;
}

.cn-decisions-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.cn-decisions-widget__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.cn-decisions-widget__row:last-child {
	border-bottom: none;
}

.cn-decisions-widget__title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 13px;
}

.cn-decisions-widget__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 10px;
}
</style>
