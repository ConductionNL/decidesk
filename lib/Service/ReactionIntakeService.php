<?php

/**
 * Decidiq Reaction Intake Service
 *
 * Reaction submission + moderation for citizen-participation consultations.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service handling ConsultationReaction intake and moderation.
 *
 * Intake matrix (auth posture x policy):
 * - Authenticated + pre-moderation  -> pending
 * - Authenticated + post-moderation -> approved (auto)
 * - Anonymous (only when enabled)   -> ALWAYS pending (pre-moderation), pseudonymous submitterId
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ReactionIntakeService {

	/**
	 * Maximum reaction body length in bytes (server-side payload cap).
	 */
	public const MAX_BODY_BYTES = 5000;

	/**
	 * Constructor for ReactionIntakeService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param IAppConfig $appConfig App config (pseudonym secret)
	 * @param ParticipationLifecycleService $lifecycleService Deadline/status guards
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $appConfig,
		private readonly ParticipationLifecycleService $lifecycleService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object The ObjectService instance.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Normalise a saved ObjectEntity (or array) to an array.
	 *
	 * @param mixed $saved The saveObject() return value.
	 * @param array<string, mixed> $fallback The original payload.
	 *
	 * @return array<string, mixed> The persisted object as an array.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function normaliseSaved(mixed $saved, array $fallback): array {
		if ($saved instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
			return $saved->jsonSerialize();
		}

		if (is_array($saved) === true) {
			return $saved;
		}

		return $fallback;
	}//end normaliseSaved()

	/**
	 * Submit a reaction on a consultation.
	 *
	 * Enforces: consultation open + deadline (server-side, independent of
	 * stored status), payload cap, anonymous gate (per-consultation
	 * anonymousReactionsAllowed), and moderation defaulting. Anonymous
	 * submissions receive a pseudonymous submitterId and are ALWAYS pending;
	 * authenticated submissions auto-approve only under post-moderation.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $body The reaction text.
	 * @param string|null $ncUid The authenticated Nextcloud UID, or null for anonymous.
	 * @param string|null $clientSeed A client fingerprint (e.g. remote addr) for the anon pseudonym; never stored raw.
	 *
	 * @return array<string, mixed> The created ConsultationReaction object.
	 *
	 * @throws RuntimeException When the consultation is not found.
	 * @throws ParticipationWindowClosedException When the consultation is closed or past its deadline.
	 * @throws InvalidArgumentException When the body is empty/oversized or anonymous intake is not enabled.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function submitReaction(string $consultationId, string $body, ?string $ncUid, ?string $clientSeed = null): array {
		$body = trim($body);
		if ($body === '') {
			throw new InvalidArgumentException('Reaction body must not be empty');
		}

		if (strlen($body) > self::MAX_BODY_BYTES) {
			throw new InvalidArgumentException(
				sprintf('Reaction body exceeds the maximum of %d bytes', self::MAX_BODY_BYTES)
			);
		}

		$objectService = $this->objectService();
		$entity = $objectService->find(id: $consultationId, register: 'decidiq', schema: 'public-consultation');
		if ($entity === null) {
			throw new RuntimeException("PublicConsultation {$consultationId} not found");
		}

		$consultation = $entity->jsonSerialize();

		// Server-side window guard (open + future deadline), independent of stored status.
		if ($this->lifecycleService->consultationAcceptsSubmissions(consultation: $consultation) === false) {
			throw new ParticipationWindowClosedException('This consultation is not open for submissions');
		}

		$isAnonymous = ($ncUid === null || $ncUid === '');

		// Anonymous intake is allowed only when staff enabled it on this consultation.
		if ($isAnonymous === true && ($consultation['anonymousReactionsAllowed'] ?? false) !== true) {
			throw new InvalidArgumentException('Anonymous reactions are not enabled for this consultation');
		}

		$policy = (string)($consultation['moderationPolicy'] ?? 'pre-moderation');

		$submitterId = $this->resolveSubmitterId(
			consultationId: $consultationId,
			ncUid: $ncUid,
			isAnonymous: $isAnonymous,
			clientSeed: $clientSeed
		);
		$moderationStatus = $this->resolveModerationStatus(isAnonymous: $isAnonymous, policy: $policy);

		$reaction = [
			'body' => $body,
			'moderationStatus' => $moderationStatus,
			'submitterId' => $submitterId,
			'submittedAt' => (new DateTimeImmutable())->format(\DateTimeInterface::ATOM),
			'relations' => [
				['register' => 'decidiq', 'schema' => 'public-consultation', 'id' => $consultationId],
			],
		];

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'consultation-reaction', object: $reaction);
		$result = $this->normaliseSaved(saved: $saved, fallback: $reaction);

		return $result;
	}//end submitReaction()

	/**
	 * Approve a pending reaction (staff action).
	 *
	 * Sets moderationStatus to 'approved' so the reaction surfaces in the
	 * consultation's reactions relation and becomes eligible for publication.
	 *
	 * @param string $reactionId The reaction UUID.
	 * @param string|null $reason Optional moderation note.
	 *
	 * @return array<string, mixed> The updated reaction object.
	 *
	 * @throws RuntimeException When the reaction is not found.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function approveReaction(string $reactionId, ?string $reason = null): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $reactionId, register: 'decidiq', schema: 'consultation-reaction');
		if ($entity === null) {
			throw new RuntimeException("ConsultationReaction {$reactionId} not found");
		}

		$reaction = $entity->jsonSerialize();

		$reaction['moderationStatus'] = 'approved';
		if ($reason !== null && $reason !== '') {
			$reaction['moderationReason'] = $reason;
		}

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'consultation-reaction', object: $reaction);

		// Increment the parent consultation's submissionCount when a reaction is
		// approved (pre-moderation: reactions start as pending and only count
		// toward the public total once a moderator approves them).
		$this->incrementConsultationSubmissionCount(reaction: $reaction, objectService: $objectService);

		return $this->normaliseSaved(saved: $saved, fallback: $reaction);
	}//end approveReaction()

	/**
	 * Increment the parent consultation's submissionCount by 1.
	 *
	 * Resolves the consultation id from the reaction's relations array,
	 * fetches the consultation, bumps its submissionCount, and saves it.
	 * Failures are swallowed (log-only) so a missing relation never breaks
	 * the moderation flow.
	 *
	 * @param array<string,mixed> $reaction The approved reaction.
	 * @param \OCA\OpenRegister\Contract\ObjectServiceInterface $objectService The OR object service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function incrementConsultationSubmissionCount(array $reaction, mixed $objectService): void {
		$relations = $reaction['relations'] ?? [];
		$consultationId = null;
		foreach ((array)$relations as $rel) {
			if (is_array($rel) === true && (($rel['schema'] ?? '') === 'public-consultation') && isset($rel['id']) === true) {
				$consultationId = (string)$rel['id'];
				break;
			}
		}

		if ($consultationId === null) {
			return;
		}

		try {
			$consultationEntity = $objectService->find(id: $consultationId, register: 'decidiq', schema: 'public-consultation');
			if ($consultationEntity === null) {
				return;
			}

			$consultation = $consultationEntity->jsonSerialize();
			$consultation['submissionCount'] = ((int)($consultation['submissionCount'] ?? 0)) + 1;
			$objectService->saveObject(register: 'decidiq', schema: 'public-consultation', object: $consultation);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ReactionIntakeService: could not increment consultation submissionCount: ' . $e->getMessage()
			);
		}//end try

	}//end incrementConsultationSubmissionCount()

	/**
	 * Reject a pending reaction (staff action).
	 *
	 * Sets moderationStatus to 'rejected' with a reason. The object is retained
	 * (soft-delete conventions) for audit and never counts toward
	 * submissionCount.
	 *
	 * @param string $reactionId The reaction UUID.
	 * @param string $reason The rejection reason.
	 *
	 * @return array<string, mixed> The updated reaction object.
	 *
	 * @throws RuntimeException When the reaction is not found.
	 * @throws InvalidArgumentException When no reason is given.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function rejectReaction(string $reactionId, string $reason): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new InvalidArgumentException('A rejection reason is required');
		}

		$objectService = $this->objectService();
		$entity = $objectService->find(id: $reactionId, register: 'decidiq', schema: 'consultation-reaction');
		if ($entity === null) {
			throw new RuntimeException("ConsultationReaction {$reactionId} not found");
		}

		$reaction = $entity->jsonSerialize();
		$reaction['moderationStatus'] = 'rejected';
		$reaction['moderationReason'] = $reason;

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'consultation-reaction', object: $reaction);

		return $this->normaliseSaved(saved: $saved, fallback: $reaction);
	}//end rejectReaction()

	/**
	 * Resolve the submitter id recorded on a reaction.
	 *
	 * A named submitter is stored by Nextcloud UID; an anonymous one is
	 * reduced to a stable pseudonymous token that reveals no PII.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string|null $ncUid The Nextcloud UID of a named submitter.
	 * @param bool $isAnonymous Whether the reaction is anonymous.
	 * @param string|null $clientSeed Client fingerprint for the anonymous token.
	 *
	 * @return string|null The submitter id to persist.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function resolveSubmitterId(
		string $consultationId,
		?string $ncUid,
		bool $isAnonymous,
		?string $clientSeed,
	): ?string {
		if ($isAnonymous === false) {
			return $ncUid;
		}

		return $this->pseudonymousId(consultationId: $consultationId, seed: (string)($clientSeed ?? ''));
	}//end resolveSubmitterId()

	/**
	 * Decide the moderation status a new reaction starts in.
	 *
	 * Anonymous reactions are ALWAYS pre-moderated regardless of the
	 * consultation's policy; only a named submitter under post-moderation is
	 * auto-approved.
	 *
	 * @param bool $isAnonymous Whether the reaction is anonymous.
	 * @param string $policy The consultation's moderation policy.
	 *
	 * @return string Either 'approved' or 'pending'.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function resolveModerationStatus(bool $isAnonymous, string $policy): string {
		if ($isAnonymous === false && $policy === 'post-moderation') {
			return 'approved';
		}

		return 'pending';
	}//end resolveModerationStatus()

	/**
	 * Build a stable, opaque pseudonymous submitter id for an anonymous reaction.
	 *
	 * HMAC(secret, consultationId:clientSeed) so the same anonymous client on
	 * the same consultation maps to one stable token (enabling per-client
	 * dedup/rate context) while the token reveals no PII and cannot be reversed
	 * without the server-side secret.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $seed A client fingerprint (never stored raw).
	 *
	 * @return string A 'anon-' prefixed hex token.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function pseudonymousId(string $consultationId, string $seed): string {
		$secret = $this->appConfig->getValueString(Application::APP_ID, 'participation_pseudonym_secret', '');
		if ($secret === '') {
			$secret = bin2hex(random_bytes(32));
			$this->appConfig->setValueString(Application::APP_ID, 'participation_pseudonym_secret', $secret);
		}

		return 'anon-' . hash_hmac('sha256', $consultationId . ':' . $seed, $secret);
	}//end pseudonymousId()
}//end class
