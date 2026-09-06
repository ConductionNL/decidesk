/**
 * Decidiq v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. CnPageRenderer
 * resolves each manifest page's `component` string against entries whose
 * `kind === "page"` (with precedence over the deprecated `customComponents`
 * prop, which decidiq no longer ships).
 *
 * Each entry that is a bespoke page or detail-tab component is wrapped with
 * `page()` so CnPageRenderer can distinguish it from future `"widget"`,
 * `"modal"`, `"form-field"` or `"cell-renderer"` entries that share the
 * same registry map.
 *
 * Genuine exceptions kept here:
 *   - `LiveMeetingView` — realtime WebSocket shell; no abstract manifest
 *     primitive exists yet (documented as the canonical example for a
 *     future `type: "realtime"` lib extension).
 *   - Detail-tab components — one per cross-schema relation; resolution
 *     happens inside each component rather than the renderer, per the
 *     manifest-abstract-sidebar contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import ActionItemsSurface from './components/tabs/ActionItemsSurface.vue'
import AgendaMotionsTab from './components/tabs/AgendaMotionsTab.vue'
import AgendaPublicationTab from './components/tabs/AgendaPublicationTab.vue'
import AmendmentDiffTab from './components/tabs/AmendmentDiffTab.vue'
import AmendmentParentMotionTab from './components/tabs/AmendmentParentMotionTab.vue'
import ConsultationReactionsTab from './components/tabs/ConsultationReactionsTab.vue'
import DecisionActionItemsTab from './components/tabs/DecisionActionItemsTab.vue'
import DecisionLifecycleTab from './components/tabs/DecisionLifecycleTab.vue'
// Public-publication tabs (publish-decisions-via-opencatalogi): publish /
// withdraw / rectify actions on the decision, meeting (agenda), and minutes
// detail views. Three thin wrappers around the shared PublicationActionsTab.
import DecisionPublicationTab from './components/tabs/DecisionPublicationTab.vue'
import DecisionRouteTab from './components/tabs/DecisionRouteTab.vue'
import DecisionVotingTab from './components/tabs/DecisionVotingTab.vue'
import GovernanceBodyEfficiencyTab from './components/tabs/GovernanceBodyEfficiencyTab.vue'
import GovernanceBodyEvaluationsTab from './components/tabs/GovernanceBodyEvaluationsTab.vue'
import GovernanceBodyMembersTab from './components/tabs/GovernanceBodyMembersTab.vue'
import GovernanceBodyRetentionTab from './components/tabs/GovernanceBodyRetentionTab.vue'
import GovernanceBodyTemplateTab from './components/tabs/GovernanceBodyTemplateTab.vue'
import MeetingAgendaTab from './components/tabs/MeetingAgendaTab.vue'
// Meeting-scoped facet composition (meeting-facet-composition): kascommissie
// verklaringen (VvE mode-gated) and incoming documents routed onto this
// meeting's agenda (two-hop join). See design.md Decisions 3/4 for why each
// needs a thin wrapper rather than a pure declarative object-list widget.
import MeetingAuditStatementTab from './components/tabs/MeetingAuditStatementTab.vue'
import MeetingDecisionsTab from './components/tabs/MeetingDecisionsTab.vue'
import MeetingMinutesTab from './components/tabs/MeetingMinutesTab.vue'
import MeetingParticipantsTab from './components/tabs/MeetingParticipantsTab.vue'
import MeetingRoutedDocumentsTab from './components/tabs/MeetingRoutedDocumentsTab.vue'
import MeetingSeriesTab from './components/tabs/MeetingSeriesTab.vue'
import MeetingTranscriptionTab from './components/tabs/MeetingTranscriptionTab.vue'
import MeetingVotesTab from './components/tabs/MeetingVotesTab.vue'
import MinutesApprovalTab from './components/tabs/MinutesApprovalTab.vue'
import MinutesDocumentTab from './components/tabs/MinutesDocumentTab.vue'
import MinutesPublicationTab from './components/tabs/MinutesPublicationTab.vue'
import MinutesSignersTab from './components/tabs/MinutesSignersTab.vue'
import MotionAmendmentOrderTab from './components/tabs/MotionAmendmentOrderTab.vue'
import MotionAmendmentsTab from './components/tabs/MotionAmendmentsTab.vue'
import MotionVotesTab from './components/tabs/MotionVotesTab.vue'
import MotionVotingRoundTab from './components/tabs/MotionVotingRoundTab.vue'
import RelatedDecisionsTab from './components/tabs/RelatedDecisionsTab.vue'
import DecisionFormDialog from './dialogs/DecisionFormDialog.vue'
import ActiveDecisionsKpiWidget from './views/dashboard/widgets/ActiveDecisionsKpiWidget.vue'
// Dashboard v2 widgets (decidesk-dashboard-v2-widgets). Bespoke CnDashboardPage
// slot components registered under kind: "widget".
//
// The follow-up config change decidesk-dashboard-v2-layout was supposed to
// reference them from src/manifest.json, and only ever did so for six of them.
// The other six .vue files sat in the tree ORPHANED: never imported here, never
// named by a `slots` entry, therefore never mounted and never rendered — while
// openspec/specs/dashboard/spec.md specifies each one by id, type and slot name.
// A component that no slot maps to produces no markup and no warning, so the
// dashboard simply rendered fewer widgets than the spec requires and nothing
// anywhere said so. All twelve are registered below; manifest.json now carries
// the matching `slots` entries.
import CreateMeetingAction from './views/dashboard/widgets/CreateMeetingAction.vue'
import DashboardEmptyState from './views/dashboard/widgets/DashboardEmptyState.vue'
import DashboardQuickActions from './views/dashboard/widgets/DashboardQuickActions.vue'
import GovernanceHealthWidget from './views/dashboard/widgets/GovernanceHealthWidget.vue'
import MyActionItemsWidget from './views/dashboard/widgets/MyActionItemsWidget.vue'
import OverdueActionsKpiWidget from './views/dashboard/widgets/OverdueActionsKpiWidget.vue'
import PendingVotesKpiWidget from './views/dashboard/widgets/PendingVotesKpiWidget.vue'
import PendingVotesListWidget from './views/dashboard/widgets/PendingVotesListWidget.vue'
import RecentDecisionsWidget from './views/dashboard/widgets/RecentDecisionsWidget.vue'
import RunningProcessesWidget from './views/dashboard/widgets/RunningProcessesWidget.vue'
import StartProcessAction from './views/dashboard/widgets/StartProcessAction.vue'
import UpcomingMeetingsKpiWidget from './views/dashboard/widgets/UpcomingMeetingsKpiWidget.vue'
import UpcomingMeetingsListWidget from './views/dashboard/widgets/UpcomingMeetingsListWidget.vue'
import LiveMeetingView from './views/LiveMeeting.vue'
import MeetingCalendarView from './views/meetings/MeetingCalendarView.vue'
import MeetingViewToggle from './views/meetings/MeetingViewToggle.vue'
import MotionIntegrations from './views/MotionIntegrations.vue'
import ModerationQueuePage from './views/participation/ModerationQueuePage.vue'
// Citizen-participation pages (citizen-participation). The consultation/budget
// list+detail pages are auto-rendered by CnPageRenderer from the manifest
// schema config; these two are bespoke action surfaces (citizen + staff
// participation, and the staff moderation queue).
import ParticipationPage from './views/participation/ParticipationPage.vue'
// User settings (user-settings-v1): in-app mount of the personal settings
// sections (notification / display / delegation / communication). The
// canonical mount is the Nextcloud personal settings panel (ISettings).
import UserSettingsPage from './views/settings/UserSettingsPage.vue'

/**
 * Wrap a Vue component into the v2 registry shape required by CnAppRoot's
 * `registry` prop (`kind: "page"` is the discriminator CnPageRenderer keys
 * page dispatch off — `kind: "widget"`/`"modal"`/`"form-field"`/
 * `"cell-renderer"` entries with the same name are NOT used for page
 * dispatch).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "page", component }` registry entry.
 */
function page(component) {
	return { kind: 'page', component }
}

/**
 * Wrap a Vue component into a v2 `kind: "widget"` registry entry.
 *
 * CnAppRoot validates every `widget` entry at mount: an unknown `kind`
 * throws, but a known kind with a missing metadata field only `console.warn`s
 * (see CnAppRoot `_validateRegistry` / `REGISTRY_KIND_REQUIRED_FIELDS.widget`).
 * The five required fields are therefore always supplied:
 *   - `defaultSize` / `minSize` / `maxSize` — `{ w, h }` grid spans the
 *     dashboard layout engine clamps the widget to.
 *   - `allowedSlots` — page-slot regions the widget may be placed in.
 *   - `propsSchema` — JSON-schema-ish description of the widget's
 *     manifest-tunable props (empty `{}` when the widget self-fetches and
 *     takes no manifest props).
 *
 * @param {object} component Vue component options.
 * @param {object} [meta] Size / slot / props metadata overrides.
 *
 * @return {object} A `{ kind: "widget", component, ...meta }` registry entry.
 */
function widget(component, meta = {}) {
	return {
		kind: 'widget',
		component,
		defaultSize: meta.defaultSize || { w: 3, h: 2 },
		minSize: meta.minSize || { w: 2, h: 2 },
		maxSize: meta.maxSize || { w: 12, h: 8 },
		allowedSlots: meta.allowedSlots || ['dashboard'],
		propsSchema: meta.propsSchema || {},
	}
}

export default {
	// --- Genuine exception: realtime UI, no abstract analogue. ---
	// Live meeting shell — WebSocket subscriptions, frame-by-frame UI,
	// per-vote-card animations. Documented as the canonical example for
	// a future `type: "realtime"` lib extension.
	LiveMeetingView: page(LiveMeetingView),

	// --- Meeting calendar (configurable-types-domain-model). ---
	// A month view alongside the manifest index table. CnIndexPage's
	// viewMode validator accepts table/cards/list/map only, so `calendar`
	// cannot be a shared view mode without editing
	// @conduction/nextcloud-vue — which a leaf app must not fork. The
	// toggle rides the index page's actionsComponent slot and routes
	// between the two pages, leaving the table surface untouched.
	MeetingCalendarView: page(MeetingCalendarView),
	MeetingViewToggle: page(MeetingViewToggle),

	// --- Integration-registry surfaces (ADR-019 / ADR-022). ---
	// Mounts CnDetailPage in `useRegistry` mode bound to its OR object so
	// every registered integration provider — including the Talk leaf —
	// surfaces as a tab. Replaces the retired in-app linking surfaces.
	//
	// ONLY the motion page is registered here, and that is not an omission.
	// A registry entry is consulted for a manifest page of `type: "custom"`.
	// The decision and agenda-item integration pages are `type: "detail"`
	// with a full declarative body (`config.widgets` + `config.layout`), and
	// the renderer builds THAT and never resolves `component` — measured in
	// the browser: both routes rendered their manifest widgets and neither
	// `.vue` root testid ever appeared. Registering a component there does
	// not make it render; it only makes a dead file look reachable. The two
	// `.vue` files were deleted for that reason.
	//
	// The inverse mistake is recorded here too, because it is one line away:
	// this motion page's component was once named by the manifest and never
	// registered, so resolution fell through and the page rendered NOTHING.
	// `type: "custom"` needs the registration; `type: "detail"` ignores it.
	MotionIntegrations: page(MotionIntegrations),

	// --- Detail-tab components (one per cross-schema relation). ---
	// Each lives in /components/tabs/. Full-CRUD (or read-only where
	// authoring lives elsewhere — votes are LiveMeeting-only by design).
	// Cross-schema lookups resolve inside each component rather than the
	// renderer, per the manifest-abstract-sidebar contract.
	ConsultationReactionsTab: page(ConsultationReactionsTab),
	GovernanceBodyMembersTab: page(GovernanceBodyMembersTab),
	GovernanceBodyTemplateTab: page(GovernanceBodyTemplateTab),
	// Meeting-efficiency analytics tab (meeting-efficiency): per-body duration
	// trend, agenda completion, speaking distribution, cost trend and time
	// allocation accuracy, all computed client-side from OR objects.
	GovernanceBodyEfficiencyTab: page(GovernanceBodyEfficiencyTab),
	// Board self-evaluation results/respond tab (board-self-evaluation):
	// anonymous respond flow, per-dimension/overall score bars with
	// small-body suppression, and close/publish/report actions.
	GovernanceBodyEvaluationsTab: page(GovernanceBodyEvaluationsTab),
	MeetingAgendaTab: page(MeetingAgendaTab),
	MeetingParticipantsTab: page(MeetingParticipantsTab),
	// Recurring-series generation (meeting-agenda-gaps-v1): pattern form,
	// preview count, generate action, and the series instance list.
	MeetingSeriesTab: page(MeetingSeriesTab),
	// Per-meeting authoring/overview tabs (refactor-decidesk-ia-alignment):
	// Minutes + Decisions are create+browse (split with the top-level
	// register index pages); Votes is the read-only post-meeting aggregate.
	MeetingMinutesTab: page(MeetingMinutesTab),
	MeetingTranscriptionTab: page(MeetingTranscriptionTab),
	GovernanceBodyRetentionTab: page(GovernanceBodyRetentionTab),
	MeetingDecisionsTab: page(MeetingDecisionsTab),
	MeetingVotesTab: page(MeetingVotesTab),
	// Meeting-scoped facet composition (meeting-facet-composition): mode-gated
	// kascommissie facet + the routed-incoming-documents two-hop join.
	MeetingAuditStatementTab: page(MeetingAuditStatementTab),
	MeetingRoutedDocumentsTab: page(MeetingRoutedDocumentsTab),
	AgendaMotionsTab: page(AgendaMotionsTab),
	MotionAmendmentsTab: page(MotionAmendmentsTab),
	// Chair-controlled amendment voting order (motion-amendment spec).
	MotionAmendmentOrderTab: page(MotionAmendmentOrderTab),
	MotionVotesTab: page(MotionVotesTab),
	MotionVotingRoundTab: page(MotionVotingRoundTab),
	AmendmentParentMotionTab: page(AmendmentParentMotionTab),
	// Visual diff against the parent motion text (motion-amendment spec).
	AmendmentDiffTab: page(AmendmentDiffTab),
	MinutesSignersTab: page(MinutesSignersTab),
	// Minutes approval workflow + document generation (minutes-ui-v1):
	// lifecycle timeline with guarded submit/approve/reject actions and
	// participant correction suggestions; document generation into the
	// meeting Files folder plus the notarial proof package trigger.
	MinutesApprovalTab: page(MinutesApprovalTab),
	MinutesDocumentTab: page(MinutesDocumentTab),
	DecisionActionItemsTab: page(DecisionActionItemsTab),
	// action-item-deck-board: surface switch — Deck-board projection (real
	// Nextcloud Deck cards via the OR leaf) when Deck is installed, else the
	// table tab. The manifest decision action-items tab points here.
	ActionItemsSurface: page(ActionItemsSurface),

	// --- Form-dialog slot replacement (decision-types-as-configuration). ---
	// Mounted into CnIndexPage's `form-dialog` slot on the Decisions and
	// Motions pages (each page's `slots` map). Same CnFormDialog over the
	// same schema as the built-in dialog, except the decisionType picker is
	// fed from the `decision_types` registry via the shared decisionLink.js
	// helpers (#1104) — the stored schema deliberately carries no enum since
	// #1099, so the built-in picker rendered "No results". Registered with
	// page() because slot resolution (resolveRegistryName) ignores `kind`;
	// what matters is that the kind is one CnAppRoot's validator knows.
	DecisionFormDialog: page(DecisionFormDialog),

	// --- Dashboard v2 widgets (decidesk-dashboard-v2-widgets). ---
	// Eleven CnDashboardPage slot components. CnPageRenderer / CnWidgetGrid
	// resolve these by name once decidesk-dashboard-v2-layout references them
	// from the Dashboard page's `widgets` array. Each self-fetches its data
	// via src/services/dashboardData.js + dashboardRefreshMixin, so propsSchema
	// is empty. KPI cards are small (3×2); list/chart widgets and the empty
	// state span wider.
	PendingVotesKpiWidget: widget(PendingVotesKpiWidget, {
		defaultSize: { w: 3, h: 2 },
		minSize: { w: 2, h: 2 },
		maxSize: { w: 4, h: 3 },
		allowedSlots: ['dashboard', 'kpi'],
	}),
	// The other three KPI cards of the spec'd four-card Row 1. Same geometry as
	// PendingVotesKpiWidget above — they are the same kind of card.
	ActiveDecisionsKpiWidget: widget(ActiveDecisionsKpiWidget, {
		defaultSize: { w: 3, h: 2 },
		minSize: { w: 2, h: 2 },
		maxSize: { w: 4, h: 3 },
		allowedSlots: ['dashboard', 'kpi'],
	}),
	UpcomingMeetingsKpiWidget: widget(UpcomingMeetingsKpiWidget, {
		defaultSize: { w: 3, h: 2 },
		minSize: { w: 2, h: 2 },
		maxSize: { w: 4, h: 3 },
		allowedSlots: ['dashboard', 'kpi'],
	}),
	OverdueActionsKpiWidget: widget(OverdueActionsKpiWidget, {
		defaultSize: { w: 3, h: 2 },
		minSize: { w: 2, h: 2 },
		maxSize: { w: 4, h: 3 },
		allowedSlots: ['dashboard', 'kpi'],
	}),
	// List / chart widgets — same geometry as the already-registered
	// PendingVotesListWidget they share a row with.
	UpcomingMeetingsListWidget: widget(UpcomingMeetingsListWidget, {
		defaultSize: { w: 6, h: 4 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['dashboard'],
	}),
	RecentDecisionsWidget: widget(RecentDecisionsWidget, {
		defaultSize: { w: 12, h: 4 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['dashboard'],
	}),
	GovernanceHealthWidget: widget(GovernanceHealthWidget, {
		defaultSize: { w: 6, h: 4 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['dashboard'],
	}),
	PendingVotesListWidget: widget(PendingVotesListWidget, {
		defaultSize: { w: 6, h: 4 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['dashboard'],
	}),
	RunningProcessesWidget: widget(RunningProcessesWidget, {
		defaultSize: { w: 6, h: 4 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['dashboard'],
	}),
	MyActionItemsWidget: widget(MyActionItemsWidget, {
		defaultSize: { w: 6, h: 4 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['dashboard'],
	}),
	DashboardEmptyState: widget(DashboardEmptyState, {
		defaultSize: { w: 12, h: 5 },
		minSize: { w: 6, h: 4 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['dashboard', 'full'],
	}),
	CreateMeetingAction: widget(CreateMeetingAction, {
		defaultSize: { w: 1, h: 1 },
		minSize: { w: 1, h: 1 },
		maxSize: { w: 2, h: 1 },
		allowedSlots: ['dashboard'],
	}),
	StartProcessAction: widget(StartProcessAction, {
		defaultSize: { w: 1, h: 1 },
		minSize: { w: 1, h: 1 },
		maxSize: { w: 2, h: 1 },
		allowedSlots: ['dashboard'],
	}),
	DashboardQuickActions: widget(DashboardQuickActions, {
		defaultSize: { w: 12, h: 1 },
		minSize: { w: 3, h: 1 },
		maxSize: { w: 12, h: 1 },
		allowedSlots: ['dashboard'],
	}),

	// Decision state machine (decision-state-machine-v1): lifecycle
	// timeline + guarded transition buttons, and the read-only
	// decision → motion → voting-round → vote results aggregate.
	DecisionLifecycleTab: page(DecisionLifecycleTab),
	DecisionRouteTab: page(DecisionRouteTab),
	DecisionVotingTab: page(DecisionVotingTab),
	RelatedDecisionsTab: page(RelatedDecisionsTab),

	// Public-publication action tabs (publish-decisions-via-opencatalogi).
	DecisionPublicationTab: page(DecisionPublicationTab),
	AgendaPublicationTab: page(AgendaPublicationTab),
	MinutesPublicationTab: page(MinutesPublicationTab),

	// --- User settings (user-settings-v1). ---
	// Four personal-preference sections; per-user REST endpoints only.
	UserSettingsPage: page(UserSettingsPage),

	// --- Citizen participation (citizen-participation). ---
	// Bespoke action surfaces: the citizen+staff participation view (reaction
	// form, proposal form, advisory voting cards, staff lifecycle/publish) and
	// the staff reaction moderation queue.
	ParticipationPage: page(ParticipationPage),
	ModerationQueuePage: page(ModerationQueuePage),
}
