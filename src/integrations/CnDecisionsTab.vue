<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CnDecisionsTab — "Besluitvorming" sidebar tab for the
 `decidesk-decisions` integration leaf (ADR-019 / ADR-022).

 The sidebar counterpart of CnDecisionsWidget. Renders the decidiq
 decisions linked to the host object as NcListItem rows grouped by kind
 (Proposals / Advice / Decisions), each deep-linking to decidiq and
 carrying an "Open in decidiq" row action. A header "Create proposal"
 button opens an in-tab CnFormDialog that creates a decision pre-linked
 to the host object via the Decision schema's `subjectId` back-reference
 (decidiq-contract-decision-hub, REQ-DCDH-001).

 Generic by construction: the host object identity arrives through the
 registry-supplied props (register / schema / objectId); the tab never
 hard-codes procest. Read-mostly + link-out: deliberation, voting and
 publication stay in decidiq.
-->
<template>
	<div class="cn-sidebar-tab cn-decisions-tab" data-testid="decisions-leaf-tab">
		<div class="cn-decisions-tab__actions">
			<NcButton
				variant="primary"
				:disabled="!hostObjectId"
				@click="openCreate">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ createProposalLabel }}
			</NcButton>
			<NcButton variant="secondary" @click="openApp">
				<template #icon>
					<OpenInNew :size="18" />
				</template>
				{{ openInDecidiqLabel }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="cn-decisions-tab__error" role="alert">
			{{ error }}
		</div>

		<div
			v-else-if="decisions.length === 0"
			class="cn-sidebar-tab__empty cn-decisions-tab__empty">
			<Gavel :size="32" class="cn-decisions-tab__empty-icon" />
			<p>{{ emptyLabel }}</p>
		</div>

		<template v-else>
			<div
				v-for="group in nonEmptyGroups"
				:key="group.key"
				class="cn-decisions-tab__group">
				<h4 class="cn-decisions-tab__group-title">
					{{ group.label }}
					<span class="cn-decisions-tab__group-count"
						>({{ group.items.length }})</span
					>
				</h4>
				<ul class="cn-decisions-tab__list">
					<NcListItem
						v-for="decision in group.items"
						:key="rowKey(decision)"
						:name="rowTitle(decision)"
						:bold="true"
						:href="decisionUrl(decision)"
						target="_blank"
						:forceDisplayActions="true">
						<template #icon>
							<span class="cn-decisions-tab__row-icon">
								<Gavel :size="20" />
							</span>
						</template>
						<template #subname>
							<span class="cn-decisions-tab__sub">
								<CnStatusBadge
									size="small"
									:variant="lifecycleVariant(decision)"
									:label="lifecycleLabel(decision)" />
								<span
									v-if="decisionDate(decision)"
									class="cn-decisions-tab__when">
									· {{ decisionDate(decision) }}
								</span>
							</span>
						</template>
						<template #actions>
							<NcActionButton
								:closeAfterClick="true"
								@click="openDecision(decision)">
								<template #icon>
									<OpenInNew :size="20" />
								</template>
								{{ openInDecidiqLabel }}
							</NcActionButton>
						</template>
					</NcListItem>
				</ul>
			</div>
		</template>

		<CnFormDialog
			v-if="createOpen"
			:schema="createSchema"
			:dialogTitle="createProposalLabel"
			@close="createOpen = false"
			@confirm="onCreate" />
	</div>
</template>

<script>
import { CnFormDialog, CnStatusBadge } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcActionButton, NcButton, NcListItem, NcLoadingIcon } from '@nextcloud/vue'
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

/**
 * CnDecisionsTab — "Besluitvorming" sidebar tab for the
 * `decidesk-decisions` leaf. See the file-level docblock for behaviour
 * and the case-linking mechanism.
 */
export default {
	name: 'CnDecisionsTab',

	components: {
		CnStatusBadge,
		CnFormDialog,
		NcActionButton,
		NcButton,
		NcListItem,
		NcLoadingIcon,
		Gavel,
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
		/** Whole integration context — fallback when discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
		/** Human label for the host object (stored as subjectLabel on created decisions). */
		objectLabel: { type: String, default: '' },
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

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 link-out to decidiq. */
		appUrl() {
			return generateUrl('/apps/decidiq/decisions')
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

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — group host decisions by kind. */
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
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — format decision date for display.
		 */
		decisionDate(decision) {
			const raw = decision?.decisionDate ?? decision?.data?.decisionDate ?? ''
			if (!raw) {
				return ''
			}
			const d = new Date(raw)
			return Number.isNaN(d.getTime())
				? ''
				: d.toLocaleDateString(undefined, { dateStyle: 'medium' })
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

		/**
		 * @param decision
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 open a decision in decidiq.
		 */
		openDecision(decision) {
			if (typeof window !== 'undefined') {
				window.open(this.decisionUrl(decision), '_blank', 'noopener')
			}
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
.cn-decisions-tab {
	padding: 12px;
	overflow-x: hidden;
}

.cn-decisions-tab__actions {
	display: flex;
	gap: 8px;
	margin-bottom: 10px;
	flex-wrap: wrap;
}

.cn-decisions-tab__error {
	color: var(--color-error);
	font-size: 0.9em;
	margin: 4px 0 8px;
}

.cn-decisions-tab__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 16px 8px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.cn-decisions-tab__empty-icon {
	color: var(--color-text-maxcontrast);
}

.cn-decisions-tab__group {
	margin-bottom: 12px;
}

.cn-decisions-tab__group-title {
	margin: 6px 0 4px;
	font-size: 0.85em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.cn-decisions-tab__group-count {
	font-weight: normal;
}

.cn-decisions-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.cn-decisions-tab__row-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-primary-element-light, var(--color-background-hover));
	color: var(--color-primary-element, #0082c9);
}

.cn-decisions-tab__sub {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	min-width: 0;
}

.cn-decisions-tab__when {
	white-space: nowrap;
}
</style>
