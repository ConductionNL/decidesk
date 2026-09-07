<?php

/**
 * Decidiq approval-route controller.
 *
 * The HTTP surface over {@see \OCA\Decidiq\Service\ApprovalRouteService}:
 * instantiate a route against a subject, and record an action on it.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Instantiate and advance approval routes.
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 */
class ApprovalRouteController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ApprovalRouteService $service The route engine.
	 * @param ApprovalRouteConclusionAnnouncer $announcer The one door a conclusion leaves by.
	 * @param IUserSession $userSession The session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ApprovalRouteService $service,
		private readonly ApprovalRouteConclusionAnnouncer $announcer,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Instantiate a route against a subject.
	 *
	 * @return JSONResponse The created stages, or an error.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	#[NoAdminRequired]
	public function instantiate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$route = $this->request->getParam('route');
		$subject = (string)$this->request->getParam('subject', '');
		$subjectSchema = (string)$this->request->getParam('subjectSchema', '');
		if (is_array($route) === false || $subject === '' || $subjectSchema === '') {
			return new JSONResponse(
				['message' => 'route, subject and subjectSchema are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			// AUTHORISATION, before any write. Being signed in is not permission
			// to start a sign-off route on someone else's object; this asks
			// whether the caller can reach the subject at all, and OpenRegister's
			// RBAC answers as the acting user.
			$this->service->assertSubjectAccessible(subject: $subject, subjectSchema: $subjectSchema);

			$stages = $this->service->instantiate(route: $route, subject: $subject, subjectSchema: $subjectSchema);
		} catch (Throwable $e) {
			// The engine's refusals are the point of the engine, so the caller
			// gets the reason rather than a generic failure.
			$this->logger->warning(
				'Decidiq: could not instantiate an approval route',
				['app' => Application::APP_ID, 'subject' => $subject, 'reason' => $e->getMessage()]
			);
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['stages' => $stages], Http::STATUS_CREATED);
	}//end instantiate()

	/**
	 * Record an action on a subject's active stage.
	 *
	 * The ACTOR IS TAKEN FROM THE SESSION, never from the body. Reading it from
	 * the request would let any caller sign off as anyone, which is the one
	 * thing a sign-off route exists to prevent.
	 *
	 * @return JSONResponse The recorded action, or an error.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	#[NoAdminRequired]
	public function record(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$action = [
			'subject' => (string)$this->request->getParam('subject', ''),
			'subjectSchema' => (string)$this->request->getParam('subjectSchema', ''),
			'step' => $this->request->getParam('step'),
			'actor' => $user->getUID(),
			'actorType' => (string)$this->request->getParam('actorType', 'user'),
			'onBehalfOf' => (string)$this->request->getParam('onBehalfOf', ''),
			'mandate' => (string)$this->request->getParam('mandate', ''),
			'action' => (string)$this->request->getParam('action', ''),
			'returnToStep' => $this->request->getParam('returnToStep'),
			'comment' => (string)$this->request->getParam('comment', ''),
			'advice' => (string)$this->request->getParam('advice', ''),
		];

		try {
			$recorded = $this->service->record(action: $action);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: an approval action was refused',
				['app' => Application::APP_ID, 'subject' => $action['subject'], 'reason' => $e->getMessage()]
			);
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		// A final signature given over THIS surface concludes the route just as
		// surely as one arriving over the cross-app seam, and the producer that
		// delegated its runtime here is waiting on the announcement.
		$this->announcer->announceIfConcluded(subject: (string)$action['subject']);

		return new JSONResponse($recorded, Http::STATUS_CREATED);
	}//end record()
}//end class
