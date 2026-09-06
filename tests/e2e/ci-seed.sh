#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Decidiq Contributors
# SPDX-License-Identifier: EUPL-1.2
#
# Provision Decidiq's OpenRegister register + schemas on a freshly installed
# Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/decidiq/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED
# ------------------
# `occ app:enable decidiq` runs a post-migration repair step which is supposed
# to import `lib/Settings/decidesk_register.json` (plus the 24 fragment
# overlays in `lib/Settings/register.d/`) into OpenRegister. Two things make
# that unreliable as the sole fresh-install path, and BOTH fail silently:
#
#   1. An IRepairStep runs with NO user session. OpenRegister's RBAC evaluates
#      the acting user, so the import is denied outright with
#      "User 'Anonymous' does not have permission to 'create' objects in schema
#      '…'". The repair step catches `\Throwable` and downgrades it to a
#      warning, so `occ app:enable decidiq` still exits 0.
#   2. The repair path calls `loadConfiguration(force: false)`. The non-forced
#      path is version-guarded: it can advance the recorded configuration
#      version WITHOUT applying the register, so a second run then sees
#      "already current" and does nothing either.
#
# Either way the app enables cleanly, the SPA boots, and the register simply is
# not there. The suite's failure mode in that state is every UI spec timing out
# on an empty list and every `expect(resp.ok()).toBe(true)` against
# `/apps/openregister/api/objects/decidiq/<schema>` failing — messages that
# accuse the selectors, not the missing import.
#
# So this script does the import EXPLICITLY over the admin HTTP API (which has
# a real session and passes RBAC), forced, and then VERIFIES the register and
# schemas actually exist. A failed provision becomes ONE loud step failure here
# instead of two dozen misleading spec failures later.
#
# It is idempotent: the import is idempotent server-side and re-running only
# re-verifies.
#
# FIXTURE-MARKER CONVENTION FOR SPECS THAT CREATE OBJECTS DIRECTLY
# ------------------------------------------------------------------
# The register/schema import above is the ONLY legitimate seed source (the
# `example` blocks in `lib/Settings/register.d/*.json`, versioned and
# reproducible). Any Playwright spec under `tests/e2e/` that creates an
# OpenRegister object directly — through the UI or the object REST API — is
# NOT seeding; it is writing throwaway test debris onto the shared instance,
# and MUST follow this convention (ux-debt-rendering REQ-008) instead of
# leaving it behind for a human to find later:
#
#   1. MARK every object this creates with a stable, greppable prefix in its
#      human-readable name/title field, e.g. `e2e-<runId>-...` (see
#      `tests/e2e/workflows/governance-fixture.ts`'s `newLedger()` /
#      `seedGovernanceScenario()` for the established helper — reuse it
#      rather than inventing a second marker scheme).
#   2. TRACK every created id (by the response's own id, never by
#      query-by-marker) in a per-run ledger.
#   3. DELETE everything the ledger tracked in an `afterAll` hook
#      (`cleanupAll()` in the same helper), best-effort, children before
#      parents.
#   4. If you add a NEW schema to the ledger, add it to `TEARDOWN_ORDER` (and
#      the marker-sweep `sweepSchemas` list) in governance-fixture.ts too — a
#      schema present in `ledger.created` but absent from `TEARDOWN_ORDER`
#      leaks silently, because `cleanupAll()` iterates the array, not the
#      ledger. This is exactly how `board-evaluation`, `evaluation-template`,
#      `consultation-reaction`, `budget-proposal` and `participatory-budget`
#      leaked on every run before the ux-debt-rendering fixture-pollution
#      sweep caught it (2026-08-19) — the ledger tracked them correctly, the
#      teardown array simply never mentioned them.
#
# Never bulk-delete by marker outside a single run's own ledger — a scheduled
# or ad-hoc "delete everything matching e2e-*" job is a standing risk if the
# convention is ever violated by a future spec (hard safety rule). A manual,
# marker-filtered cleanup remains something an operator can run deliberately,
# never something automated.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# NC_BASE_URL / ADMIN_USER / ADMIN_PASSWORD / NC_ADMIN_USER / NC_ADMIN_PASS.
# Accept all of them, and fall back to the CI runner's own
# `php -S 0.0.0.0:8080` only when actually running on CI.
#
# On a developer box `localhost:8080` is the SHARED dev container, and this
# script performs ADMIN WRITES — it must never silently import a register into
# somebody else's environment. Off CI, an unset target is a hard error.
# Mirrors tests/e2e/base-url.ts.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

# A deliberately UNPRIVILEGED account. The suite otherwise has exactly one
# account, `admin`, and against a single admin "this endpoint denies a
# non-admin" and "this endpoint denies nobody" produce identical evidence — so
# decidiq's four RequiresOrAdmin controllers had no live proof they gate
# anything. Consumed by tests/e2e/workflows/rbac-authorization-workflow.spec.ts.
E2E_MEMBER_USER="${E2E_MEMBER_USER:-decidiq-e2e-member}"
E2E_MEMBER_PASS="${E2E_MEMBER_PASS:-decidiq-e2e-member-pw}"

# The calendar collection action-item VTODOs are written into. Its own URI, not
# `personal`: Nextcloud's auto-provisioned "Personal" calendar is VEVENT-only,
# and a calendar's component set is fixed at creation — so this must be a
# calendar the seed creates, never one it adopts. See section 0a-bis.
VTODO_CALENDAR_URI="${VTODO_CALENDAR_URI:-decidesk-action-items}"

echo "[ci-seed] target:  ${BASE}"
echo "[ci-seed] app dir: ${APP_DIR}"

# ── 0. Make Nextcloud emit the pretty URLs the specs navigate to ─────────────
# decidiq's SPA router is `createWebHistory(generateUrl('/apps/decidiq'))`
# (src/main.js). The JS `generateUrl` prefixes `/index.php` unless
# `OC.config.modRewriteWorking` is true, and that flag is derived from the
# `htaccess.IgnoreFrontController` system config — which `occ
# maintenance:install` leaves at its default of FALSE.
#
# So on an unconfigured CI instance the router's base is
# `/index.php/apps/decidiq`, while every spec navigates to
# `${BASE}/apps/decidiq/...`. vue-router 4 only strips a base that the current
# path actually starts with, so the resolved path stays `/apps/decidiq/...`,
# matches NO route, and the view never mounts. The failure surfaces as a
# selector timeout naming an element — i.e. it reads like a broken component,
# not like a misconfigured base.
#
# The workflow's `ci-router.php` front controller already serves pretty URLs
# (the job asserts `/apps/<app>/` does not 404), so telling Nextcloud the front
# controller is active is accurate here, not a workaround.
#
# Only attempted when `occ` is actually next to us — this step's cwd is the
# Nextcloud server root.
if [ -f ./occ ]; then
	php ./occ config:system:set htaccess.IgnoreFrontController --value=true --type=boolean >/dev/null 2>&1 || true

	# Read it BACK and gate on it. `occ config:system:set` can fail silently
	# (read-only config, a config partition it will not write), and an
	# unverified set is exactly the shape of a check whose absence looks like
	# its success.
	#
	# ⚠️ occ prints unrelated notices to STDOUT ahead of the value on some
	# instances, so take the LAST non-empty line rather than the whole output.
	FC_VALUE="$(php ./occ config:system:get htaccess.IgnoreFrontController 2>/dev/null \
		| grep -v '^[[:space:]]*$' | tail -1 | tr -d '[:space:]' || true)"
	echo "[ci-seed] htaccess.IgnoreFrontController -> '${FC_VALUE}'"
	if [ "$FC_VALUE" != "true" ] && [ "$FC_VALUE" != "1" ]; then
		echo "::error::htaccess.IgnoreFrontController is '${FC_VALUE}', not true."
		echo "::error::generateUrl() will emit /index.php/apps/decidiq as the vue-router base while"
		echo "::error::the specs navigate to /apps/decidiq/... — no route matches and every UI spec"
		echo "::error::fails on a selector timeout that names an element rather than the real cause."
		exit 1
	fi

	# ── 0a-bis. A VTODO-CAPABLE CALENDAR MUST EXIST FOR THE ACTING USER ──────
	#
	# Action items ARE CalDAV VTODOs (ADR-002): ActionItemWriter hands the write
	# to OpenRegister's TaskService, whose findUserCalendar() walks the user's
	# calendars and takes the first whose
	# `{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set`
	# includes VTODO. If none does it throws NoVtodoCalendarException,
	# ActionItemWriter catches \Throwable and returns null, and
	# ActionItemController turns that into a bare
	# `{"error":"Could not create action item"}` with HTTP 500.
	#
	# A STOCK NEXTCLOUD HAS NO SUCH CALENDAR. Measured on this instance:
	#
	#   id | uri               | displayname       | components
	#    1 | personal          | Personal          | VEVENT
	#    2 | contact_birthdays | Contact birthdays | VEVENT
	#
	# The auto-provisioned "Personal" calendar is VEVENT-ONLY. VTODO arrives
	# only when something creates a task list — the Tasks app, or the user. So
	# the seed must create one, exactly as it must import the register: both are
	# prerequisites the product needs and a bare `maintenance:install` does not
	# provide. Without it three action-item specs fail on an opaque 500 whose
	# message names nothing.
	#
	# `occ dav:create-calendar` creates it as VEVENT,VTODO,VJOURNAL — verified
	# by reading the row back below, not assumed.
	#
	# Idempotent: a second run just reports "already exists", which is why the
	# create is allowed to fail and the VERIFY is what gates.
	php ./occ dav:create-calendar "${USER_NAME}" "${VTODO_CALENDAR_URI}" >/dev/null 2>&1 || true

	# VERIFY over CalDAV, not over the create's exit code. `occ` exiting 0 says
	# a calendar exists; it does not say it accepts VTODO, and a VEVENT-only
	# calendar is indistinguishable from a correct one until a write fails 500.
	# PROPFIND the collection and require VTODO in the component set.
	CAL_PROPS="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -X PROPFIND \
		-H 'Depth: 0' -H 'Content-Type: application/xml' \
		--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:supported-calendar-component-set/></d:prop></d:propfind>' \
		"${BASE}/remote.php/dav/calendars/${USER_NAME}/${VTODO_CALENDAR_URI}/" 2>/dev/null || true)"
	if printf '%s' "${CAL_PROPS}" | grep -q 'name="VTODO"'; then
		echo "[ci-seed] VTODO calendar '${VTODO_CALENDAR_URI}' present for ${USER_NAME}."
	else
		echo "::error::no VTODO-supporting calendar for '${USER_NAME}' after dav:create-calendar."
		echo "::error::OpenRegister's TaskService::findUserCalendar() will throw NoVtodoCalendarException,"
		echo "::error::ActionItemWriter will swallow it, and every action-item create answers a bare"
		echo "::error::HTTP 500 {\"error\":\"Could not create action item\"} that names no cause."
		echo "::error::PROPFIND returned: ${CAL_PROPS}"
		exit 1
	fi

	# ── 0a-ter. AN UNPRIVILEGED ACCOUNT, so admin-gating is testable ─────────
	#
	# Created with NO --group, so it lands outside `admin` — which is exactly
	# what IGroupManager::isAdmin() reads, and what RequiresOrAdmin consumes.
	#
	# Idempotent in the same shape as the calendar above: the create may fail
	# ("user already exists") and the VERIFY is what gates.
	OC_PASS="${E2E_MEMBER_PASS}" php ./occ user:add --password-from-env \
		"${E2E_MEMBER_USER}" >/dev/null 2>&1 || true

	# VERIFY over occ, and gate. An absent member account turns the spec's 403
	# assertions into 401s, which reads as a broken guard rather than a missing
	# fixture — the failure would name the wrong thing.
	MEMBER_INFO="$(php ./occ user:info "${E2E_MEMBER_USER}" --output=json 2>/dev/null || true)"
	if [ -z "${MEMBER_INFO}" ]; then
		echo "::error::could not provision the non-admin account '${E2E_MEMBER_USER}'."
		echo "::error::rbac-authorization-workflow.spec.ts asserts 403 for it on four"
		echo "::error::RequiresOrAdmin surfaces; without the account those become 401s."
		exit 1
	fi

	# It must NOT be an admin. If it were, every 403 assertion would invert and
	# the spec would pass while proving the opposite of its own name — the worst
	# available outcome, because it is green.
	if printf '%s' "${MEMBER_INFO}" | python3 -c \
		'import json,sys; sys.exit(0 if "admin" in json.load(sys.stdin).get("groups",[]) else 1)' \
		2>/dev/null; then
		echo "::error::'${E2E_MEMBER_USER}' is in the admin group; it must not be."
		exit 1
	fi
	echo "[ci-seed] non-admin account '${E2E_MEMBER_USER}' present and unprivileged."
else
	echo "[ci-seed] no ./occ in $(pwd) — skipping the front-controller config (not a server root?)."
	echo "[ci-seed] WARNING: the VTODO calendar gate is also skipped; action-item specs may 500."
	echo "[ci-seed] WARNING: the non-admin account is also skipped; the admin-gating spec will"
	echo "[ci-seed]          see 401 where it asserts 403."
fi

# ── 0b. GATE: the SERVED page must actually advertise pretty URLs ────────────
#
# The `occ config:system:get` read-back above proves what is in config.php. It
# does NOT prove what the SPA sees, and those are different facts: what matters
# is the value Nextcloud renders into `OC.config.modRewriteWorking` on the page
# the BROWSER loads, because that is what `generateUrl()` reads to build the
# vue-router base. A config the renderer does not pick up (a cached
# config, a second config partition, an instance whose theming/bootstrap path
# overrides it) leaves the flag false on the page while `occ` cheerfully reports
# true — and the `occ` check passes, so nothing says so.
#
# Assert on the SERVED HTML instead. Getting this wrong is invisible in exactly
# the way that matters: the app still boots, still renders, still returns HTTP
# 200 — it is simply on the wrong route, and every downstream failure then
# accuses a selector rather than the router base.
PRETTY_HTML="$(mktemp)"
PRETTY_CODE="$(curl -sS -o "$PRETTY_HTML" -w '%{http_code}' \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/decidiq/" || echo 000)"
if [ "$PRETTY_CODE" != "200" ]; then
	echo "::error::Could not fetch the Decidiq app page to verify pretty URLs (HTTP ${PRETTY_CODE})."
	exit 1
fi
if grep -q '"modRewriteWorking":true\|"modRewriteWorking": *true' "$PRETTY_HTML"; then
	echo "[ci-seed] served page reports modRewriteWorking:true — the SPA router base will be /apps/decidiq."
else
	echo "::error::The served Decidiq page does NOT report modRewriteWorking:true."
	echo "::error::generateUrl() will therefore return '/index.php/apps/decidiq' as the vue-router base,"
	echo "::error::while every spec navigates to the pretty '/apps/decidiq/...' form. Those disagree, so"
	echo "::error::vue-router matches nothing and the view never mounts — every UI spec then fails on a"
	echo "::error::selector timeout that names an element rather than the real cause."
	grep -o 'modRewriteWorking[^,}]*' "$PRETTY_HTML" | head -3 || echo "  (key not present in the page at all)"
	exit 1
fi

# ── 1. Import the Decidiq configuration ─────────────────────────────────────
# Decidiq's `appinfo/routes.php` returns
# `\OCA\OpenRegister\AppHost\Routes::standard([...])`, whose canonical table
# ships `settings#load` at POST /api/settings/load. On decidiq that name
# resolves to OCA\Decidiq\Controller\SettingsController::load(), which calls
# `loadConfiguration(force: true)` — precisely the forced import the repair step
# cannot perform, and the only entry point that merges the register.d/ fragment
# overlays on top of the base register.
#
# It is admin-only (`#[AuthorizedAdminSetting(AdminSettings::class)]`), so HTTP
# Basic as admin is required.
#
# `OCS-APIRequest: true` is load-bearing, not decoration: the method carries no
# #[NoCSRFRequired], and Nextcloud's Request::passesCSRFCheck() short-circuits
# to true on that header (the strict-cookie precondition is satisfied because a
# Basic-auth request carries no session cookie at all). Without the header this
# POST is rejected as a CSRF failure.
IMPORT_URL="${BASE}/index.php/apps/decidiq/api/settings/load"
echo "[ci-seed] POST ${IMPORT_URL} (forced import)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{}' \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] settings#load HTTP ${IMPORT_CODE}"
head -c 2000 "$IMPORT_BODY"; echo

# HTTP 200 is necessary but NOT sufficient: SettingsController::load() returns
# `{"success": false, "message": "..."}` with a 200 when the import itself
# failed (OpenRegister missing, register JSON unreadable, RBAC denial). Treat
# anything that is not an explicit success as a reason to try the generic
# importer below, and let the verification step decide the outcome.
IMPORT_OK=0
if [ "$IMPORT_CODE" = "200" ] && grep -q '"success":[[:space:]]*true' "$IMPORT_BODY"; then
	IMPORT_OK=1
	echo "[ci-seed] decidiq settings#load reported success."
else
	echo "[ci-seed] decidiq settings#load did not report success; falling back to the OpenRegister importer."
fi

# ── 1b. Fallback: OpenRegister's generic configuration importer ──────────────
# Independent of decidiq's own controller wiring, so it still provisions the
# register if `settings#load` is unavailable (e.g. an OpenRegister build whose
# AppHost route table predates `settings#load`) or if the decidiq
# SettingsService rejects the file. Admin-only. It reads the upload under the
# literal form key `file`; a raw JSON request body is NOT one of its accepted
# shapes. `force` is compared `=== 'true' || === true` there, so the
# form-encoded string is fine.
#
# ⚠️ This fallback imports ONLY the base register — it cannot merge the
# register.d/ fragments, which is decidiq-specific logic living in
# SettingsService::mergeRegisterFragments(). It is a floor, not a substitute:
# the verification below still has to pass.
if [ "$IMPORT_OK" != "1" ]; then
	REGISTER_JSON="${APP_DIR}/lib/Settings/decidesk_register.json"
	if [ ! -f "$REGISTER_JSON" ]; then
		echo "::error::decidesk_register.json not found at ${REGISTER_JSON}."
		exit 1
	fi

	OR_URL="${BASE}/index.php/apps/openregister/api/configurations/import"
	echo "[ci-seed] POST ${OR_URL} (file=decidesk_register.json, force=true)"
	OR_BODY="$(mktemp)"
	OR_CODE="$(
		curl -sS -o "$OR_BODY" -w '%{http_code}' \
			-u "${USER_NAME}:${USER_PASS}" \
			-X POST \
			-H 'OCS-APIRequest: true' \
			-F "file=@${REGISTER_JSON}" \
			-F 'force=true' \
			-F 'appId=decidiq' \
			"$OR_URL" || echo 000
	)"
	echo "[ci-seed] configurations/import HTTP ${OR_CODE}"
	head -c 2000 "$OR_BODY"; echo
fi

# ── 2. Verify the register and schemas are actually there ────────────────────
# An import reporting success is not the same as the register existing. Verify
# against OpenRegister directly, using the same slugs the specs resolve by
# (they build object URLs as
# /index.php/apps/openregister/api/objects/decidiq/<schema>).
#
# ⚠️ The required slugs below are READ OUT OF lib/Settings/decidesk_register.json
# verbatim (`components.schemas.<Name>.slug`), NOT mechanically kebab-cased from
# the schema names. OpenRegister resolves a URL segment via LOWER(slug), so a
# structural mismatch (hyphen vs underscore, a prefix) is a 404 — and the ones
# in this register are not all what a naive transform would produce.
#
# The HTTP status is captured and checked separately from the payload on
# purpose: an endpoint that 404s or redirects to the login form yields an empty
# slug set, which is indistinguishable from "the import produced nothing" if
# you only look at the parsed list. A wrong lookup manufactures an absence for
# free, so the two are reported as different errors.
verify() {
	python3 - "$1" "$2" "$3" <<'PY'
import json, sys
path, kind, code = sys.argv[1], sys.argv[2], sys.argv[3]
required = {
    # x-openregister.app in decidesk_register.json.
    'registers': ['decidiq'],
    # Verbatim `slug` values from components.schemas in
    # lib/Settings/decidesk_register.json — the ones the e2e specs address.
    'schemas': [
        'meeting', 'agenda-item', 'decision', 'action-item', 'governance-body',
        'participant', 'person', 'minutes', 'voting-round', 'vote',
        'transcript', 'public-consultation', 'consultation-reaction',
        'participatory-budget', 'budget-proposal', 'board-evaluation',
        'publication-record', 'engagement-record',
    ],
}[kind]
with open(path) as fh:
    raw = fh.read()
if code != '200':
    print(f'::error::OpenRegister {kind} endpoint returned HTTP {code}, so the '
          f'slug list below proves nothing about the import. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON (HTTP 200). First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind} present: {sorted(s for s in slugs if s)}')
if missing:
    print(f'::error::Decidiq {kind} missing after import: {missing}')
    print('::error::The e2e suite cannot address meetings, decisions, agendas or '
          'voting rounds without them; every UI spec would fail on an empty list.')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
REG_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$REG_BODY" -w '%{http_code}' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" || echo 000)"
verify "$REG_BODY" registers "$REG_CODE"

# 🔴 THE PAGE SIZE IS PART OF THE ASSERTION, SO IT MUST OUTRUN THE INSTANCE.
#
# This asked for `_limit=1000` and then reported every required slug it could
# not find as a MISSING SCHEMA. On a dev instance with 35 apps installed the
# schema table holds 1111 rows, so ten decidiq schemas fell off the end of the
# page and the script failed with:
#
#   ::error::Decidiq schemas missing after import:
#     ['meeting', 'action-item', 'minutes', 'vote', 'transcript', ...]
#
# Every one of them was present in the database. The import had worked; the
# QUESTION was too small. That is the worst shape a check can take: it names a
# real-sounding cause and sends you to look at the import.
#
# So the limit outruns any plausible instance, AND truncation is detected rather
# than assumed away: if the response comes back exactly full, the page is the
# suspect and the script says so instead of blaming the import.
SCH_LIMIT=20000
SCH_BODY="$(mktemp)"
SCH_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$SCH_BODY" -w '%{http_code}' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=${SCH_LIMIT}" || echo 000)"
verify "$SCH_BODY" schemas "$SCH_CODE"

SCH_COUNT="$(python3 -c "
import json
d=json.load(open('${SCH_BODY}'))
r=d.get('results', d if isinstance(d, list) else [])
print(len(r))
" 2>/dev/null || echo 0)"
if [ "${SCH_COUNT}" -ge "${SCH_LIMIT}" ] 2>/dev/null; then
	echo "::error::The schema listing came back exactly full (${SCH_COUNT} of _limit=${SCH_LIMIT})."
	echo "::error::It is TRUNCATED, so a 'missing schema' below would be a paging artefact, not a failed import. Raise SCH_LIMIT."
	exit 1
fi
echo "[ci-seed] schema listing: ${SCH_COUNT} row(s), under the ${SCH_LIMIT} page limit"

# The register existing is still not the same as it being READABLE by the admin
# session the specs use. Several specs assert `expect(resp.ok()).toBe(true)` on
# exactly this URL shape; probe it here so that failure mode has a name at the
# seed step rather than surfacing as a dozen unexplained assertion failures.
for slug in meeting decision governance-body participant; do
	OBJ_CODE="$(curl -sS -o /dev/null -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		"${BASE}/index.php/apps/openregister/api/objects/decidiq/${slug}?_limit=1" || echo 000)"
	echo "[ci-seed] objects/decidiq/${slug} probe -> ${OBJ_CODE}"
	if [ "$OBJ_CODE" -ge 400 ] 2>/dev/null; then
		echo "::error::The decidiq ${slug} collection is not readable (HTTP ${OBJ_CODE})."
		echo "::error::Specs that assert resp.ok() on this URL would fail with no indication the cause is provisioning."
		exit 1
	fi
done

echo "[ci-seed] Decidiq register + schemas provisioned."

# ── 2b. Seed the governance fixture the suite actually needs ─────────────────
#
# WHY THIS EXISTS
# ---------------
# The register import above creates the REGISTER and the SCHEMAS. It creates no
# OBJECTS: no schema in decidesk_register.json carries `x-openregister.seedData`,
# and tests/e2e/global-setup.ts only logs in and dismisses the walkthrough — it
# does no seeding at all, despite a comment in dashboard-layout.spec.ts asserting
# that it provisions a "Gemeenteraad Westerkwartier" fixture. It does not, and
# never did.
#
# So the suite ran against a register with ZERO objects, and the specs are
# written to tolerate that: they fetch "the first meeting / decision / motion"
# and then `test.skip(!first, 'No meeting objects found')`. On an empty instance
# that guard fires every time, so those tests reported SKIPPED — not failed —
# and a skip renders in the summary as "not a failure". That is the bulk of the
# 62 skips in run 31083903075: they were not documenting an unsupported
# environment, they were reporting that nobody had seeded anything, in language
# that blamed the instance.
#
# Every create below is CHECKED. A malformed seed body (an out-of-enum value, a
# missing required property) answers 400, the id interpolates to the empty
# string, and every downstream test skips on a cause that has nothing to do with
# the code under test — the exact failure mode this block exists to remove. So a
# failed create is a loud, immediate, fatal error here.
#
# Enum values and required[] below are read VERBATIM out of
# lib/Settings/decidesk_register.json. They are not guessable: Meeting.lifecycle
# is [draft, scheduled, opened, paused, adjourned, closed] (not "in-progress"),
# Minutes.lifecycle is [draft, review, approved, signed, published] (not
# "in-review"), and Decision.required includes `outcome` AND `decisionType`.
#
# Idempotent: the whole block is skipped when the tagged body already exists, so
# re-running the seed never duplicates the fixture.
SEED_TAG='e2e-seed'

# POST one object and echo its UUID. Fatal on any non-2xx.
#   seed_object <schema-slug> <json-body> <human label>
#
# ⚠️ Every diagnostic here goes to STDERR (`>&2`), deliberately. The caller uses
# `ID="$(seed_object …)"`, and command substitution captures STDOUT — so an error
# message written to stdout is swallowed into the variable instead of reaching
# the log. The first version of this function did exactly that: the Decision seed
# failed, the ::error:: lines describing why went into $MOTION_ID, and the step
# died with a bare "Process completed with exit code 1" and no diagnosis at all.
# A loud failure is only loud if it is written where someone can read it.
seed_object() {
	local slug="$1" body="$2" label="$3"
	local out code id
	out="$(mktemp)"
	code="$(curl -sS -o "$out" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data "$body" \
		"${BASE}/index.php/apps/openregister/api/objects/decidiq/${slug}" || echo 000)"

	if [ "$code" -lt 200 ] 2>/dev/null || [ "$code" -ge 300 ] 2>/dev/null; then
		{
			echo "::error::Seeding ${label} (schema '${slug}') failed with HTTP ${code}."
			echo "::error::Request body: ${body}"
			echo "::error::Response: $(head -c 800 "$out")"
			echo "::error::Without this object the specs that read it do not fail — they SKIP, on a message"
			echo "::error::that blames the instance ('No ${slug} objects found') rather than this seed."
		} >&2
		exit 1
	fi

	id="$(python3 -c '
import json, sys
try:
    o = json.load(open(sys.argv[1]))
except Exception:
    print(""); raise SystemExit
if not isinstance(o, dict):
    print(""); raise SystemExit
print(o.get("id") or (o.get("@self") or {}).get("id") or o.get("uuid") or "")
' "$out")"

	if [ -z "$id" ]; then
		{
			echo "::error::Seeding ${label} returned HTTP ${code} but no id could be read from the response."
			echo "::error::Response: $(head -c 800 "$out")"
		} >&2
		exit 1
	fi

	echo "$id"
}

EXISTING_BODY="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/objects/decidiq/governance-body?_limit=100" 2>/dev/null \
	| python3 -c '
import json, sys
try:
    b = json.load(sys.stdin)
except Exception:
    print(""); raise SystemExit
items = b if isinstance(b, list) else b.get("results", [])
for i in items:
    if isinstance(i, dict) and str(i.get("name", "")).startswith("e2e-seed"):
        print(i.get("id") or (i.get("@self") or {}).get("id") or "")
        break
else:
    print("")
' || true)"

if [ -n "$EXISTING_BODY" ]; then
	echo "[ci-seed] governance fixture already present (${EXISTING_BODY}) — not re-seeding."
else
	echo "[ci-seed] seeding the governance fixture…"

	BODY_ID="$(seed_object governance-body \
		"{\"name\":\"${SEED_TAG} Gemeenteraad Westerkwartier\",\"bodyType\":\"legislative\",\"domain\":\"Westerkwartier municipal council\"}" \
		'the governance body')"
	echo "[ci-seed]   governance-body ${BODY_ID}"

	# One SCHEDULED meeting (feeds the upcoming-meetings KPI/list) and one OPENED
	# meeting (the live-meeting specs need a meeting that is actually in session;
	# 'opened' is the register's term — there is no 'in-progress' value).
	MEETING_ID="$(seed_object meeting \
		"{\"title\":\"${SEED_TAG} Raadsvergadering\",\"meetingType\":\"regular\",\"scheduledDate\":\"2099-01-15T19:00:00Z\",\"meetingMode\":\"in-person\",\"lifecycle\":\"scheduled\",\"quorumRequired\":2,\"isPublic\":true,\"governanceBody\":\"${BODY_ID}\"}" \
		'the scheduled meeting')"
	echo "[ci-seed]   meeting (scheduled) ${MEETING_ID}"

	LIVE_MEETING_ID="$(seed_object meeting \
		"{\"title\":\"${SEED_TAG} Live raadsvergadering\",\"meetingType\":\"regular\",\"scheduledDate\":\"2026-01-15T19:00:00Z\",\"meetingMode\":\"hybrid\",\"lifecycle\":\"opened\",\"quorumRequired\":2,\"isPublic\":true,\"governanceBody\":\"${BODY_ID}\"}" \
		'the live meeting')"
	echo "[ci-seed]   meeting (opened) ${LIVE_MEETING_ID}"

	# The admin the specs authenticate as must map to a CHAIR participant, or
	# every chair/secretary-guarded action answers 403 and the guard tests cannot
	# tell "correctly refused" from "nobody is chair".
	CHAIR_ID="$(seed_object participant \
		"{\"displayName\":\"${SEED_TAG} Chair (admin)\",\"role\":\"chair\",\"attendanceStatus\":\"present\",\"nextcloudUserId\":\"${USER_NAME}\",\"governanceBody\":\"${BODY_ID}\"}" \
		'the chair participant')"
	echo "[ci-seed]   participant (chair) ${CHAIR_ID}"

	MEMBER_ID="$(seed_object participant \
		"{\"displayName\":\"${SEED_TAG} Member\",\"role\":\"member\",\"attendanceStatus\":\"present\",\"governanceBody\":\"${BODY_ID}\"}" \
		'the member participant')"
	echo "[ci-seed]   participant (member) ${MEMBER_ID}"

	for spec in "1|informational|Opening en mededelingen|${LIVE_MEETING_ID}" \
		"2|decision|Vaststelling begroting|${LIVE_MEETING_ID}" \
		"3|discussion|Rondvraag|${MEETING_ID}"
	do
		IFS='|' read -r ord itype ititle imeeting <<< "$spec"
		AGENDA_ID="$(seed_object agenda-item \
			"{\"title\":\"${SEED_TAG} ${ititle}\",\"itemType\":\"${itype}\",\"orderNumber\":${ord},\"meeting\":\"${imeeting}\"}" \
			"agenda item ${ord}")"
		echo "[ci-seed]   agenda-item ${ord} ${AGENDA_ID}"
	done

	# ADR-005: motions and amendments ARE Decisions, discriminated by
	# decisionType. There is no `motion` schema and no `amendment` schema.
	# `outcome` and `decisionType` are both in Decision.required[].
	# ⚠️ Decision has NO `meeting` property — a Decision is not linked to a meeting
	# directly (checked against components.schemas.Decision.properties, which has
	# amends/supersedes/repeals/implements/refersTo but nothing meeting-shaped;
	# the AgendaItem carries the meeting link). And `decisionDate` is
	# `format: date-time`, not `date` — a bare "2026-01-15" is rejected. Both
	# mistakes were in the first version of this seed and together took the whole
	# step down.
	MOTION_ID="$(seed_object decision \
		"{\"decisionType\":\"motion\",\"title\":\"${SEED_TAG} Motie duurzame energie\",\"text\":\"De raad draagt het college op te versnellen op duurzame energie.\",\"decisionDate\":\"2026-01-15T19:30:00Z\",\"outcome\":\"adopted\",\"motionType\":\"motion\",\"proposer\":\"${SEED_TAG} Chair (admin)\",\"lifecycle\":\"proposed\",\"isPublished\":\"public\",\"submittedAt\":\"2026-01-15T19:00:00Z\"}" \
		'the motion Decision')"
	echo "[ci-seed]   decision (motion) ${MOTION_ID}"

	AMENDMENT_ID="$(seed_object decision \
		"{\"decisionType\":\"amendment\",\"title\":\"${SEED_TAG} Amendement op de motie\",\"text\":\"Vervang versnellen door onmiddellijk versnellen.\",\"proposedText\":\"De raad draagt het college op onmiddellijk te versnellen op duurzame energie.\",\"decisionDate\":\"2026-01-15T19:35:00Z\",\"outcome\":\"rejected\",\"motionType\":\"amendment\",\"proposer\":\"${SEED_TAG} Member\",\"lifecycle\":\"proposed\",\"isPublished\":\"public\",\"submittedAt\":\"2026-01-15T19:05:00Z\",\"amends\":\"${MOTION_ID}\"}" \
		'the amendment Decision')"
	echo "[ci-seed]   decision (amendment) ${AMENDMENT_ID}"

	DECISION_ID="$(seed_object decision \
		"{\"decisionType\":\"meeting-outcome\",\"title\":\"${SEED_TAG} Vaststelling begroting 2026\",\"text\":\"De begroting 2026 wordt vastgesteld.\",\"decisionDate\":\"2026-01-15T20:00:00Z\",\"outcome\":\"adopted\",\"lifecycle\":\"enacted\",\"isPublished\":\"public\",\"enactedAt\":\"2026-01-15T20:05:00Z\"}" \
		'the enacted Decision')"
	echo "[ci-seed]   decision (meeting-outcome, enacted) ${DECISION_ID}"

	# lifecycle 'review' feeds the minutes-in-review stats-block on the dashboard.
	MINUTES_ID="$(seed_object minutes \
		"{\"title\":\"${SEED_TAG} Notulen raadsvergadering\",\"lifecycle\":\"review\",\"meeting\":\"${LIVE_MEETING_ID}\"}" \
		'the minutes')"
	echo "[ci-seed]   minutes ${MINUTES_ID}"

	# ⚠️ NO action-item is seeded here, deliberately. The ActionItem schema carries
	#   "x-openregister-object-source": {"provider": "caldav-vtodo", "readOnly": true}
	# so it is not an ordinary OpenRegister object: writes through the object API
	# are refused and findAll() delegates to the CalDAV provider rather than
	# reading the magic table. (That is also why the eight action-item entries in
	# the register's seedData are never served, and why
	# lib/Migration/MigrateActionItemsToDeckLeaf.php is an explicit no-op.)
	# Action items are seeded per-spec through decidiq's own endpoint,
	# POST /apps/decidiq/api/action-items → ActionItemWriter → TaskService.
	# ── The five Goals `goals-pages.spec.ts` asserts on ────────────────────────
	#
	# 🔴 THESE TITLES ARE THE ASSERTION, SO THEY CARRY NO ${SEED_TAG} PREFIX.
	# `SEEDED_GOAL_TITLES` matches with `{ exact: true }`; prefixing them the way
	# the objects above are prefixed would break the very test this seeds for.
	#
	# WHY THEY HAVE TO BE SEEDED HERE AT ALL. The spec's docblock points at
	# lib/Settings/register.d/66-organisation-goals.json, but that file declares
	# only the `Goal` SCHEMA. The five objects live in three different profile
	# files — association.json (1), corporate.json (2), municipality.json (2) —
	# so NO single `example_profile` produces all five, and CI picks `none`
	# (see the setup/config call below) precisely to keep a whole demo dataset
	# out of the lists other specs assert on. The spec was written against the
	# older behaviour where installing planted everything.
	#
	# Measured on development 2026-08-31: E2E 4 failed / 139 passed, one of them
	# `Goals: index lists all five seeded goals` failing on
	# `getByText('Duurzame omzetgroei 2028')` → element(s) not found.
	#
	# Titles, descriptions, horizons, deadlines and statuses are copied verbatim
	# from those profiles so the fixture and the shipped example sets cannot
	# drift into disagreeing about what a Goal looks like.
	#
	# `body` is repointed at THIS run's governance body. The profiles reference
	# their own bodies by slug (`gemeenteraad-amsterdam`, `ledenraad-vng`,
	# `raad-van-bestuur-acme-bv`), none of which exist here — a dangling
	# reference would seed an object the Goals index cannot resolve or render.
	# `owner` is dropped for the same reason: `femke-halsema` is not a user on
	# this instance, and it is not a required field.
	seed_goal() {
		# $1 = title, $2 = description, $3 = horizon, $4 = startDate,
		# $5 = deadline, $6 = status, $7 = extra JSON fields (may be empty)
		local id
		id="$(seed_object goal \
			"{\"title\":\"$1\",\"description\":\"$2\",\"horizon\":\"$3\",\"body\":\"${BODY_ID}\",\"startDate\":\"$4\",\"deadline\":\"$5\",\"status\":\"$6\"$7}" \
			"the Goal \"$1\"")"
		echo "[ci-seed]   goal ${id}  $1"
	}

	seed_goal 'Duurzame omzetgroei 2028' \
		'Structurele omzetgroei realiseren binnen de duurzaamheidsdoelstellingen van de organisatie.' \
		'multi-year' '2026-01-01' '2028-12-31' 'active' \
		',"targetValue":20,"currentValue":6,"unit":"% omzetgroei"'

	seed_goal 'Operationele effectiviteit 2026' \
		'Procesdoorlooptijden binnen norm brengen als uitvoering van de groeidoelstelling.' \
		'annual' '2026-01-01' '2026-12-31' 'active' \
		',"targetValue":90,"currentValue":78,"unit":"% doorlooptijd binnen norm"'

	seed_goal 'Amsterdam klimaatneutraal' \
		'Netto CO2-uitstoot van de gemeentelijke organisatie naar nul in 2050.' \
		'multi-year' '2026-01-01' '2050-01-01' 'active' \
		',"targetValue":100,"currentValue":42,"unit":"% CO2-reductie behaald"'

	seed_goal 'Herzien parkeerbeleid vastgesteld' \
		'Vaststellen van het herziene parkeerbeleid binnenstad.' \
		'quarterly' '2026-04-01' '2026-09-30' 'at-risk' ''

	seed_goal 'Digitale dienstverlening leden' \
		'Alle leden kunnen digitaal diensten afnemen bij de vereniging.' \
		'annual' '2026-01-01' '2026-12-31' 'draft' ''

	# ── One built-in ProcessTemplate ───────────────────────────────────────────
	#
	# `process-configuration.spec.ts:41` failed at
	# `expect(page.locator('[data-testid="process-template-list"]')).toBeVisible()`
	# with `Received: hidden` — NOT because the list is missing, but because it is
	# EMPTY. ProcessTemplates.vue renders `<ul v-if="!store.loading">` regardless
	# of how many rows it has, and an empty `<ul>` is a zero-height box, which
	# Playwright reports as hidden. ProcessTemplateService::list() already carries
	# a comment describing exactly this shape from the last time it returned zero
	# rows.
	#
	# The built-in templates are NOT shipped with the schema: like the Goals
	# above, they live in the profile files (association.json, corporate.json and
	# municipality.json declare three each), so `example_profile=none` leaves the
	# catalogue empty. Same root cause, same fix.
	#
	# ONE is enough and one is deliberate. The spec asserts the list renders, then
	# takes the FIRST item carrying `process-template-builtin` and checks it is
	# read-only (duplicate offered, delete withheld). Seeding all nine would push
	# a full catalogue into every other list that reads this schema, which is the
	# thing the `none` profile exists to avoid.
	#
	# `Municipal Council` is the one that matches the rest of this fixture — the
	# governance body seeded above is a Gemeenteraad — and it is copied verbatim
	# from municipality.json (states, transitions, guards, voting rule and quorum
	# rule included) so the fixture cannot drift from the shipped template. The
	# profile's `@self` and `slug` are dropped: `@self` is import metadata the
	# object API sets itself, and the slug is assigned server-side.
	#
	# Single-quoted deliberately — the payload contains double quotes throughout
	# and no apostrophes, so this embeds it byte-for-byte with no escaping.
	PROCESS_TEMPLATE_ID="$(seed_object process-template \
		'{"name":"Municipal Council","description":"Legislative decision process for a municipal council (gemeenteraad), Gemeentewet.","context":"legislative","builtIn":true,"initialState":"draft","stateMachine":{"states":[{"name":"draft"},{"name":"proposed"},{"name":"deliberating"},{"name":"voting"},{"name":"decided"},{"name":"enacted"},{"name":"archived"}],"transitions":[{"name":"propose","from":"draft","to":"proposed"},{"name":"deliberate","from":"proposed","to":"deliberating"},{"name":"openVoting","from":"deliberating","to":"voting","chairOnly":true,"guards":["quorum_met","all_amendments_resolved"]},{"name":"decide","from":"voting","to":"decided","chairOnly":true},{"name":"enact","from":"decided","to":"enacted"},{"name":"archive","from":"enacted","to":"archived"}]},"votingRule":{"voteThreshold":"simple-majority","abstentionHandling":"exclude","tieBreakRule":"rejected"},"quorumRequired":true,"quorumRule":"More than half of the seats occupied (Gemeentewet art. 20)","allowDecideWithoutVote":false}' \
		'the built-in Municipal Council process template')"
	echo "[ci-seed]   process-template ${PROCESS_TEMPLATE_ID}  Municipal Council (built-in)"

	# ── One governing document with two versions ───────────────────────────────
	#
	# 🔴 THIS SPEC HAS NEVER RUN. `register-detail-widgets.spec.ts` asserts the
	# version-timeline widget renders both versions of "Afvalstoffenverordening
	# Amsterdam", and skips when it cannot find that record. It lives only in
	# municipality.json, and CI picks `example_profile=none` (see the setup/config
	# call below), so the record has never existed here and the test has skipped
	# on every run since it was written. A skip reads exactly like a pass in the
	# summary line, which is how it went unnoticed.
	#
	# Seeded here rather than by loading the profile, for the same reason as the
	# Goals and the ProcessTemplate above: a whole demo dataset would land in
	# every other list the specs assert on.
	#
	# The title carries no ${SEED_TAG} prefix because the spec matches it
	# exactly, and the fields are copied from municipality.json so the fixture
	# and the shipped example set cannot drift apart. `governingBody` is
	# repointed at THIS run's body: the profile names `gemeenteraad-amsterdam`,
	# which does not exist here, and a dangling reference would seed a document
	# the detail page cannot resolve.
	GOVERNING_DOC_ID="$(seed_object governing-document \
		"{\"type\":\"by-law\",\"citationTitle\":\"Afvalstoffenverordening Amsterdam\",\"officialTitle\":\"Verordening op de inzameling en verwerking van huishoudelijke afvalstoffen Amsterdam\",\"statutoryBasis\":[\"Gemeentewet art. 149\"],\"governingBody\":\"${BODY_ID}\",\"externalRegisterIdentifier\":\"CVDR641871\",\"currentVersionNumber\":2,\"currentEffectiveDate\":\"2025-06-01\",\"status\":\"in-effect\"}" \
		'the governing document "Afvalstoffenverordening Amsterdam"')"
	echo "[ci-seed]   governing-document ${GOVERNING_DOC_ID}  Afvalstoffenverordening Amsterdam"

	# BOTH versions, because the spec asserts both render and that the earlier
	# one reads as replaced. One version would satisfy "the widget rendered" and
	# prove nothing about the timeline.
	#
	# 🔴 `in-effect`, NOT `in-force`. The two schemas do NOT share a status
	# vocabulary: GoverningDocument accepts both (register.d/77), and
	# GoverningDocumentVersie accepts only
	# ['draft','adopted','in-effect','replaced','lapsed'] (register.d/55). This
	# line wrote the document's word onto a versie and the API answered 400,
	# which exits the seed and takes the WHOLE suite with it.
	for versie in \
		'1|2024-01-01|replaced|Eerste vaststelling van de Afvalstoffenverordening Amsterdam.' \
		'2|2025-06-01|in-effect|Geactualiseerde verordening na evaluatie 2025.'
	do
		IFS='|' read -r v_num v_date v_status v_notes <<<"${versie}"
		v_id="$(seed_object governing-document-versie \
			"{\"document\":\"${GOVERNING_DOC_ID}\",\"versionNumber\":${v_num},\"effectiveDate\":\"${v_date}\",\"status\":\"${v_status}\",\"notes\":\"${v_notes}\"}" \
			"governing-document version ${v_num}")"
		echo "[ci-seed]   governing-document-versie ${v_id}  v${v_num} (${v_status})"
	done

	echo "[ci-seed] governance fixture seeded."
fi

# Verify the fixture is READABLE — a create that 2xx'd but is not listable would
# leave every "first object" spec skipping exactly as before.
python3 - "$BASE" "$USER_NAME" "$USER_PASS" <<'PY'
import base64, json, sys, urllib.request

base, user, password = sys.argv[1], sys.argv[2], sys.argv[3]
token = base64.b64encode(f'{user}:{password}'.encode()).decode()

required = {
    'governance-body': 1,
    'meeting': 2,
    'participant': 2,
    'agenda-item': 3,
    'decision': 3,
    'minutes': 1,
    # goals-pages.spec.ts asserts all FIVE by exact title, so five is the floor.
    # Listed here for the reason this whole block exists: a create that answered
    # 2xx but is not listable would leave the spec failing on "element(s) not
    # found", which reads as a missing feature rather than a seed that did not
    # land.
    'goal': 5,
    # process-configuration.spec.ts needs the list to be non-EMPTY, since an
    # empty <ul> is a zero-height box that Playwright reports as hidden. One
    # built-in template is the floor; see the seeding note above for why it is
    # not the full catalogue.
    'process-template': 1,
    # register-detail-widgets.spec.ts asserts BOTH versions of the seeded
    # governing document render in the version timeline. The document is the
    # floor; the versions are counted separately below because a document with
    # no versions renders an empty timeline, which is the shape that made this
    # spec skip silently for its whole life.
    'governing-document': 1,
    'governing-document-versie': 2,
    # NOT action-item: CalDAV-backed and read-only through this API (see above).
}

failed = []
for slug, minimum in required.items():
    url = f'{base}/index.php/apps/openregister/api/objects/decidiq/{slug}?_limit=200'
    req = urllib.request.Request(url, headers={
        'Authorization': f'Basic {token}',
        'OCS-APIRequest': 'true',
        'Accept': 'application/json',
    })
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            body = json.loads(resp.read().decode())
    except Exception as exc:  # noqa: BLE001
        print(f'::error::Could not list {slug}: {exc}')
        failed.append(slug)
        continue
    items = body if isinstance(body, list) else body.get('results', [])
    print(f'[ci-seed] {slug}: {len(items)} object(s)')
    if len(items) < minimum:
        print(f'::error::{slug} has {len(items)} object(s), need at least {minimum}.')
        failed.append(slug)

if failed:
    print('::error::The governance fixture is incomplete: ' + ', '.join(failed))
    print('::error::Specs that read "the first <object>" would SKIP rather than fail, reporting '
          '"No <x> objects found" and blaming the instance.')
    raise SystemExit(1)
print('[ci-seed] governance fixture verified.')
PY

# ── 3. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080`. It sets
# PHP_CLI_SERVER_WORKERS=8, but the first hit still pays a cold opcache and the
# first parse of a multi-megabyte webpack bundle, and that cost lands entirely
# on whichever spec happens to run first. Warming it here puts that cost in the
# environment-preparation step where it belongs, rather than inside an assertion
# timeout that would then have to keep drifting upward.
#
# Failures are ignored on purpose: this is a warm-up, not a gate. The real
# checks are above and below.
for path in \
	"/index.php/apps/decidiq/" \
	"/index.php/apps/decidiq/api/health" \
	"/index.php/settings/admin/decidiq" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/decidiq/js/…` on the CI runner,
# `/custom_apps/decidiq/js/…` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC error
# page, served through index.php. A status-code check therefore reports success
# while fetching a 40 KB HTML page instead of a multi-MB bundle, so the warm-up
# silently warms nothing.
#
# Read the real src out of the rendered app page instead, and verify the
# response is actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/decidiq/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*decidiq-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

BUNDLE_BYTES=0
if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
	# Third field of the -w format. Default to 0 on anything unparseable so the
	# floor below treats "I could not measure it" as "it is too small", not as
	# a pass.
	BUNDLE_BYTES="$(printf '%s' "$BUNDLE_INFO" | awk '{print $3+0}')"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# The floor exists because the content-type check ALONE cannot fail on the
# case it was written for.
#
# `truncate -s 0 js/decidiq-main.js` leaves a file that Nextcloud still serves
# as **HTTP 200 with Content-Type application/javascript** — length zero. So a
# `case "$BUNDLE_INFO" in *javascript*)` gate passes a bundle that contains no
# application at all, and every UI spec then fails on a selector timeout whose
# message names a component. The truncation control run recorded on #378
# (30887496886) did not catch this either: it truncated the bundle AFTER the
# gate had already run, so the gate itself was never shown capable of failing.
#
# 1 MB is deliberately far below the measured 14,191,177 bytes (run
# 31040165156) — it is a floor against emptiness and gross truncation, not a
# size ratchet that would go red on a legitimate bundle-size change.
BUNDLE_MIN_BYTES=1000000

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# **HTTP 200 and Content-Type text/html**, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# Note that this gate reads the SERVED response, not the file on disk, and it
# is placed at the very end so that a run which reaches the specs has provably
# been able to fetch real JavaScript for the SPA. (A file-on-disk check is also
# defeated by global-setup.ts's ensureBundleBuilt(), which does an
# fs.existsSync() and silently rebuilds — so a *truncated* bundle passes that
# check while serving zero bytes of app.)
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle content-type OK (JavaScript)."
			;;
		*)
			echo "::error::The Decidiq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac

	if [ "$BUNDLE_BYTES" -lt "$BUNDLE_MIN_BYTES" ]; then
		echo "::error::The Decidiq frontend bundle served only ${BUNDLE_BYTES} bytes (floor: ${BUNDLE_MIN_BYTES})."
		echo "::error::A truncated bundle is served as HTTP 200 application/javascript, so the content-type check above CANNOT catch it — this floor is the check that can."
		echo "::error::The SPA will not mount and every UI spec would fail on a selector timeout naming a component rather than the bundle."
		exit 1
	fi
	echo "[ci-seed] bundle size OK (${BUNDLE_BYTES} bytes >= ${BUNDLE_MIN_BYTES})."
fi

echo "[ci-seed] done."

# ── Settle the demo-data decision ────────────────────────────────────────────
# 🔴 OR THE SETUP WIZARD MASKS EVERY CLICK. ADR-111 added an OPTIONAL
# `demo-data` step, and CnAppRoot opens the non-gating wizard as a full modal
# mask while ANY optional step that is not info/summary is reported not-done —
# in every fresh browser context, so once per spec.
#
# Measured on this app's development at the ADR-111 merge: clicks failed with
# "locator resolved to <button ...> - attempting click action", the call log
# naming <ol class="cn-wizard-dialog__progress"> as the interceptor. The element
# was found; the click never landed.
#
# SKIPPED, not installed: recording the decision is what closes the wizard.
# Installing would push the app's whole demo dataset into every list the suite
# asserts on. `demo-data-setup-step.spec.ts` exercises the install deliberately.
#
# Uses the workflow's own exported credentials rather than this script's, so it
# does not depend on where in the file it sits.
#
# 🔴 THE ACTION ID IS `skip-example-set`, AND THIS CALLED `skip-demo-data`.
#
# ADR-111's step was renamed demo-data -> example-set, and SetupController
# implements exactly two ids (`load-example-set`, `skip-example-set`),
# returning 404 "Unknown setup action" for anything else. So this POST had
# been answering 404 on every run, the decision was never recorded, and the
# wizard this block exists to close stayed open. Measured on development
# 2026-08-31: E2E 45 failed / 123 passed, with 228 `intercepts pointer
# events` lines naming the cn-wizard-dialog modal mask and 76 clicks timing
# out at 20s. The failures were one seeding bug wearing 45 costumes.
#
# The old "tolerant on purpose" note is what hid it: a 404 read as the
# benign 400 it described, so the log line printed the failure and nothing
# treated it as one. Tolerance that cannot distinguish "this app has no such
# step" from "this app renamed it" is not tolerance, it is blindness.
#
# `example_profile=none` rather than the skip action, because it closes BOTH
# steps: status() reports `example-set.done` from `$picked !== ''` and
# `load-example-set.done` from `$picked === NONE_PROFILE`. The skip action
# writes only DEMO_DECIDED_KEY and would leave `example-set` open — still
# enough wizard to mask every click.
# 🔴 `${BASE}`, NOT ITS OWN RESOLUTION. This line used to read
# `${BASE_URL:-${NEXTCLOUD_URL:-http://localhost:8080}}`, which ignores
# PLAYWRIGHT_BASE_URL and falls back to the SHARED dev container — the exact
# default the top of this script refuses, in the block whose whole job is to
# WRITE app config. On CI it worked because the workflow exports BASE_URL; on a
# throwaway rig driven by PLAYWRIGHT_BASE_URL it aimed a POST at :8080 and the
# run then failed on a status document it had never written, from an instance
# it had never touched. `${BASE}` is already resolved and already guarded.
DEMO_BASE="${BASE}"
DEMO_CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 300 \
	-u "${ADMIN_USER:-admin}:${ADMIN_PASSWORD:-admin}" -X POST \
	-H 'Content-Type: application/json' -H 'OCS-APIRequest: true' \
	--data '{"example_profile":"none"}' \
	"${DEMO_BASE}/index.php/apps/decidiq/api/setup/config" || echo 000)"
echo "[ci-seed] POST setup/config example_profile=none -> HTTP ${DEMO_CODE}"

# ASSERT THE STATE, NOT THE STATUS CODE. A 200 says the endpoint answered; it
# does not say the wizard will stay shut. Reading the steps back is the only
# check that would still fail if the contract moved again — which is exactly
# what happened last time.
SETUP_STATUS="$(curl -sS --max-time 60 \
	-u "${ADMIN_USER:-admin}:${ADMIN_PASSWORD:-admin}" \
	-H 'OCS-APIRequest: true' \
	"${DEMO_BASE}/index.php/apps/decidiq/api/setup/status" || echo '{}')"
if printf '%s' "${SETUP_STATUS}" \
	| python3 -c 'import json,sys
try:
    s = json.load(sys.stdin).get("steps", {})
except Exception:
    sys.exit(1)
sys.exit(0 if all(s.get(k, {}).get("done") for k in ("example-set", "load-example-set")) else 1)'; then
	echo "[ci-seed] setup steps report done — the wizard will not mask the suite."
else
	echo "[ci-seed] ERROR: setup steps are NOT done after seeding." >&2
	echo "[ci-seed]        status was: ${SETUP_STATUS}" >&2
	echo "[ci-seed]        The setup wizard will open as a modal mask and every" >&2
	echo "[ci-seed]        click in every spec will time out. Failing here, where" >&2
	echo "[ci-seed]        the cause is one line, instead of in 45 specs where it" >&2
	echo "[ci-seed]        is not." >&2
	exit 1
fi
