<?php

/**
 * Decidiq Live Meeting Controller
 *
 * Controller for live meeting operations such as recording decisions during
 * an active meeting via the live decision panel.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
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
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\LiveDecisionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for live meeting operations.
 *
 * Provides the endpoint for recording decisions during an active meeting.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 */
class LiveMeetingController extends Controller {
	/**
	 * Constructor for LiveMeetingController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param LiveDecisionService $liveDecisionService The live decision service
	 * @param IUserSession $userSession The current user session
	 * @param IGroupManager $groupManager Group manager for admin checks
	 * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function __construct(
		IRequest $request,
		private LiveDecisionService $liveDecisionService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ParticipantResolver $participantResolver,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Verify that the caller is an NC admin or holds a chair/secretary role for the meeting.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return JSONResponse|null Null if authorised, a 403/401 JSONResponse otherwise.
	 */
	private function requireChairOrAdmin(string $meetingId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		if ($this->participantResolver->hasRole(
			meetingId: $meetingId,
			nextcloudUid: $userId,
			roles: ['chair', 'secretary'],
		) === true
		) {
			return null;
		}

		return new JSONResponse(
			['error' => 'Forbidden: chair or secretary role required for this meeting.'],
			Http::STATUS_FORBIDDEN
		);

	}//end requireChairOrAdmin()

	/**
	 * Record a decision during an active meeting.
	 *
	 * POST /api/meetings/{meetingId}/live-decisions
	 *
	 * Body: { "title": string, "text": string, "outcome": string, "legalBasis"?: string }
	 *
	 * Returns 200 with the created Decision object on success.
	 * Returns 400 when required fields are missing.
	 * Returns 401 when not authenticated.
	 * Returns 403 when the caller does not hold a chair or secretary role for the meeting.
	 * Returns 404 when the Meeting is not found.
	 * Returns 409 when the Meeting is not in 'opened' state.
	 *
	 * @param string $meetingId The Meeting ID
	 *
	 * @return JSONResponse The created Decision object
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	#[NoAdminRequired]
	public function recordLiveDecision(string $meetingId): JSONResponse {
		$denied = $this->requireChairOrAdmin(meetingId: $meetingId);
		if ($denied !== null) {
			return $denied;
		}

		try {
			$title = $this->request->getParam('title');
			$text = $this->request->getParam('text');
			$outcome = $this->request->getParam('outcome');

			if (empty($title) === true || empty($text) === true || empty($outcome) === true) {
				return new JSONResponse(
					['error' => 'Missing required fields: title, text, outcome'],
					400
				);
			}

			$decisionData = [
				'title' => $title,
				'text' => $text,
				'outcome' => $outcome,
				'legalBasis' => $this->request->getParam('legalBasis'),
			];

			$decisionSlug = $this->liveDecisionService->recordDecision(
				$meetingId,
				$decisionData
			);

			return new JSONResponse(
				[
					'slug' => $decisionSlug,
					'message' => 'Decision recorded successfully',
				]
			);
		} catch (MissingObjectException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 404);
		} catch (\Exception $e) {
			if ((int)$e->getCode() === 409) {
				return new JSONResponse(['error' => $e->getMessage()], 409);
			}

			return new JSONResponse(['error' => 'Internal server error.'], 500);
		}//end try
	}//end recordLiveDecision()
}//end class
