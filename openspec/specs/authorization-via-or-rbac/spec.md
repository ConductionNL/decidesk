# authorization-via-or-rbac Specification

## Purpose
TBD - created by archiving change consume-or-rbac-authorization. Update Purpose after archive.
## Requirements
### Requirement: REQ-RBAC-001 Governance-body roles project into OpenRegister RBAC scopes
decidiq SHALL maintain, per GovernanceBody, two OpenRegister RBAC scopes derived from the body's
member roles: a **chair scope** (`decidesk:body:{bodyId}:chair`) containing members whose role is
`chair` or `chairman`, and a **signatory scope** (`decidesk:body:{bodyId}:signatory`) containing
members whose role is `chair`, `chairman`, `vice-chairman`, or `secretary`. A role-projection hook
SHALL reconcile both scopes to the body's current roster whenever a member (Participant/Membership)
of that body is created, updated, or removed. Reconciliation SHALL be idempotent and SHALL fail
closed (an unresolved or empty scope denies rather than over-grants).

#### Scenario: Adding a chair populates both scopes
- **GIVEN** a GovernanceBody with no chair
- **WHEN** a member of that body is given the role `chair`
- **THEN** that member is present in both `decidesk:body:{bodyId}:chair` and
  `decidesk:body:{bodyId}:signatory`.

@e2e exclude backend role→scope projection with no distinct UI flow; the scope-group membership is maintained by GovernanceRoleScopeProjector on a roster write and is unit-proven in GovernanceRoleScopeProjectorTest (chair→both scopes).

#### Scenario: Secretary is a signatory but not a chair
- **GIVEN** a GovernanceBody
- **WHEN** a member is given the role `secretary`
- **THEN** that member is present in `decidesk:body:{bodyId}:signatory`
- **AND** that member is NOT present in `decidesk:body:{bodyId}:chair`.

@e2e exclude backend projection set-membership assertion with no distinct UI flow; unit-proven in GovernanceRoleScopeProjectorTest (secretary→signatory-only).

#### Scenario: Removing a role reconciles the scopes idempotently
- **GIVEN** a member currently in a body's chair scope
- **WHEN** that member's chair role is removed and the projection runs (or re-runs)
- **THEN** the member is absent from both scopes
- **AND** re-running the projection produces no further change.

@e2e exclude backend idempotent-reconcile invariant with no distinct UI flow; unit-proven in GovernanceRoleScopeProjectorTest (removal reconciles + re-run is a no-op).

### Requirement: REQ-RBAC-002 Signatory authorization is an OpenRegister RBAC rule, not an app-local service
Initiating a qualified e-signature (QES) on a Minutes record (and on a resolution `decision`) SHALL
be authorized by an OpenRegister property-RBAC rule that write-gates the signing-request property to
the owning body's `signatory` scope. The app-local `MinutesAuthorizationService` SHALL be removed and
SHALL NOT be reintroduced; the signing controller SHALL rely on OpenRegister returning a
403/`null` for a non-signatory. No app-local `*AuthorizationService` or `*PermissionService` for
decidiq OpenRegister objects SHALL remain.

#### Scenario: A signatory may initiate signing
- **GIVEN** a Minutes record on a body and a user in that body's `signatory` scope
- **WHEN** the user initiates QES signing
- **THEN** OpenRegister authorizes the signing-request write and the signing flow starts.

@e2e exclude needs a second account acting through a CSRF-bearing session plus a governance body whose role scopes the projector has populated, and tests/e2e/workflows/rbac-authorization-workflow.spec.ts skips it for that reason rather than pretending to cover it. Unit-proven in EIDASSignatureControllerTest (signatory allowed) and GovernanceScopeGuardTest.

#### Scenario: A non-signatory is denied by OpenRegister
- **GIVEN** a Minutes record on a body and a user NOT in that body's `signatory` scope
- **WHEN** the user attempts to initiate QES signing
- **THEN** OpenRegister denies the signing-request write (403)
- **AND** no app-local authorization service is consulted.

@e2e exclude needs a second account acting through a CSRF-bearing session plus a governance body whose role scopes the projector has populated, and tests/e2e/workflows/rbac-authorization-workflow.spec.ts skips it for that reason rather than pretending to cover it. Unit-proven in EIDASSignatureControllerTest (non-signatory denied) and GovernanceScopeGuardTest.

#### Scenario: The anti-pattern gate is clean
- **GIVEN** the decidiq worktree after this change
- **WHEN** `lint-or-abstraction-anti-patterns.sh` runs
- **THEN** it reports no `consume-or-rbac-fleet-wide` finding for decidiq.

@e2e exclude build-time lint-gate assertion, not a runtime UI flow; verified by running lint-or-abstraction-anti-patterns.sh (reports clean; no *AuthorizationService/*PermissionService remains under lib/).

### Requirement: REQ-RBAC-003 Chair-only lifecycle transitions are enforced by OpenRegister property RBAC
Chair-only meeting-lifecycle transitions SHALL be enforced by an OpenRegister property-RBAC rule that
write-gates the `meeting.lifecycle` property to the owning body's `chair` scope for the transitions
listed in the workflow template's `chairOnlyTransitions`. The imperative
`WorkflowService::requiresChairAuthorization()` actor-branch and the NC-UID-vs-chair comparison in
`MeetingService` SHALL be removed. The workflow **policy** predicates (domain `allowPause` /
`allowAdjourn` flags, `isQuorumRequired`) and the quorum data-predicate
(`DecisionTransitionGuard::isOpenAllowed`) SHALL remain, as they express process configuration and
data state, not actor authorization.

#### Scenario: Only the chair may run a chair-only transition
- **GIVEN** a meeting whose `from:to` transition is in `chairOnlyTransitions`
- **WHEN** a user NOT in the body's `chair` scope attempts the transition
- **THEN** OpenRegister denies the `meeting.lifecycle` write (403)
- **AND WHEN** the body's chair attempts the same transition
- **THEN** OpenRegister authorizes it and the lifecycle advances.

@e2e exclude needs a second account acting through a CSRF-bearing session plus a governance body whose role scopes the projector has populated, and tests/e2e/workflows/rbac-authorization-workflow.spec.ts skips it for that reason rather than pretending to cover it. Unit-proven in MeetingServiceTest (chair allowed, non-chair denied, fail-closed on an unresolvable body).

#### Scenario: Domain policy still forbids a disallowed transition regardless of actor
- **GIVEN** a domain whose workflow sets `allowPause: false`
- **WHEN** the body's chair attempts `opened → paused`
- **THEN** the transition is refused by the workflow policy (not permitted in this domain)
- **AND** the refusal is independent of the actor's scope membership.

@e2e exclude needs a second account acting through a CSRF-bearing session plus a governance body whose role scopes the projector has populated, and tests/e2e/workflows/rbac-authorization-workflow.spec.ts skips it for that reason rather than pretending to cover it. Unit-proven in MeetingServiceTest testDomainDisallowedTransitionReturnsFailure.

### Requirement: REQ-RBAC-004 The duplicated admin guards consume OpenRegister's admin determination
decidiq SHALL replace the four per-controller `requireAdmin()` copies
(`GovernanceReportController`, `MultilingualReconciliationController`, `AuditLogController`,
`RegulatorExportController`) with a single shared admin guard that consumes OpenRegister's admin
determination (`PropertyRbacHandler::isAdmin()` / the OpenRegister authorization decision). Each
controller SHALL call the shared guard; a non-admin SHALL receive 403 on every previously
admin-gated method.

#### Scenario: A non-admin is denied on every previously admin-gated surface
- **GIVEN** a non-admin user
- **WHEN** the user calls any method previously guarded by a private `requireAdmin()`
- **THEN** the shared guard returns 403
- **AND** there is exactly one admin-guard implementation shared across the four controllers.

#### Scenario: An admin is allowed
- **GIVEN** an admin user
- **WHEN** the user calls a previously admin-gated method
- **THEN** the shared guard permits the call.

### Requirement: REQ-RBAC-005 Fail-closed authorization is preserved end to end
Every migrated authorization path SHALL deny on ambiguity — an unresolved scope, a missing body, or
an OpenRegister error SHALL result in no write (403/`null`), never a silent skip. The change SHALL
NOT introduce a `catch (\Throwable) { return null; }` resolver whose null return is treated by a
caller as "check skipped".

#### Scenario: An unresolved scope denies
- **GIVEN** a signing or chair-only transition attempt whose body scope cannot be resolved
- **WHEN** OpenRegister evaluates the RBAC rule
- **THEN** the write is denied (403)
- **AND** the attempt is never treated as authorized.

@e2e exclude fail-closed edge (unresolvable body scope) with no distinct UI flow; unit-proven in GovernanceScopeGuardTest (fails closed when the body is unresolvable / on OR error) and MeetingServiceTest (chair-only transition denied when the governanceBody cannot be resolved).


### Requirement: REQ-RBAC-006 The register declares an authorization baseline so an absent block cannot grant writes
The `decidesk` register row SHALL declare an `authorization` block naming EVERY canonical
OpenRegister action (`read`, `list`, `create`, `update`, `delete`). `read`, `list` and `create`
SHALL be granted to `authenticated`; `update` and `delete` SHALL NOT be, so that a user who is
neither the object's owner, nor a Nextcloud admin, nor a member of the named administrator group
cannot rewrite or destroy another user's decidiq object through OpenRegister's own
`/apps/openregister/api/objects/decidiq/<schema>` API. Schemas that declare their own
`authorization` block SHALL keep it (OpenRegister resolves the schema block first and falls back to
the register's only when a schema has none), and what such a block declares for the write actions is
governed by REQ-RBAC-007. The register version, the configuration version and the app version SHALL all be bumped in the
same change, because the register import skips on a non-newer version with no content fallback and
the `<post-migration>` repair step that performs the import runs only on `occ upgrade`.

#### Scenario: A non-owner cannot rewrite another user's object
- **GIVEN** a Decision created by user A, and user B who is not an admin and not in the
  administrator group
- **WHEN** user B issues an update or delete against that Decision through OpenRegister's object API
- **THEN** OpenRegister denies the write
- **AND WHEN** user A issues the same write on their own object
- **THEN** OpenRegister permits it via the unconditional owner bypass.

#### Scenario: Reads and creates are unchanged
- **GIVEN** any authenticated user
- **WHEN** the user lists or reads decidiq objects, or creates a new one
- **THEN** the action is permitted exactly as before the baseline was declared.

#### Scenario: The baseline names every action
- **GIVEN** the shipped register row
- **WHEN** its `authorization` block is read
- **THEN** every canonical action is named with a non-empty rule list, because OpenRegister denies
  any action a non-empty block omits — an unnamed action would break the app rather than secure it.

@e2e exclude The assertion is a per-user DENIAL by OpenRegister's own permission evaluator against a declaration this repo ships, and the owner bypass is unconditional and SQL-side — so a browser test driven by a single seeded (and therefore owning, usually admin) session cannot observe it at all, and would report success over the exact hole. Pinned by `tests/Unit/RegisterAuthorizationTest.php` on the declaration side; the per-user behaviour needs a two-account probe against a live instance, recorded in the PR as verification owed rather than claimed.

### Requirement: REQ-RBAC-007 A schema block that narrows reads restates the writes the app needs
OpenRegister uses a schema's own `authorization` block IN PLACE OF the register's, whole, not action
by action: `PermissionHandler::resolveAuthorizationRaw()` consults the register block only when the
schema block is empty, and `hasGroupPermission()` denies any action a non-empty block leaves out. A
schema block added to narrow who may READ therefore also closes `create`, `update` and `delete` to
everyone except the object owner and a Nextcloud superuser, unless it names them.

A schema block that exists to narrow reads, on a schema the SPA writes through OpenRegister's object
API, SHALL therefore name `create`, `update` and `delete` with exactly the rule lists the register row
grants. A schema whose writes are owned by a decidiq service that runs its own per-object guard
(ProxyAuthorization, ConflictOfInterest, ConsultationReaction, PublicationPayload) SHALL NOT name a
write action, so that guard cannot be bypassed through the object API. EvaluationResponse SHALL name
`create` for `authenticated` only. A retired schema SHALL NOT name a write action. Every schema
`decidiq_mock_register.json` carries SHALL have the same block there as in `decidesk_register.json`,
and the mock's register row SHALL carry the register row's block, because the demo import is forced
and writes the mock's schemas over the real ones.

A property-level rule, such as the `update` rule on Decision `isPublished` and `publishedAt`, is
consulted only after the object-level check has admitted the caller. It SHALL keep refusing a direct
write to those fields from every group the schema grants `update`. A Nextcloud superuser bypasses
object and property rules alike and is not constrained by this requirement.

#### Scenario: An administrator group member edits a Decision they did not create
- **GIVEN** a Decision owned by another account, and an account that is in `decidiq-administrators`
  and is not a Nextcloud superuser
- **WHEN** that account writes a changed `title` through OpenRegister's object API
- **THEN** the write succeeds and the account reads back the new title.

#### Scenario: A direct write to a flow owned publication field is refused
- **GIVEN** the same account and Decision, after the title edit succeeded
- **WHEN** that account writes `isPublished: public` through OpenRegister's object API
- **THEN** OpenRegister refuses it with the property rule's message naming `isPublished`, not with
  the object-level update refusal
- **AND** the Decision's `isPublished` is unchanged.

#### Scenario: A member outside the administrator groups cannot edit a Decision they did not create
- **GIVEN** the same Decision, and an authenticated account in neither administrator group that is
  not a Nextcloud superuser and does not own it
- **WHEN** that account writes a changed `title` through OpenRegister's object API
- **THEN** OpenRegister refuses it at the object level, and the title is unchanged.

#### Scenario: Any member can raise a Decision
- **GIVEN** an authenticated account in no group at all
- **WHEN** that account creates a Decision through OpenRegister's object API
- **THEN** the Decision is created.
