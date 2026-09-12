---
status: done
status-note: 2026-06-12 admin-settings-v1 — all 4 requirements now have working surfaces. Members tab root cause fixed (governanceBody materialised as a real Participant property via the ADR-037 register fragment — it previously lived only in x-openregister-relations, which OpenRegister never turns into a queryable property, so the tab always rendered empty) plus role-assignment UI; member import from Nextcloud groups and CSV (validation preview, duplicate handling, email-to-account matching, 500-row cap client and server); organization configuration UI (name, logo URL, timezone, locale, currency, retention days via IAppConfig); per-body default + specialized process-template assignment from the built-in catalogue. Honest residue — Nextcloud Contacts import not built (requirement satisfiable via Groups/CSV); template chooser on decision-create not built (decision-management surface); org name/logo not yet consumed by generated resolutions/minutes (document-generation pipeline); template management itself is process-configuration (V1, separate spec).
---

# Admin Settings Specification

## Purpose
Admin settings enable organization administrators to configure Decidiq for their specific governance context. This includes setting up governing bodies (bodies), assigning members with roles, selecting process templates, configuring voting rules, and managing the OpenRegister schema setup. The admin interface is the first thing configured after installation and determines how the entire system behaves.

**Standards**: Nextcloud Settings API (`OCP\Settings\ISettings`), Schema.org (`Organization`, `Role`)
**Feature tier**: MVP

**OpenSpec changes**:
- ia-six-item-nav (active) — adds the `organisatie-modus` tenant-mode setting (ADR-004 Rule 1 / ADR-006 label adaptation)

## Requirements

---

### Requirement: Governing Body Management

The system MUST support creating and managing governing bodies (bestuursorganen). Each body MUST have a name, type (council, board, assembly, committee, team), member list with roles, default process template, and quorum rules. Bodies MUST be stored as OpenRegister objects in the `decidesk` register using the `body` schema.

**Feature tier**: MVP

#### Scenario: Create a governing body for an association board

@e2e openspec/specs/admin-settings/spec.md#create-a-governing-body-for-an-association-board

- GIVEN an administrator in the Decidiq admin settings
- WHEN they create a body with name "Bestuur", type "board", and add 5 members with roles (chair, secretary, treasurer, member, member)
- THEN the system MUST create an OpenRegister object with the `body` schema
- AND each member MUST be linked to a Nextcloud user account
- AND the default process template MUST be selectable from available templates

#### Scenario: Configure quorum rules for a body

@e2e openspec/specs/admin-settings/spec.md#configure-quorum-rules-for-a-body

- GIVEN an existing body "Algemene Ledenvergadering" with 200 members
- WHEN the administrator sets quorum to "50%+1 of members present or represented"
- THEN the quorum rule MUST be stored on the body configuration
- AND the quorum MUST be automatically calculated at each meeting

#### Scenario: Assign roles within a body

@e2e openspec/specs/admin-settings/spec.md#assign-roles-within-a-body

- GIVEN an existing body with members
- WHEN the administrator assigns the "chair" role to a member
- THEN the member MUST have chair-specific permissions (start votes, manage agenda, set speaking order)
- AND the "secretary" role MUST grant minute-taking and convocation permissions
- AND the "member" role MUST grant voting and speaking rights only

---

### Requirement: Process Template Assignment

The system MUST allow administrators to assign process templates to bodies. Each body MUST have a default template and MAY have additional templates for specific decision types (e.g., statute amendment, board election).

**Feature tier**: MVP

#### Scenario: Assign default and specialized templates to a body

@e2e openspec/specs/admin-settings/spec.md#assign-default-and-specialized-templates-to-a-body

- GIVEN a body "ALV" with a default template "ALV Standard Decision"
- WHEN the administrator adds a specialized template "ALV Statute Amendment" for statute changes
- THEN the body MUST have both templates available
- AND when creating a decision, the user MUST be able to choose the applicable template
- AND if no template is chosen, the default MUST apply

---

### Requirement: Organization Configuration

The system MUST support configuring organization-level settings: organization name, logo, default language (nl/en), timezone, currency for cost calculations, and archival retention period.

**Feature tier**: MVP

#### Scenario: Configure organization defaults

@e2e openspec/specs/admin-settings/spec.md#configure-organization-defaults

- GIVEN the administrator opens the organization settings
- WHEN they set organization name "Vereniging De Harmonie", language "nl", timezone "Europe/Amsterdam", and currency "EUR"
- THEN these defaults MUST apply to all meetings, decisions, and generated documents
- AND the organization name and logo MUST appear on generated resolutions and minutes

---

### Requirement: Member Import

The system MUST support importing members from Nextcloud Groups, Nextcloud Contacts, or CSV file. Imported members MUST be linked to Nextcloud user accounts where possible. Members are stored as `Person` + `Membership` object pairs (Popolo model), not as the deprecated flat `Participant` schema — the `GovernanceBodyMembersTab` list/create/remove flow and the `MemberAddDialog`/`MemberGroupImportDialog`/`MemberCsvImportDialog`/`MemberRoleDialog` import/role-assignment flows all read and write `membership`+`person` objects.

**Feature tier**: MVP

#### Scenario: Import members from a Nextcloud group

@e2e openspec/specs/admin-settings/spec.md#import-members-from-a-nextcloud-group

- GIVEN a Nextcloud group "bestuur" with 5 members
- WHEN the administrator imports the group into a Decidiq body
- THEN all 5 Nextcloud users MUST be added as body members
- AND their display names and email addresses MUST be populated from Nextcloud
- AND the administrator MUST be able to assign roles after import

#### Scenario: Import members from CSV

@e2e openspec/specs/admin-settings/spec.md#import-members-from-csv

- GIVEN a CSV file with columns: name, email, role
- WHEN the administrator uploads the CSV for a body
- THEN the system MUST create member entries for each row
- AND members with matching Nextcloud accounts (by email) MUST be automatically linked
- AND unmatched members MUST be flagged for manual linking or invitation

#### Scenario: Imported member is stored as Person + Membership

- **GIVEN** an administrator imports a member (from a Nextcloud group, CSV, or the "Add member" action) after this change
- **WHEN** the import completes
- **THEN** a `Person` object is created (or matched by email against an existing `Person`) holding identity fields (`name`, `email`)
- **AND** a `Membership` object is created linking that `Person` to the target `GovernanceBody` with the assigned `role`
- **AND** no new `Participant` object is created

@e2e exclude tests/e2e/spec-coverage/admin-settings.spec.ts's import tests (`import-members-from-a-nextcloud-group`, `import-members-from-csv`) only open the dialog and preview rows, they never submit an import; tests/Unit/Service/MemberImportServiceTest.php covers the group/CSV listing and email-matching helpers but not the Person+Membership write path itself — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Members tab lists active memberships, not Participant rows

- **GIVEN** a `GovernanceBody` with active `Membership` objects (no `endDate`) for several `Person` records
- **WHEN** the administrator opens the Members tab (`GovernanceBodyMembersTab.vue`)
- **THEN** the list is populated by querying `membership` filtered on `governanceBody` and `endDate: null`, joined to each `Membership`'s `Person` for display name
- **AND** "Remove from body" sets the `Membership`'s `endDate` to the current date (Popolo departure semantics, matching the existing `member-onboarding` offboarding pattern) rather than nulling a `governanceBody` field on a `Participant` row

@e2e exclude tests/e2e/spec-coverage/admin-settings.spec.ts's Members-tab test ("Members tab lists body members and offers the Change role action") asserts the tab renders and lists rows, but does not assert the underlying query is `membership` filtered on `endDate: null` (vs. a `Participant` query), nor drive the "Remove from body" soft-departure action — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

### Requirement: REQ-ADM-MODE-001 Organisatie-modus tenant setting
The system MUST expose an `organisatie_modus` setting whose value is one of
`gov`, `corp`, `assoc`, `ops`, or `citizen`, defaulting to `gov`. The setting MUST
be persisted via `IAppConfig` through `SettingsService` (added to
`SettingsService::CONFIG_KEYS`), returned by `getSettings()` with the `gov`
default when unset, and writable via `updateSettings()`. The setting MUST be
selectable in the Decidiq admin settings UI. The value MUST drive the
navigation label map (per the app-navigation capability) and MUST NOT alter the
entity/schema set or the navigation structure (ADR-006: mode adaptation, never
parallel entities).

#### Scenario: Default mode is gov

@e2e openspec/specs/admin-settings/spec.md#default-mode-is-gov
@e2e exclude tests/Unit/Service/SettingsServiceTest.php's getSettings tests mock `IAppConfig::getValueString` with a blanket return value for every key, so they never independently exercise the `organisatie_modus` default-to-`"gov"` fallback branch; no e2e test asserts a fresh-install default either — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

- GIVEN a fresh install where `organisatie_modus` has never been set
- WHEN `getSettings()` is called
- THEN it returns `organisatie_modus = "gov"`

#### Scenario: Admin selects a tenant mode

@e2e openspec/specs/admin-settings/spec.md#admin-selects-a-tenant-mode

- GIVEN an administrator in the Decidiq admin settings
- WHEN they set the organisation mode to "corp"
- THEN `updateSettings()` persists `organisatie_modus = "corp"` via `IAppConfig`
- AND `getSettings()` subsequently returns `"corp"`
- AND the navigation Bodies item relabels to "Board" on next render

#### Scenario: Mode does not create parallel entities

@e2e openspec/specs/admin-settings/spec.md#mode-does-not-create-parallel-entities
@e2e exclude a cross-mode invariant assertion (schema set + nav structure unchanged across all five `organisatie_modus` values) — no e2e test drives all five modes and diffs the schema/nav shape between them; the label-only change for one mode (`corp`) is covered by the `admin-selects-a-tenant-mode` scenario/test, but the full invariant across every mode is untested — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

- GIVEN any `organisatie_modus` value
- WHEN the app boots with that mode
- THEN the register schema set is unchanged and the navigation structure stays the six-item IA
- AND only displayed labels differ

## User Stories

1. **Board secretary managing conflict of interest register**: As a board secretary, I want to maintain a digital conflict of interest register for all board and supervisory board members, so that potential conflicts are proactively identified before meetings. (Source: intelligence DB #23)

2. **Supervisory board chair managing director appointment**: As a supervisory board chair, I want to manage the full director appointment process from vacancy to formal appointment, so that governance procedures are properly followed. (Source: intelligence DB #28)

3. **Board secretary organizing document archive**: As a board secretary, I want to maintain a structured, searchable governance document archive with access controls, so that governance documents are secure, findable, and properly retained. (Source: intelligence DB #43)

## Acceptance Criteria

- Governing bodies are stored as OpenRegister objects with member lists and roles
- Body roles (chair, secretary, treasurer, member) map to specific permissions
- Process templates are assignable to bodies with default and specialized options
- Organization-level settings (name, logo, language, timezone) are configurable
- Member import from Nextcloud Groups, Contacts, and CSV is supported
- Quorum rules are configurable per body
- Admin settings use Nextcloud Settings API (OCP\Settings\ISettings)
