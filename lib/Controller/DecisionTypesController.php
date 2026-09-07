<?php

/**
 * Decidiq decision-type vocabulary endpoint.
 *
 * Serves the configured decisionType vocabulary to the frontend. The
 * create-proposal pickers (CnDecisionsTab / CnDecisionsWidget) render inside
 * FOREIGN apps' pages, where decidiq's initial state is never provided — an
 * endpoint is the only channel that reaches them. Before this endpoint the
 * pickers hardcoded five types, so a type an administrator added to the
 * registry validated fine at the write path and never appeared in any picker.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\DecisionTypeRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads the decisionType vocabulary for picker surfaces.
 *
 * AUTH POSTURE. The vocabulary is configuration labels, not object data:
 * there is no per-object rule to enforce, so an authenticated session is the
 * whole requirement — the same posture the registry's own write validation
 * applies through IntegrationController::createDecision(). Unauthenticated
 * callers are refused so the vocabulary is not an anonymous fingerprinting
 * surface.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
 */
class DecisionTypesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param IUserSession $userSession Nextcloud user session
	 * @param DecisionTypeRegistry $registry The decisionType vocabulary authority
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly DecisionTypeRegistry $registry,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the configured decisionType vocabulary.
	 *
	 * GET /api/v1/decision-types
	 *
	 * Returns 200 with { types: string[] } — the registry's configured
	 * vocabulary, which is the shipped seed until an administrator changes it.
	 * Returns 401 when unauthenticated.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['types' => $this->registry->getTypes()]);
	}//end index()
}//end class
