<?php

/**
 * Decidiq Decision Publication Service
 *
 * Owns the server-side publication of a Decision: loading the object through
 * OpenRegister, validating that it is adopted and not yet published, stamping
 * `isPublished` / `publishedAt`, persisting the change and emitting the
 * fail-soft activity-feed event. DecisionController keeps only the HTTP
 * envelope and the admin authorization gate.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Activity\DecidiqProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side Decision publication (OWASP A01 / ADR-005).
 *
 * Every method returns a plain `{status, data}` envelope so the caller can
 * render it as a JSONResponse without the service knowing about the HTTP
 * layer beyond the status constant.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 */
class DecisionPublicationService {
	/**
	 * Constructor for DecisionPublicationService.
	 *
	 * @param ContainerInterface $container DI container (lazy-loads OpenRegister services)
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish a Decision.
	 *
	 * Validates server-side that outcome='adopted' and that the decision is
	 * not already published before persisting, then stamps `isPublished`
	 * ('public', the p3-citizen-participation enum value) and `publishedAt`.
	 *
	 * @param string $decisionId UUID of the Decision object
	 * @param string $actorUid Nextcloud UID of the publishing administrator
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return array{status: int, data: array<string, mixed>} Response status + payload
	 */
	public function publish(string $decisionId, string $actorUid): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return $this->envelope(status: Http::STATUS_SERVICE_UNAVAILABLE, message: 'OpenRegister is not available.');
		}

		$objectService->setRegister('decidiq');
		$objectService->setSchema('decision');

		$decision = $this->loadDecision(objectService: $objectService, decisionId: $decisionId);
		if ($decision === null) {
			return $this->envelope(
				status: Http::STATUS_NOT_FOUND,
				message: sprintf('Decision "%s" not found.', $decisionId)
			);
		}

		// Server-side guard: only adopted, unpublished decisions may be published.
		$rejection = $this->resolvePublicationRejection(decision: $decision);
		if ($rejection !== null) {
			return $this->envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: $rejection);
		}

		return $this->persistPublication(
			objectService: $objectService,
			decision: $decision,
			decisionId: $decisionId,
			actorUid: $actorUid
		);

	}//end publish()

	/**
	 * Load the Decision object as a plain array, or null when it does not exist.
	 *
	 * OpenRegister's find() raises DoesNotExistException for an unknown id
	 * (instead of returning null), so treat that — and a null return — as
	 * not-found.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param string $decisionId UUID of the Decision object
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadDecision(object $objectService, string $decisionId): ?array {
		try {
			$entity = $objectService->find(id: $decisionId);
		} catch (DoesNotExistException) {
			$entity = null;
		}

		if ($entity === null) {
			return null;
		}

		return (array)$entity->getObject();
	}//end loadDecision()

	/**
	 * Resolve why a Decision may not be published, or null when it may.
	 *
	 * P3-citizen-participation: isPublished is an enum
	 * ('internal' | 'public' | 'confidential'). Only 'internal' decisions are
	 * eligible; the legacy boolean form is backward-compatible by treating
	 * `true` as already published.
	 *
	 * @param array<string, mixed> $decision The Decision object array
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return string|null Rejection message, or null when publication may proceed
	 */
	private function resolvePublicationRejection(array $decision): ?string {
		if (($decision['outcome'] ?? '') !== 'adopted') {
			return 'Only decisions with outcome "adopted" may be published.';
		}

		$published = ($decision['isPublished'] ?? 'internal');
		if (in_array(needle: $published, haystack: ['public', 'confidential', true], strict: true) === true) {
			return 'Decision is already published.';
		}

		return null;
	}//end resolvePublicationRejection()

	/**
	 * Stamp and persist the publication, then emit the activity-feed event.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed> $decision The Decision object array
	 * @param string $decisionId UUID of the Decision object
	 * @param string $actorUid Nextcloud UID of the publishing administrator
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return array{status: int, data: array<string, mixed>} Response status + payload
	 */
	private function persistPublication(object $objectService, array $decision, string $decisionId, string $actorUid): array {
		$updated = $decision;

		$updated['isPublished'] = 'public';
		$updated['publishedAt'] = date(DATE_ATOM);

		try {
			$saved = $objectService->saveObject(
				object: $updated,
				register: 'decidiq',
				schema: 'decision',
				uuid: $decisionId
			);

			// The response carries what was STORED, not what was sent.
			// ObjectServiceInterface::saveObject() answers the stored entity,
			// so anything OpenRegister adds or normalises on write (a version,
			// an @self block, a defaulted property) reaches the caller. The
			// branch that used to be here tested for a stdClass or an array,
			// neither of which that contract can return, so the local payload
			// was always what came back.
			$result = (array)$saved->getObject();

			$this->logger->info(
				'Decidiq: Decision published',
				['id' => $decisionId, 'publishedBy' => $actorUid]
			);

			$this->publishActivity(
				title: (string)($updated['title'] ?? $decisionId),
				decisionId: $decisionId
			);

			return ['status' => Http::STATUS_OK, 'data' => $result];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: Failed to publish Decision',
				['id' => $decisionId, 'exception' => $e->getMessage()]
			);
			return $this->envelope(
				status: Http::STATUS_SERVICE_UNAVAILABLE,
				message: 'Failed to publish decision. Please try again.'
			);
		}//end try

	}//end persistPublication()

	/**
	 * Publish the governance activity-feed event for a published decision.
	 *
	 * Fail-soft: the decision is already persisted, so an activity failure is
	 * logged at debug level and swallowed.
	 *
	 * @param string $title Human-readable decision title
	 * @param string $decisionId UUID of the Decision object
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	private function publishActivity(string $title, string $decisionId): void {
		try {
			$this->container->get(ActivityPublisherService::class)->publishGovernanceEvent(
				subject: DecidiqProvider::SUBJECT_DECISION_PUBLISHED,
				title: $title,
				status: 'public',
				objectType: 'decision',
				objectUuid: $decisionId,
				segment: 'decisions'
			);
		} catch (\Throwable $activityError) {
			$this->logger->debug(
				'Decidiq: activity publish skipped',
				['error' => $activityError->getMessage()]
			);
		}//end try

	}//end publishActivity()

	/**
	 * Build a message-only response envelope.
	 *
	 * @param int $status HTTP status code
	 * @param string $message Human-readable message
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 *
	 * @return array{status: int, data: array<string, mixed>}
	 */
	private function envelope(int $status, string $message): array {
		return [
			'status' => $status,
			'data' => ['message' => $message],
		];

	}//end envelope()
}//end class
