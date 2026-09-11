<?php

/**
 * Decidiq Participant Notifier
 *
 * Sends a Nextcloud notification to a set of Decidiq participants, resolving
 * each participant's Nextcloud UID first.
 *
 * A participant record is a Decidiq object, not a Nextcloud account: its UUID
 * is NOT a valid userId. Every notification path therefore has to resolve a UID
 * before it can notify anybody, and every path that did so carried its own
 * copy of the resolution ladder. This is that ladder, once.
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
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Exception;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves participant Nextcloud UIDs and delivers notifications to them.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
 */
class ParticipantNotifier {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy-loads OpenRegister services)
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Notify every participant whose Nextcloud UID can be resolved.
	 *
	 * Participants whose UID cannot be resolved are logged and skipped; a
	 * delivery failure for one recipient never aborts the rest.
	 *
	 * @param array<int,array<string,mixed>> $participants The participant records
	 * @param string $title The notification title
	 * @param string $message The notification body
	 * @param string $deepLink The in-app deep link
	 *
	 * @return int The count of notifications actually sent
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	public function notifyAll(array $participants, string $title, string $message, string $deepLink): int {
		if (count($participants) === 0) {
			return 0;
		}

		$notificationService = $this->container->get('OpenRegisterNotificationService');

		$sentCount = 0;
		foreach ($participants as $participant) {
			$ncUid = $this->resolveUid(participant: $participant);
			if ($ncUid === null) {
				$displayName = ($participant['displayName'] ?? '?');
				$this->logger->warning(
					'Decidiq: cannot resolve Nextcloud UID for participant',
					['participant' => $displayName]
				);
				continue;
			}

			$sent = $this->send(
				notificationService: $notificationService,
				userId: $ncUid,
				title: $title,
				message: $message,
				deepLink: $deepLink
			);

			if ($sent === true) {
				$sentCount++;
			}
		}//end foreach

		return $sentCount;
	}//end notifyAll()

	/**
	 * Deliver one notification, swallowing per-recipient delivery failures.
	 *
	 * @param object $notificationService The OpenRegister notification service
	 * @param string $userId The Nextcloud UID
	 * @param string $title The notification title
	 * @param string $message The notification body
	 * @param string $deepLink The in-app deep link
	 *
	 * @return bool True when the notification was accepted
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	private function send(
		object $notificationService,
		string $userId,
		string $title,
		string $message,
		string $deepLink,
	): bool {
		try {
			$notificationService->sendNotification(
				userId: $userId,
				title: $title,
				message: $message,
				deepLink: $deepLink
			);
			return true;
		} catch (Exception $e) {
			$this->logger->warning("Failed to send notification to $userId: " . $e->getMessage());
			return false;
		}

	}//end send()

	/**
	 * Resolve a participant's Nextcloud UID.
	 *
	 * Tries, in order: the stored nextcloudUserId, an email lookup, and finally
	 * a display-name search. Returns null when none of them resolves.
	 *
	 * @param array<string,mixed> $participant The participant record
	 *
	 * @return string|null The Nextcloud UID, or null when unresolvable
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	private function resolveUid(array $participant): ?string {
		$ncUid = ($participant['nextcloudUserId'] ?? null);
		if (empty($ncUid) === false) {
			return (string)$ncUid;
		}

		$userManager = $this->container->get(\OCP\IUserManager::class);
		if (($userManager instanceof \OCP\IUserManager) === false) {
			return null;
		}

		$email = ($participant['email'] ?? null);
		if (empty($email) === false) {
			$users = $userManager->getByEmail(email: (string)$email);
			if (empty($users) === false) {
				return array_values($users)[0]->getUID();
			}
		}

		$displayName = ($participant['displayName'] ?? null);
		if (empty($displayName) === false) {
			$users = $userManager->searchDisplayName(pattern: (string)$displayName, limit: 1);
			if (empty($users) === false) {
				return array_values($users)[0]->getUID();
			}
		}

		return null;
	}//end resolveUid()
}//end class
