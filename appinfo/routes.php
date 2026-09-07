<?php

/**
 * Decidiq route table.
 *
 * Adopts the OpenRegister AppHost canonical route table
 * ({@see \OCA\OpenRegister\AppHost\Routes::standard()}) for the mechanical
 * fleet-standard routes (dashboard page + SPA catch-all, settings API,
 * per-user preferences, the observability /api/health + /api/metrics
 * endpoints), and appends decidiq's domain routes via `$extra`.
 *
 * `$extra` routes are inserted before the SPA catch-all so they keep priority
 * over the `/{path}` fallback; an `$extra` route whose name matches a canonical
 * one overrides it (used here to add the publication-config sub-routes under
 * the canonical `settings#*` controller without re-declaring it).
 *
 * ⚠️ The AppHost builder is invoked through a `class_exists()` guard. This file
 * is `include`d by Nextcloud's router for EVERY decidiq request, so an
 * unguarded static call to a class in another app makes every route in the app
 * fatal with HTTP 500 when openregister is absent — not just the AppHost ones.
 * The `$fallback` branch below is a byte-equivalent local copy of
 * `Routes::standard()`'s output so decidiq still routes (and degrades
 * per-endpoint) without openregister. See decidiq#377.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.2
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

$extra = [
        // Publication configuration (publish-decisions-via-opencatalogi).
        // @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
        // First-time setup wizard (ADR-042) - the standard CnSetupWizard contract.
        ['name' => 'setup#status',    'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#runAction', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[a-z0-9\\-]+']],
        // A `choice` step POSTs its answer here before it advances; the
        // `run-action` step that follows reads it back, because runAction()
        // carries no body and so cannot carry the answer itself.
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',           'verb' => 'POST'],
        ['name' => 'settings#getPublicationConfig', 'url' => '/api/settings/publication-config', 'verb' => 'GET'],
        ['name' => 'settings#setPublicationConfig', 'url' => '/api/settings/publication-config', 'verb' => 'PUT'],

        // Publication action endpoints — publish/withdraw/rectify ONLY (ADR-022; CRUD stays on OR object API).
        // @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
        ['name' => 'publication#publish',  'url' => '/api/publications',                     'verb' => 'POST'],
        ['name' => 'publication#withdraw', 'url' => '/api/publications/{recordId}/withdraw', 'verb' => 'POST'],
        ['name' => 'publication#rectify',  'url' => '/api/publications/{recordId}/rectify',  'verb' => 'POST'],

        // Approval routes (approval-routes) — instantiate a sign-off route against
        // a subject, and record an action on it. Two DISTINCT route names: a
        // duplicate name is the route identifier colliding, which throws while
        // the table is built and takes every route in the app down with it.
        // @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
        ['name' => 'approvalRoute#instantiate', 'url' => '/api/approval-routes/instantiate', 'verb' => 'POST'],
        ['name' => 'approvalRoute#record',      'url' => '/api/approval-routes/actions',     'verb' => 'POST'],

        // Process template management (admin-only — AuthorizedAdminSetting on every method).
        // @spec openspec/specs/process-configuration/spec.md
        ['name' => 'processTemplate#index',     'url' => '/api/process-templates',                  'verb' => 'GET'],
        ['name' => 'processTemplate#validate',  'url' => '/api/process-templates/validate',         'verb' => 'POST'],
        ['name' => 'processTemplate#create',    'url' => '/api/process-templates',                  'verb' => 'POST'],
        ['name' => 'processTemplate#show',      'url' => '/api/process-templates/{id}',             'verb' => 'GET'],
        ['name' => 'processTemplate#update',    'url' => '/api/process-templates/{id}',             'verb' => 'PUT'],
        ['name' => 'processTemplate#duplicate', 'url' => '/api/process-templates/{id}/duplicate',   'verb' => 'POST'],
        ['name' => 'processTemplate#destroy',   'url' => '/api/process-templates/{id}',             'verb' => 'DELETE'],

        // Action items — VTODO write path (the action-item schema is a read-only
        // projection; the object API rejects writes). @spec action-items-vtodo-deck-reconcile.
        ['name' => 'actionItem#create',  'url' => '/api/action-items',       'verb' => 'POST'],
        ['name' => 'actionItem#update',  'url' => '/api/action-items/{uid}', 'verb' => 'PUT',    'requirements' => ['uid' => '[^/]+']],
        ['name' => 'actionItem#destroy', 'url' => '/api/action-items/{uid}', 'verb' => 'DELETE', 'requirements' => ['uid' => '[^/]+']],

        // Member import (admin-only — AuthorizedAdminSetting on every method).
        // @spec openspec/specs/admin-settings/spec.md
        ['name' => 'memberImport#groups',       'url' => '/api/member-import/groups',                   'verb' => 'GET'],
        ['name' => 'memberImport#groupMembers', 'url' => '/api/member-import/groups/{groupId}/members', 'verb' => 'GET'],
        ['name' => 'memberImport#match',        'url' => '/api/member-import/match',                    'verb' => 'POST'],

        // Analytics endpoint — personal action-item list only.
        // @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
        ['name' => 'analytics#getMyItems',             'url' => '/api/analytics/action-items/my-items',         'verb' => 'GET'],

        // Live meeting endpoints — live decision recording during active meetings.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
        ['name' => 'liveMeeting#recordLiveDecision', 'url' => '/api/meetings/{meetingId}/live-decisions', 'verb' => 'POST'],

        // Minutes endpoints — specific routes must precede the wildcard catch-all.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
        ['name' => 'minutes#generateDraft',   'url' => '/api/minutes/{minutesId}/generate-draft',  'verb' => 'POST'],
        ['name' => 'minutes#transition',      'url' => '/api/minutes/{minutesId}/transition',       'verb' => 'POST'],

        // ALV minutes endpoints.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
        ['name' => 'minutes#generateALVDraft',    'url' => '/api/minutes/{minutesId}/generate-alv', 'verb' => 'POST'],
        ['name' => 'minutes#distributeALVMinutes', 'url' => '/api/minutes/{minutesId}/distribute',  'verb' => 'POST'],

        // Action item extraction endpoints.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
        ['name' => 'minutes#extractActionItems',         'url' => '/api/minutes/{minutesId}/extract-action-items',              'verb' => 'POST'],
        ['name' => 'minutes#saveExtractedActionItems',   'url' => '/api/minutes/{minutesId}/save-extracted-action-items',       'verb' => 'POST'],

        // Minutes approval endpoints.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
        ['name' => 'minutes#submitForApproval',          'url' => '/api/minutes/{minutesId}/submit-for-approval',               'verb' => 'POST'],

        // Minutes approval workflow + document generation (minutes-ui-v1).
        // @spec openspec/specs/resolution-minutes/spec.md
        ['name' => 'minutesCorrection#addCorrection',     'url' => '/api/minutes/{minutesId}/corrections',                'verb' => 'POST'],
        ['name' => 'minutesCorrection#resolveCorrection', 'url' => '/api/minutes/{minutesId}/corrections/{correctionId}', 'verb' => 'PUT'],
        ['name' => 'minutes#reject',            'url' => '/api/minutes/{minutesId}/reject',                       'verb' => 'POST'],
        ['name' => 'minutes#generateDocument',  'url' => '/api/minutes/{minutesId}/generate-document',            'verb' => 'POST'],

        // Notarial proof package (minutes-ui-v1) — chair/secretary gated.
        // @spec openspec/specs/resolution-minutes/spec.md
        ['name' => 'meeting#proofPackage', 'url' => '/api/meetings/{id}/proof-package', 'verb' => 'POST'],

        // Meeting transcription action endpoints (meeting-transcription-ai-minutes).
        // @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
        ['name' => 'transcription#sources',         'url' => '/api/meetings/{meetingId}/transcription/sources', 'verb' => 'GET'],
        ['name' => 'transcription#attach',          'url' => '/api/meetings/{meetingId}/transcription/attach',  'verb' => 'POST'],
        ['name' => 'transcription#transcribe',      'url' => '/api/transcripts/{transcriptId}/transcribe',      'verb' => 'POST'],
        ['name' => 'transcription#realign',         'url' => '/api/transcripts/{transcriptId}/re-align',        'verb' => 'POST'],
        ['name' => 'transcription#generateDraft',   'url' => '/api/transcripts/{transcriptId}/generate-draft',  'verb' => 'POST'],
        ['name' => 'transcription#retentionConfig', 'url' => '/api/governance-bodies/{bodyId}/retention-config', 'verb' => 'PUT'],

        // Decision endpoints — server-side publish enforces governance access control.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
        ['name' => 'decision#publish', 'url' => '/api/decisions/{decisionId}/publish', 'verb' => 'POST'],

        // Decision lifecycle state machine (guarded transition map).
        // @spec openspec/specs/decision-management/spec.md
        ['name' => 'decision#transition',  'url' => '/api/decisions/{decisionId}/transition',  'verb' => 'POST'],
        ['name' => 'decision#transitions', 'url' => '/api/decisions/{decisionId}/transitions', 'verb' => 'GET'],

        // Meeting lifecycle transitions (CRUD is handled by OpenRegister's object API directly).
        ['name' => 'meeting#lifecycle', 'url' => '/api/meetings/{id}/lifecycle', 'verb' => 'POST'],

        // Recurring series generation + document package assembly
        // (meeting-agenda-gaps-v1). @spec openspec/specs/meeting-management/spec.md
        ['name' => 'meeting#createSeries',    'url' => '/api/meetings/{id}/series',  'verb' => 'POST'],
        ['name' => 'meeting#assemblePackage', 'url' => '/api/meetings/{id}/package', 'verb' => 'POST'],

        // Agenda lifecycle routes (task-1.3) — specific routes BEFORE wildcard catch-all.
        ['name' => 'agenda#publish',             'url' => '/api/agendas/{meetingId}/publish',      'verb' => 'POST'],
        ['name' => 'agenda#revise',              'url' => '/api/agendas/{meetingId}/revise',       'verb' => 'PUT'],
        ['name' => 'agenda#advanceBobPhase',     'url' => '/api/agenda-items/{id}/bob-phase',      'verb' => 'PUT'],
        ['name' => 'agenda#processHamerstukken', 'url' => '/api/agendas/{meetingId}/hamerstukken', 'verb' => 'POST'],
        ['name' => 'agenda#reorder',             'url' => '/api/agendas/{meetingId}/reorder',      'verb' => 'PUT'],

        // Motion lifecycle and co-signature routes (specific before wildcard).
        ['name' => 'motion#transition',     'url' => '/api/motions/{id}/transition',      'verb' => 'POST'],
        ['name' => 'motion#coSignRequest',  'url' => '/api/motions/{id}/co-sign-request', 'verb' => 'POST'],
        ['name' => 'motion#coSignConfirm',  'url' => '/api/motions/{id}/co-sign-confirm', 'verb' => 'POST'],
        ['name' => 'motion#budgetImpact',   'url' => '/api/motions/{id}/budget-impact',   'verb' => 'POST'],

        // Amendment lifecycle routes (specific before wildcard).
        ['name' => 'motion#amendmentTransition', 'url' => '/api/amendments/{id}/transition', 'verb' => 'POST'],

        // Chair-set amendment voting order — @spec openspec/specs/motion-amendment/spec.md.
        ['name' => 'motion#amendmentOrder', 'url' => '/api/motions/{id}/amendment-order', 'verb' => 'POST'],

        // Voting round routes (specific before wildcard).
        ['name' => 'voting#open',        'url' => '/api/voting-rounds',             'verb' => 'POST'],
        ['name' => 'voting#cast',        'url' => '/api/voting-rounds/{id}/cast',   'verb' => 'POST'],
        ['name' => 'voting#close',       'url' => '/api/voting-rounds/{id}/close',  'verb' => 'POST'],
        ['name' => 'voting#publish',     'url' => '/api/voting-rounds/{id}/publish','verb' => 'POST'],
        ['name' => 'voting#tally',       'url' => '/api/voting-rounds/{id}/tally',  'verb' => 'POST'],
        ['name' => 'voting#proxy',       'url' => '/api/voting-rounds/{id}/proxy',  'verb' => 'POST'],
        ['name' => 'voting#revokeProxy', 'url' => '/api/voting-rounds/{id}/proxy',  'verb' => 'DELETE'],

        // Voting behaviour (stats) routes — @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
        ['name' => 'votingBehaviour#getStats', 'url' => '/api/voting-behaviour/{participantId}', 'verb' => 'GET'],

        // Projection public-state routes — @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
        ['name' => 'projection#publicState', 'url' => '/api/voting-rounds/{id}/public-state', 'verb' => 'GET'],

        // Motion forwarding routes — @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
        ['name' => 'motion#forward', 'url' => '/api/motions/{id}/forward', 'verb' => 'POST'],

        // Governance services (retained from the retired board portal,
        // retargeted onto the unified entities per ADR-006).
        // Conflict-of-interest declarations.
        ['name' => 'conflictOfInterest#declare',     'url' => '/api/conflicts',                  'verb' => 'POST'],
        ['name' => 'conflictOfInterest#forMember',   'url' => '/api/members/{id}/conflicts',     'verb' => 'GET'],
        ['name' => 'conflictOfInterest#recordAction', 'url' => '/api/conflicts/{id}/action',     'verb' => 'PUT'],

        // Audit log (secretary/admin only — enforced inside controller).
        ['name' => 'auditLog#index',  'url' => '/api/audit-log',                'verb' => 'GET'],
        ['name' => 'auditLog#verify', 'url' => '/api/audit-log/{id}/verify',    'verb' => 'GET'],
        ['name' => 'auditLog#export', 'url' => '/api/audit-log/export',         'verb' => 'GET'],

        // eIDAS QES integration on minutes (task-3.3).
        ['name' => 'eIDASSignature#initiate',   'url' => '/api/minutes/{minutesId}/eidas/initiate',  'verb' => 'POST'],
        ['name' => 'eIDASSignature#verify',     'url' => '/api/minutes/{minutesId}/eidas/verify',    'verb' => 'POST'],
        ['name' => 'eIDASSignature#finalize',   'url' => '/api/minutes/{minutesId}/eidas/finalize',  'verb' => 'POST'],
        ['name' => 'eIDASSignature#certStatus', 'url' => '/api/eidas/validate-cert',                 'verb' => 'POST'],

        // Proxy voting (task-5.1).
        ['name' => 'proxyVote#register', 'url' => '/api/proxies',               'verb' => 'POST'],
        ['name' => 'proxyVote#index',    'url' => '/api/proxies',               'verb' => 'GET'],
        ['name' => 'proxyVote#suspend',  'url' => '/api/proxies/{id}/suspend',  'verb' => 'PUT'],
        ['name' => 'proxyVote#revoke',   'url' => '/api/proxies/{id}',          'verb' => 'DELETE'],

        // Board self-evaluation (board-self-evaluation).
        // @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md
        ['name' => 'boardEvaluation#respond', 'url' => '/api/board-evaluations/{id}/respond', 'verb' => 'POST'],
        ['name' => 'boardEvaluation#close',   'url' => '/api/board-evaluations/{id}/close',   'verb' => 'POST'],
        ['name' => 'boardEvaluation#publish', 'url' => '/api/board-evaluations/{id}/publish', 'verb' => 'POST'],
        ['name' => 'boardEvaluation#report',  'url' => '/api/board-evaluations/{id}/report',  'verb' => 'POST'],

        // Governance reporting (task-5.4).
        ['name' => 'governanceReport#generate', 'url' => '/api/governance-reports',                       'verb' => 'POST'],
        ['name' => 'governanceReport#index',    'url' => '/api/governance-reports',                       'verb' => 'GET'],
        ['name' => 'governanceReport#show',     'url' => '/api/governance-reports/{id}',                  'verb' => 'GET'],
        ['name' => 'governanceReport#export',   'url' => '/api/governance-reports/{id}/export/{format}',  'verb' => 'GET'],

        // Regulator export (task-6.1).
        ['name' => 'regulatorExport#generate', 'url' => '/api/regulator-exports',         'verb' => 'POST'],
        ['name' => 'regulatorExport#index',    'url' => '/api/regulator-exports',         'verb' => 'GET'],
        ['name' => 'regulatorExport#download', 'url' => '/api/regulator-exports/{id}',    'verb' => 'GET'],

        // Multilingual reconciliation (task-6.3).
        ['name' => 'multilingualReconciliation#queue',    'url' => '/api/multilingual/queue',          'verb' => 'POST'],
        ['name' => 'multilingualReconciliation#status',   'url' => '/api/multilingual/queue',          'verb' => 'GET'],
        ['name' => 'multilingualReconciliation#process',  'url' => '/api/multilingual/queue/process',  'verb' => 'POST'],

        // Public REST API — versioned v1 (REQ-API-001..004).
        // @spec openspec/changes/p4-integration/tasks.md#task-1
        // Legacy health endpoint — public, no auth. Re-pointed at the AppHost
        // engine via the decidiq HealthController subclass; kept on the
        // historical /api/v1/health URL for reverse-proxy probes (deprecation
        // window — see openspec/changes/adopt-apphost/tasks.md#task-2.3). The
        // canonical /api/health (health#index) comes from Routes::standard().
        // @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
        // Canonical /api/health re-declared here (identical to the entry
        // Routes::standard() would inject) so the decidiq HealthController
        // subclass route target is statically visible (gate-14); the $extra
        // override is behaviour-neutral. @spec openspec/changes/adopt-apphost/tasks.md#task-2.2
        ['name' => 'health#index',           'url' => '/api/health',     'verb' => 'GET'],
        ['name' => 'health#status',          'url' => '/api/v1/health',  'verb' => 'GET'],
        ['name' => 'health#statusOptions',   'url' => '/api/v1/health',  'verb' => 'OPTIONS'],
        // Decision-type registry (REQ-DCDH-002) — a LITERAL /api/v1 GET, so it
        // MUST precede the api#index wildcard below. Nextcloud matches in
        // declaration order; declared after the wildcard (where the rest of the
        // integration-hub group sits) the wildcard swallowed it and answered
        // 404 "Unknown resource", so the picker silently fell back to its 13
        // shipped types and an admin-added type never appeared. The exact trap
        // the write routes document further down.
        ['name' => 'decisionTypes#index',    'url' => '/api/v1/decision-types',  'verb' => 'GET'],
        // CORS preflight for the whole v1 surface — must precede the catch-all GET.
        ['name' => 'api#preflight',          'url' => '/api/v1/{resource}',      'verb' => 'OPTIONS', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'api#preflightItem',      'url' => '/api/v1/{resource}/{id}', 'verb' => 'OPTIONS', 'requirements' => ['resource' => '[a-z\-]+']],
        // Read-only public entity endpoints (REQ-API-002).
        ['name' => 'api#index',              'url' => '/api/v1/{resource}',      'verb' => 'GET', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'api#show',               'url' => '/api/v1/{resource}/{id}', 'verb' => 'GET', 'requirements' => ['resource' => '[a-z\-]+']],

        // ORI API 1.4 endpoints (REQ-ORI-001..004).
        // @spec openspec/changes/p4-integration/tasks.md#task-11
        ['name' => 'ori#preflight',     'url' => '/api/ori/v1/{resource}',      'verb' => 'OPTIONS', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'ori#preflightItem', 'url' => '/api/ori/v1/{resource}/{id}', 'verb' => 'OPTIONS', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'ori#index',         'url' => '/api/ori/v1/{resource}',      'verb' => 'GET', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'ori#show',          'url' => '/api/ori/v1/{resource}/{id}', 'verb' => 'GET', 'requirements' => ['resource' => '[a-z\-]+']],

        // Notification preference endpoints (own preferences).
        ['name' => 'notificationPreference#show',   'url' => '/api/notification-preference', 'verb' => 'GET'],
        ['name' => 'notificationPreference#update', 'url' => '/api/notification-preference', 'verb' => 'PUT'],

        // Engagement capture and query.
        ['name' => 'engagement#capture', 'url' => '/api/engagement', 'verb' => 'POST'],
        ['name' => 'engagement#index',   'url' => '/api/engagement', 'verb' => 'GET'],

        // Motion co-authoring (specific routes before motion wildcard).
        ['name' => 'motionCoauthor#addCoauthor',    'url' => '/api/motions/{id}/coauthors',            'verb' => 'POST'],
        ['name' => 'motionCoauthor#removeCoauthor', 'url' => '/api/motions/{id}/coauthors/{personId}', 'verb' => 'DELETE'],
        ['name' => 'motionCoauthor#updateText',     'url' => '/api/motions/{id}/text',                 'verb' => 'POST'],
        ['name' => 'motionCoauthor#history',        'url' => '/api/motions/{id}/history',              'verb' => 'GET'],

        // Integration hub endpoints — create-decision, outcome query, subscribe (REQ-DCDH-002..004).
        // decisionTypes#index belongs to this group but is declared ABOVE the
        // api#index wildcard, where a literal /api/v1 GET has to live.
        // @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
        ['name' => 'integration#createDecision', 'url' => '/api/v1/decisions',                   'verb' => 'POST'],
        ['name' => 'integration#getOutcome',     'url' => '/api/v1/decisions/{id}/outcome',       'verb' => 'GET'],
        ['name' => 'integration#subscribe',      'url' => '/api/v1/decisions/{id}/subscriptions', 'verb' => 'POST'],

        // Writes on the public REST surface. DECLARED AFTER the integration
        // routes above, and that ordering is load-bearing: Nextcloud matches in
        // declaration order, so a `{resource}` wildcard placed earlier would
        // capture `POST /api/v1/decisions` and answer "Unknown resource" where
        // integration#createDecision used to create one. The literal routes
        // must win.
        //
        // Only `governance-bodies` is accepted, enforced by
        // ApiController::RESOURCE_WRITABLE rather than by the route pattern — a
        // pattern naming the one resource would silently stop matching the day
        // another is added, and read as "no route" instead of "not writable".
        // Authorization is OpenRegister's RBAC; see the method.
        //
        // TWO NAMES, not one shared `api#write`. A route `name` becomes the route
        // IDENTIFIER (`decidiq.api.write`), so declaring it twice collides — and
        // it does not fail locally at the duplicate. It throws out of
        // Routes::standard() while the table is built, which takes down EVERY
        // route in the app: measured, the whole /api/v1 surface answered 500,
        // including endpoints this change never touched.
        ['name' => 'api#create',             'url' => '/api/v1/{resource}',      'verb' => 'POST', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'api#update',             'url' => '/api/v1/{resource}/{id}', 'verb' => 'PUT',  'requirements' => ['resource' => '[a-z\-]+']],

        // Citizen-participation ACTION endpoints (lifecycle, intake, moderation, voting,
        // publication). Plain CRUD stays on the OpenRegister object API per ADR-022.
        // @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
        ['name' => 'participation#transitionConsultation',     'url' => '/api/participation/consultations/{consultationId}/transition',       'verb' => 'POST'],
        ['name' => 'participation#submitReaction',             'url' => '/api/participation/consultations/{consultationId}/reactions',        'verb' => 'POST'],
        ['name' => 'participation#submitAnonymousReaction',    'url' => '/api/participation/public/consultations/{consultationId}/reactions', 'verb' => 'POST'],
        ['name' => 'participation#publishConsultationResults', 'url' => '/api/participation/consultations/{consultationId}/publish',          'verb' => 'POST'],
        ['name' => 'participation#approveReaction',            'url' => '/api/participation/reactions/{reactionId}/approve',                  'verb' => 'POST'],
        ['name' => 'participation#rejectReaction',             'url' => '/api/participation/reactions/{reactionId}/reject',                   'verb' => 'POST'],
        ['name' => 'participation#publishReaction',            'url' => '/api/participation/reactions/{reactionId}/publish',                  'verb' => 'POST'],
        // Participatory budget — same URLs, handled by ParticipationBudgetController.
        ['name' => 'participationBudget#transitionBudgetRound', 'url' => '/api/participation/budgets/{budgetId}/transition',                  'verb' => 'POST'],
        ['name' => 'participationBudget#submitProposal',        'url' => '/api/participation/budgets/{budgetId}/proposals',                   'verb' => 'POST'],
        ['name' => 'participationBudget#publishBudgetResults',  'url' => '/api/participation/budgets/{budgetId}/publish',                     'verb' => 'POST'],
        ['name' => 'participationBudget#validateProposal',      'url' => '/api/participation/proposals/{proposalId}/validate',                'verb' => 'POST'],
        ['name' => 'participationBudget#castAdvisoryVote',      'url' => '/api/participation/proposals/{proposalId}/vote',                    'verb' => 'POST'],
];

// Preferred path: the OpenRegister AppHost owns the canonical route table.
// `class_exists()` autoloads without fatalling when the class is unavailable.
if (class_exists('OCA\OpenRegister\AppHost\Routes') === true) {
    return \OCA\OpenRegister\AppHost\Routes::standard($extra);
}

// Fallback: openregister is not installed. Reproduce `Routes::standard()`
// locally — canonical routes first (minus any name `$extra` overrides), then
// `$extra`, then the SPA catch-all LAST so it never shadows a real route.
$canonicalRoutes = [
    ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
    ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
    ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
    ['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'POST'],
    ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
    ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],
    ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
    ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],
];

$catchAllRoute = [
    'name'         => 'dashboard#catchAll',
    'url'          => '/{path}',
    'verb'         => 'GET',
    'requirements' => ['path' => '.+'],
    'defaults'     => ['path' => ''],
];

$extraNames = [];
foreach ($extra as $extraRoute) {
    if (isset($extraRoute['name']) === true) {
        $extraNames[(string) $extraRoute['name']] = true;
    }
}

$mergedRoutes = [];
foreach ($canonicalRoutes as $canonicalRoute) {
    if (isset($extraNames[$canonicalRoute['name']]) === true) {
        continue;
    }

    $mergedRoutes[] = $canonicalRoute;
}

$mergedRoutes   = array_merge($mergedRoutes, $extra);
$mergedRoutes[] = $catchAllRoute;

return ['routes' => $mergedRoutes];
