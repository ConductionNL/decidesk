<?php

/**
 * Decidiq Participation Responder
 *
 * Runs one citizen-participation service call behind the right authorization
 * guard and maps its outcome onto a JSONResponse — the guard/try/catch ladder
 * every participation endpoint would otherwise repeat verbatim.
 *
 * Two entry points mirror the two audiences the participation API serves:
 * `staffAction()` for governance-body (staff) operations, and
 * `citizenAction()` for operations any authenticated citizen may perform, which
 * refuses when the caller resolved no session identity. Both fail closed.
 *
 * Citizen participation is deliberately open to EVERY authenticated account —
 * that is what participation means, and the register's own authorization
 * baseline says so (`create: ["authenticated"]`). What `citizenAction()`
 * guarantees is narrower and is the part that matters: the identity recorded on
 * the resulting object is the SESSION's, resolved through `currentUid()`, and
 * never a value the request supplied.
 *
 * Shared by ParticipationController and ParticipationBudgetController.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/citizen-participation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Guards and response-maps citizen-participation service calls.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ParticipationResponder {
	/**
	 * Constructor for ParticipationResponder.
	 *
	 * @param ParticipationStaffGuard $staffGuard Actor + staff authority
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		private readonly ParticipationStaffGuard $staffGuard,
	) {

	}//end __construct()

	/**
	 * Run a staff-guarded service call and map its outcome to a response.
	 *
	 * @param callable $operation The service call to run.
	 * @param string|null $key Envelope key, or null to return the raw payload.
	 * @param int $status The success HTTP status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function staffAction(callable $operation, ?string $key = null, int $status = Http::STATUS_OK): JSONResponse {
		return ($this->requireStaff() ?? $this->respond(operation: $operation, key: $key, status: $status));
	}//end staffAction()

	/**
	 * Resolve the acting user's session-derived UID.
	 *
	 * Exposed so the routed controller method can bind the acting identity in
	 * its OWN body and hand it to the service call alongside the caller-supplied
	 * object id. That hand-off used to happen inside `citizenAction()`, which
	 * passed the UID into the operation closure as a parameter — behaviourally
	 * identical, but it hid the provenance of the recorded submitter/voter
	 * identity from anything reading the endpoint, human or mechanical. The
	 * value is ALWAYS the session's; no participation endpoint accepts a
	 * submitter, voter or author identity from the request.
	 *
	 * @return string|null The acting UID, or null when no user is signed in.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function currentUid(): ?string {
		return $this->staffGuard->currentUid();
	}//end currentUid()

	/**
	 * Run an authenticated-citizen service call and map its outcome to a response.
	 *
	 * @param callable $operation The service call to run.
	 * @param string|null $uid The acting UID the caller resolved from the session; null refuses.
	 * @param string|null $key Envelope key, or null to return the raw payload.
	 * @param int $status The success HTTP status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one
	 */
	public function citizenAction(callable $operation, ?string $uid, ?string $key = null, int $status = Http::STATUS_OK): JSONResponse {
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->respond(operation: $operation, key: $key, status: $status);
	}//end citizenAction()

	/**
	 * Require the current user to hold staff (governance-body) authority.
	 *
	 * Returns a 401/403 JSONResponse on failure, null on success. Fail closed.
	 *
	 * @return JSONResponse|null A response on failure, null when authorized.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function requireStaff(): ?JSONResponse {
		if ($this->staffGuard->currentUid() === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->staffGuard->isStaff() === false) {
			return new JSONResponse(['message' => 'Governance-body authority required'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end requireStaff()

	/**
	 * Execute a service call, enveloping the result or mapping the exception.
	 *
	 * @param callable $operation The service call to run.
	 * @param string|null $key Envelope key, or null to return the raw payload.
	 * @param int $status The success HTTP status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function respond(callable $operation, ?string $key, int $status): JSONResponse {
		try {
			$payload = $operation();
			if ($key !== null) {
				$payload = [$key => $payload];
			}

			return new JSONResponse($payload, $status);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], $this->statusForException(e: $e));
		}

	}//end respond()

	/**
	 * Map a service exception to an HTTP status code.
	 *
	 * The two participation exceptions are checked first because each extends
	 * one of the generic classes below it: a value that fails validation is 422
	 * and an action outside its window is 400, as the p3-citizen-participation
	 * scenarios state. Every other \InvalidArgumentException stays 400 and
	 * everything else stays 409.
	 *
	 * @param \Throwable $e The thrown exception.
	 *
	 * @return int The HTTP status.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	private function statusForException(\Throwable $e): int {
		if ($e instanceof ParticipationValidationException) {
			return Http::STATUS_UNPROCESSABLE_ENTITY;
		}

		if ($e instanceof ParticipationWindowClosedException || $e instanceof \InvalidArgumentException) {
			return Http::STATUS_BAD_REQUEST;
		}

		return Http::STATUS_CONFLICT;
	}//end statusForException()
}//end class
