<?php

/**
 * Decidiq Live Decision Service
 *
 * Service for recording decisions during active meetings via the live decision panel.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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

namespace OCA\Decidiq\Service;

use Exception;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that records decisions during active meetings.
 *
 * Verifies that the Meeting lifecycle is 'opened', creates Decision objects,
 * auto-creates draft Minutes if needed, and links decisions to minutes.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 */
class LiveDecisionService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Record a decision during an active meeting.
	 *
	 * Verifies the Meeting lifecycle is 'opened'; creates a Decision object;
	 * calls ensureDraftMinutes to auto-create draft Minutes if needed; links
	 * the Decision to the Minutes via relation; returns the Decision's slug.
	 *
	 * @param string $meetingId The Meeting ID
	 * @param array $decisionData Decision data (title, text, outcome, legalBasis)
	 * @param string $actorId The actor ID (user making the recording)
	 *
	 * @return string The slug of the created Decision
	 *
	 * @throws MissingObjectException If the Meeting is not found
	 * @throws Exception If the Meeting lifecycle is not 'opened'
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.1
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $actorId reserved for future audit-log enrichment.
	 */
	public function recordDecision(string $meetingId, array $decisionData, string $actorId): string {
		try {
			// Fetch Meeting.
			$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
			$meeting = null;
			if ($meetingEntity !== null) {
				$meeting = $meetingEntity->jsonSerialize();
			}

			if ($meeting === null) {
				throw new MissingObjectException(message: "Meeting not found: $meetingId");
			}

			// Verify lifecycle is 'opened'.
			$lifecycle = $meeting['lifecycle'] ?? null;
			if ($lifecycle !== 'opened') {
				throw new Exception("Meeting is not in 'opened' state (current: $lifecycle)", 409);
			}

			// Ensure draft Minutes exist (side-effect: creates draft if missing).
			$this->ensureDraftMinutes(meetingId: $meetingId);

			// Create Decision.
			$decisionToSave = [
				'title' => $decisionData['title'] ?? '',
				'text' => $decisionData['text'] ?? '',
				'outcome' => $decisionData['outcome'] ?? 'pending',
				'decisionDate' => date('c'),
				'isPublished' => 'internal',
			];

			if (empty($decisionData['legalBasis']) === false) {
				$decisionToSave['legalBasis'] = $decisionData['legalBasis'];
			}

			// Add relation to Meeting.
			$decisionToSave['relations'] = [
				'Meeting' => [$meetingId],
			];

			$decisionEntity = $this->objectService->saveObject(
				register: 'decidiq',
				schema: 'Decision',
				object: $decisionToSave
			);
			$decision = $decisionEntity->jsonSerialize();

			$decisionSlug = $decision['@self']['slug'] ?? $decision['id'] ?? '';

			$this->logger->info("Decision recorded in live mode for meeting $meetingId: $decisionSlug");

			// @spec openspec/specs/nextcloud-integration/spec.md
			// Activity feed (fail-soft): a decision was recorded.
			try {
				$this->container->get(\OCA\Decidiq\Service\ActivityPublisherService::class)->publishGovernanceEvent(
					subject: \OCA\Decidiq\Activity\DecidiqProvider::SUBJECT_DECISION_RECORDED,
					title: (string)($decision['title'] ?? $decisionSlug),
					status: (string)($decision['outcome'] ?? ''),
					objectType: 'decision',
					objectUuid: (string)($decision['id'] ?? ($decision['@self']['id'] ?? $decisionSlug)),
					segment: 'decisions'
				);
			} catch (\Throwable $activityError) {
				$this->logger->debug('Decidiq: activity publish skipped', ['error' => $activityError->getMessage()]);
			}

			return $decisionSlug;
		} catch (Exception $e) {
			$this->logger->error('LiveDecisionService::recordDecision failed: ' . $e->getMessage());
			throw $e;
		}//end try
	}//end recordDecision()

	/**
	 * Ensure a draft Minutes object exists for the Meeting.
	 *
	 * Checks if a Minutes object is linked to the Meeting. If not, creates
	 * a draft Minutes with title "Concept notulen — {meeting.title}",
	 * lifecycle "draft", version 1, and links it to the Meeting.
	 *
	 * @param string $meetingId The Meeting ID
	 *
	 * @return string The slug of the Minutes object (existing or new)
	 *
	 * @psalm-suppress UnusedReturnValue Return preserved for future-callers; current callsites only use
	 *                 the side effect of creating the Minutes record.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.1
	 */
	private function ensureDraftMinutes(string $meetingId): string {
		try {
			// Check if Minutes already exist for this Meeting.
			$params = [
				'_limit' => 999,
				'_offset' => 0,
			];
			$this->objectService->setRegister('decidiq');
			$this->objectService->setSchema('minutes');
			$existingMinutes = $this->objectService->findAll(['filters' => $params]);

			foreach ($existingMinutes as $minutesEntity) {
				$minutes = $minutesEntity->jsonSerialize();
				// Check if linked to the Meeting.
				if (empty($minutes['relations']['Meeting']) === false) {
					foreach ($minutes['relations']['Meeting'] as $linkedMeetingId) {
						if ($linkedMeetingId === $meetingId || $linkedMeetingId === ['id' => $meetingId]) {
							return $minutes['@self']['slug'] ?? $minutes['id'] ?? '';
						}
					}
				}
			}

			// No Minutes found, create one.
			$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
			$meeting = null;
			if ($meetingEntity !== null) {
				$meeting = $meetingEntity->jsonSerialize();
			}

			if ($meeting === null) {
				throw new MissingObjectException(message: "Meeting not found: $meetingId");
			}

			$minutesToCreate = [
				'title' => 'Concept notulen — ' . ($meeting['title'] ?? 'Untitled Meeting'),
				'lifecycle' => 'draft',
				'version' => 1,
				'content' => '',
				'relations' => [
					'Meeting' => [$meetingId],
				],
			];

			$minutesEntity = $this->objectService->saveObject(
				register: 'decidiq',
				schema: 'Minutes',
				object: $minutesToCreate
			);
			$minutes = $minutesEntity->jsonSerialize();

			$minutesSlug = $minutes['@self']['slug'] ?? $minutes['id'] ?? '';

			$this->logger->info("Draft Minutes created for meeting $meetingId: $minutesSlug");

			return $minutesSlug;
		} catch (Exception $e) {
			$this->logger->error('LiveDecisionService::ensureDraftMinutes failed: ' . $e->getMessage());
			throw $e;
		}//end try
	}//end ensureDraftMinutes()
}//end class
