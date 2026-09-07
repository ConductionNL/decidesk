/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Result plumbing for a `form-dialog` slot replacement.
 *
 * A page that replaces its built-in form dialog through the `form-dialog`
 * slot renders its own `CnFormDialog`, and the host holds no ref to it. That
 * makes the save result the only channel back: `CnFormDialog` raises its
 * `loading` flag on submit and ONLY `setResult()` lowers it again, while
 * `no-close` is bound to `loading`. A replacement that drops the result
 * therefore locks its own modal open, on a successful save as well as on a
 * failed one, with no error and nothing in the console.
 *
 * `CnDetailPage`'s slot `confirm` resolves to `{ success: true, data }` or
 * `{ error }` for exactly this reason. `CnIndexPage`'s does not resolve to
 * anything yet (nextcloud-vue#944 left that half alone deliberately), and it
 * closes its dialog by flipping the slot's `show` instead — so this helper
 * has to accept "no result" as a normal outcome rather than a fault, and one
 * replacement component can serve both pages.
 *
 * Kept in a plain .js module because this repo's vitest runs on plain Vite
 * with no @vitejs/plugin-vue, so a `.vue` file cannot be imported by a spec.
 * The logic that must be tested lives here and the SFC calls it.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */

/**
 * Hand a slot `confirm`'s resolved result back to the dialog that submitted it.
 *
 * @param {?object} dialog The CnFormDialog instance (a `$refs` entry), or null
 *   when the dialog has already unmounted because `show` went false.
 * @param {?object} result What the slot's `confirm` resolved to:
 *   `{ success: true, data }` / `{ error }` on CnDetailPage, `undefined` on a
 *   page whose confirm returns nothing.
 *
 * @return {boolean} True when the dialog was settled, false when there was
 *   nothing to settle (no dialog, or no result to settle it with).
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
export function settleFormDialogResult(dialog, result) {
	// `setResult` reads `resultData.success`, so a null / undefined result
	// would throw rather than close anything. A page whose confirm resolves
	// to nothing closes its dialog itself; leave that path alone.
	if (!result || typeof result !== 'object') return false
	if (!dialog || typeof dialog.setResult !== 'function') return false
	dialog.setResult(result)
	return true
}
