<?php

/**
 * Decidiq Minutes Controller
 *
 * Controller for Minutes-specific operations such as draft generation
 * and server-side lifecycle transition enforcement.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\ALVMinutesService;
use OCA\Decidiq\Service\MinutesAccessGuard;
use OCA\Decidiq\Service\MinutesDocumentService;
use OCA\Decidiq\Service\MinutesGenerationService;
use OCA\Decidiq\Service\MinutesWorkflowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for Minutes-specific operations.
 *
 * Provides endpoints for generating a structured Dutch draft from
 * a linked meeting's data and for enforcing server-side lifecycle
 * transitions with signatory attribution.
 *
 * Every endpoint is the same three steps: authorise, delegate, respond. The
 * authorisation lives in MinutesAccessGuard, the work in a service, and the
 * exception-to-status mapping in MinutesResponder — so what is left here is
 * only the routing surface, which is exactly the part that must not drift.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesController extends Controller {
	/**
	 * Constructor for MinutesController.
	 *
	 * Note: userId is intentionally NOT injected via DI. The Nextcloud container
	 * caches service instances as singletons; resolving the UID at construction
	 * time would freeze it as null when the container is first built in a cron or
	 * pre-flight context. The UID is resolved per-request via $this->userSession.
	 *
	 * @param IRequest $request The HTTP request
	 * @param MinutesGenerationService $generationService The generation service
	 * @param ALVMinutesService $alvMinutesService The ALV minutes service
	 * @param MinutesWorkflowService $workflowService Action items + approval submission
	 * @param IUserSession $userSession The current user session
	 * @param MinutesAccessGuard $accessGuard Per-object minutes authorisation
	 * @param MinutesDocumentService $documentService Document generation + persistence service
	 * @param MinutesResponder $responder Maps operation failures to HTTP statuses
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	public function __construct(
		IRequest $request,
		private MinutesGenerationService $generationService,
		private ALVMinutesService $alvMinutesService,
		private MinutesWorkflowService $workflowService,
		private IUserSession $userSession,
		private readonly MinutesAccessGuard $accessGuard,
		private MinutesDocumentService $documentService,
		private readonly MinutesResponder $responder,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Require chair, secretary, or NC admin role for operations on a Minutes record.
	 *
	 * Resolves the associated meeting via the minutes relations map and checks
	 * participant records for a chair or secretary role, mirroring AgendaController.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @return JSONResponse|null Null if authorised, a 401/403 JSONResponse otherwise.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function requireChairOrAdminForMinutes(string $minutesId): ?JSONResponse {
		return $this->accessGuard->requireChairOrAdmin(minutesId: $minutesId);
	}//end requireChairOrAdminForMinutes()

	/**
	 * Resolve the display name of the authenticated user, or an empty string.
	 *
	 * @return string The display name
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function currentDisplayName(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getDisplayName();
	}//end currentDisplayName()

	/**
	 * Generate a Dutch draft text for the given Minutes object.
	 *
	 * POST /api/minutes/{minutesId}/generate-draft
	 *
	 * Returns { "preview": "<generated text>" } on success.
	 * Returns 401 when the request is not authenticated.
	 * Returns 403 when the caller is not a Nextcloud admin.
	 * Returns 404 when the Minutes object is not found.
	 * Returns 422 when no Meeting is linked to the Minutes record.
	 * Returns 503 when OpenRegister is unavailable.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function generateDraft(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		return $this->responder->runDraft(
			operation: fn (): array => ['preview' => $this->generationService->generateDraft($minutesId)]
		);

	}//end generateDraft()

	/**
	 * Transition the lifecycle state of a Minutes object server-side.
	 *
	 * POST /api/minutes/{minutesId}/transition
	 *
	 * Validates that the requested lifecycle value is the immediate next step in
	 * the fixed sequence (draft → review → approved → signed → published).
	 * Populates signedBy from the authenticated server-side user session for the
	 * "approved" and "signed" transitions so that forged client-side attribution
	 * is impossible.
	 *
	 * All lifecycle transitions require the caller to hold Nextcloud admin rights
	 * (governance-role enforcement / OWASP A01 — Broken Access Control).
	 *
	 * Returns 200 with the updated Minutes object on success.
	 * Returns 401 when the request is not authenticated.
	 * Returns 403 when the user lacks the required governance role.
	 * Returns 404 when the Minutes object is not found.
	 * Returns 422 when the requested transition is not the valid next step.
	 * Returns 503 when OpenRegister is unavailable.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function transition(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		$newLifecycle = $this->request->getParam('lifecycle');
		if (is_string($newLifecycle) === false || $newLifecycle === '') {
			return $this->responder->badRequest(message: 'Missing or invalid lifecycle parameter.');
		}

		$displayName = $this->currentDisplayName();

		return $this->responder->runLifecycle(
			operation: fn (): array => $this->generationService->transition(
				minutesId: $minutesId,
				newLifecycle: $newLifecycle,
				displayName: $displayName,
			)
		);

	}//end transition()

	/**
	 * Generate an ALV minutes draft.
	 *
	 * POST /api/minutes/{minutesId}/generate-alv
	 *
	 * Returns { "preview": "<generated ALV content>" } on success.
	 * Returns 400 when the request is invalid.
	 * Returns 401 when the request is not authenticated.
	 * Returns 404 when the Minutes object is not found.
	 * Returns 422 when the meeting is not an ALV type.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function generateALVDraft(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		return $this->responder->runCoded(
			operation: fn (): array => [
				'preview' => $this->alvMinutesService->generateALVDraft($minutesId)['content'],
			],
			honouredStatus: 422
		);

	}//end generateALVDraft()

	/**
	 * Distribute approved ALV minutes to members.
	 *
	 * POST /api/minutes/{minutesId}/distribute
	 *
	 * Returns { "notified": N } on success.
	 * Returns 401 when not authenticated.
	 * Returns 403 when Minutes lifecycle is not approved or signed.
	 * Returns 404 when the Minutes object is not found.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function distributeALVMinutes(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		return $this->responder->runCoded(
			operation: fn (): array => ['notified' => $this->alvMinutesService->distribute($minutesId)],
			honouredStatus: 403
		);

	}//end distributeALVMinutes()

	/**
	 * Extract action item candidates from minutes content.
	 *
	 * POST /api/minutes/{minutesId}/extract-action-items
	 *
	 * Returns { "candidates": [ { "title": string, "suggestedAssignee": string|null }, ... ] }
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 */
	#[NoAdminRequired]
	public function extractActionItems(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		return $this->responder->runInternal(
			operation: fn (): array => [
				'candidates' => $this->workflowService->extractActionItems(minutesId: $minutesId),
			]
		);

	}//end extractActionItems()

	/**
	 * Save extracted action items after user confirmation.
	 *
	 * POST /api/minutes/{minutesId}/save-extracted-action-items
	 *
	 * Body: { "confirmed": [ { "title": string, "assignee"?: string, "dueDate"?: string }, ... ] }
	 *
	 * Returns { "saved": N } on success.
	 * Returns 400 when the Minutes lifecycle is published.
	 * Returns 404 when the Minutes object is not found.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 */
	#[NoAdminRequired]
	public function saveExtractedActionItems(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		$confirmed = $this->request->getParam('confirmed', []);

		return $this->responder->runInternal(
			operation: fn (): array => [
				'saved' => $this->workflowService->saveExtractedActionItems(
					minutesId: $minutesId,
					confirmed: $confirmed
				),
			]
		);

	}//end saveExtractedActionItems()

	/**
	 * Submit Minutes for approval.
	 *
	 * POST /api/minutes/{minutesId}/submit-for-approval
	 *
	 * Transitions lifecycle from draft to review and sends approval notifications.
	 *
	 * Returns 200 with updated lifecycle on success.
	 * Returns 404 when Minutes not found.
	 * Returns 409 when lifecycle is not draft.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
	 */
	#[NoAdminRequired]
	public function submitForApproval(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		return $this->responder->runInternal(
			operation: fn (): array => $this->workflowService->submitForApproval(minutesId: $minutesId)
		);

	}//end submitForApproval()

	/**
	 * Reject Minutes in review back to draft with a mandatory comment.
	 *
	 * POST /api/minutes/{minutesId}/reject
	 *
	 * Body: { "comment": "<why the minutes are rejected>" }
	 *
	 * Returns 200 with the updated Minutes object on success.
	 * Returns 401/403 per the chair/secretary guard.
	 * Returns 404 when the Minutes object is not found.
	 * Returns 422 when the comment is missing or the lifecycle is not review.
	 * Returns 503 when OpenRegister is unavailable.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	#[NoAdminRequired]
	public function reject(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		$comment = $this->request->getParam('comment');
		if (is_string($comment) === false) {
			$comment = '';
		}

		$user = $this->userSession->getUser();

		return $this->responder->runLifecycle(
			operation: fn (): array => $this->generationService->reject(
				minutesId: $minutesId,
				comment: $comment,
				userId: $user->getUID(),
			)
		);

	}//end reject()

	/**
	 * Generate a minutes document and persist it into the meeting folder.
	 *
	 * POST /api/minutes/{minutesId}/generate-document
	 *
	 * Body: { "format": "markdown" | "pdf" } (default: markdown)
	 *
	 * The PDF format uses Docudesk when its PdfService is resolvable; when it
	 * is not, a markdown document is produced and the response carries
	 * `docudesk: false` plus an explanatory note (honest fallback, never a
	 * silent failure).
	 *
	 * Returns 200 with { path, format, docudesk, note? } on success.
	 * Returns 401/403 per the chair/secretary guard.
	 * Returns 404 when the Minutes object is not found.
	 * Returns 422 when the format is unsupported or no Meeting is linked.
	 * Returns 503 when OpenRegister or the Files backend is unavailable.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	#[NoAdminRequired]
	public function generateDocument(string $minutesId): JSONResponse {
		$denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		$format = $this->request->getParam('format', 'markdown');
		if (is_string($format) === false || $format === '') {
			$format = 'markdown';
		}

		$displayName = $this->currentDisplayName();

		return $this->responder->runLifecycle(
			operation: fn (): array => $this->documentService->generate(
				minutesId: $minutesId,
				format: $format,
				displayName: $displayName,
			)
		);

	}//end generateDocument()
}//end class
