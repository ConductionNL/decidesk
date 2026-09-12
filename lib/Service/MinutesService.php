<?php

/**
 * Decidiq Minutes Service
 *
 * Service for Minutes-specific operations including approval notifications.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Log\LoggerInterface;

/**
 * Stateless service for Minutes operations.
 *
 * Handles approval notifications and other minutes-specific workflows.
 *
 * OpenRegister lookups are delegated to MinutesContextResolver and notification
 * delivery to ParticipantNotifier, so what remains here is the approval rule
 * itself: which roles are asked to approve, and what they are told.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
 */
class MinutesService {

	/**
	 * The governance roles asked to approve minutes.
	 *
	 * @var array<int,string>
	 */
	private const APPROVER_ROLES = [
		'chair',
		'secretary',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param MinutesContextResolver $context Resolves Minutes/Meeting/Participant context
	 * @param ParticipantNotifier $notifier Delivers notifications to participants
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
	 */
	public function __construct(
		private LoggerInterface $logger,
		private MinutesContextResolver $context,
		private ParticipantNotifier $notifier,
	) {
	}//end __construct()

	/**
	 * Send approval notifications when Minutes are submitted for approval.
	 *
	 * Resolves the linked GovernanceBody, fetches chair and secretary Memberships,
	 * and sends Nextcloud notifications to each.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return int The count of notifications sent
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
	 */
	public function notifyApproversOnSubmit(string $minutesId): int {
		try {
			$minutes = $this->context->findMinutes(minutesId: $minutesId);
			if ($minutes === null) {
				$this->logger->warning("Minutes not found: $minutesId");
				return 0;
			}

			// A Minutes record with no resolvable GovernanceBody has no approver
			// roll to notify — that is a no-op, not a failure.
			$bodyId = $this->context->governanceBodyIdForMinutes(minutes: $minutes);
			if ($bodyId === null) {
				$this->logger->info("No GovernanceBody linked to Minutes $minutesId");
				return 0;
			}

			return $this->notifier->notifyAll(
				participants: $this->context->participantsByRole(roles: self::APPROVER_ROLES),
				title: 'Notulen ter goedkeuring: ' . ($minutes['title'] ?? 'Untitled'),
				message: 'De notulen zijn ter goedkeuring ingediend.',
				deepLink: "/minutes/$minutesId"
			);
		} catch (\Exception $e) {
			$this->logger->error('MinutesService::notifyApproversOnSubmit failed: ' . $e->getMessage());
			return 0;
		}//end try

	}//end notifyApproversOnSubmit()
}//end class
