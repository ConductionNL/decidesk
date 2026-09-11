<?php

/**
 * Decidiq Minutes Workflow Service
 *
 * The action-item extraction and approval-submission steps of the minutes
 * workflow.
 *
 * These three operations used to run inline in MinutesController, which meant
 * the controller reached into OpenRegister directly and re-implemented its own
 * lifecycle checks. They live here so the controller is left with routing and
 * authorisation only, per ADR-022.
 *
 * Domain refusals are signalled with the HTTP status the caller should report
 * in the exception code, so the endpoint never has to restate the rule.
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
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * Action-item extraction and approval submission for Minutes records.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
 */
class MinutesWorkflowService {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param ActionItemExtractionService $extractionService Extracts and persists action items
	 * @param MinutesService $minutesService Sends the approval notifications
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ActionItemExtractionService $extractionService,
		private readonly MinutesService $minutesService,
	) {
	}//end __construct()

	/**
	 * Extract action item candidates from the minutes content.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array<int,array<string,mixed>> The extracted candidates
	 *
	 * @throws MissingObjectException When the Minutes record does not exist
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 */
	public function extractActionItems(string $minutesId): array {
		$minutes = $this->requireMinutes(minutesId: $minutesId);

		return $this->extractionService->extractFromContent(content: ($minutes['content'] ?? ''));
	}//end extractActionItems()

	/**
	 * Persist the action items a user confirmed.
	 *
	 * @param string $minutesId The Minutes ID
	 * @param array<int,mixed> $confirmed The confirmed candidates
	 *
	 * @return int The count of action items saved
	 *
	 * @throws MissingObjectException When the Minutes record does not exist
	 * @throws RuntimeException When the Minutes have already been published (HTTP 400)
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 */
	public function saveExtractedActionItems(string $minutesId, array $confirmed): int {
		$minutes = $this->requireMinutes(minutesId: $minutesId);

		if (($minutes['lifecycle'] ?? null) === 'published') {
			throw new RuntimeException('Cannot save action items for published minutes.', 400);
		}

		return $this->extractionService->saveExtracted(
			minutesId: $minutesId,
			confirmed: $confirmed
		);

	}//end saveExtractedActionItems()

	/**
	 * Move draft Minutes into review and notify the approvers.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array{lifecycle:string,notified:int} The new lifecycle and notification count
	 *
	 * @throws MissingObjectException When the Minutes record does not exist
	 * @throws RuntimeException When the Minutes are not in draft (HTTP 409)
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
	 */
	public function submitForApproval(string $minutesId): array {
		$minutes = $this->requireMinutes(minutesId: $minutesId);

		if (($minutes['lifecycle'] ?? null) !== 'draft') {
			throw new RuntimeException('Minutes must be in draft state to submit for approval.', 409);
		}

		$minutes['lifecycle'] = 'review';
		$this->objectService->saveObject(
			register: 'decidiq',
			schema: 'minutes',
			object: $minutes
		);

		$notified = $this->minutesService->notifyApproversOnSubmit(minutesId: $minutesId);

		return [
			'lifecycle' => 'review',
			'notified' => $notified,
		];

	}//end submitForApproval()

	/**
	 * Fetch a Minutes record or fail with a 404-shaped exception.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array<string,mixed> The Minutes data
	 *
	 * @throws MissingObjectException When the Minutes record does not exist
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
	 */
	private function requireMinutes(string $minutesId): array {
		$entity = $this->objectService->find(id: $minutesId, register: 'decidiq', schema: 'minutes');
		if ($entity === null) {
			throw new MissingObjectException(message: 'Minutes not found.');
		}

		return $entity->jsonSerialize();
	}//end requireMinutes()
}//end class
