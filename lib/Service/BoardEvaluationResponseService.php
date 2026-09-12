<?php

/**
 * Decidiq Board Evaluation Response Service
 *
 * Collects anonymous board-self-evaluation responses by reusing the existing
 * secret-ballot anonymity mechanism (VotingService's HMAC voter-token
 * pattern) instead of inventing a second anonymity model. The response
 * content never carries a member relation or Nextcloud user id; completion
 * (who has/has not responded) is tracked separately on the BoardEvaluation
 * object's roster fields.
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
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\AppInfo\Application;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Anonymous response collection + completion tracking for a BoardEvaluation.
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
 */
class BoardEvaluationResponseService {

	/**
	 * App-config key holding the HMAC secret. Deliberately the SAME key
	 * VotingService uses for secret-ballot voter tokens (`voterTokenSecret()`)
	 * — this reuses the existing secret-ballot anonymity infrastructure
	 * rather than deriving a second, parallel one.
	 */
	private const TOKEN_SECRET_CONFIG_KEY = 'voter_token_secret';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration (shared HMAC secret)
	 * @param LoggerInterface $logger Diagnostic logger
	 * @param ParticipantUuidLookup $participants Nextcloud UID -> Participant UUID resolution
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ParticipantUuidLookup $participants,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve which Participant the logged-in user is FOR THIS EVALUATION.
	 *
	 * The identity a response is checked against is per-body, not per-person: a
	 * member who serves on two boards has two Participant objects, and an
	 * unscoped UID lookup returns whichever one the store lists first. Feeding
	 * that into submitResponse() compares an identity from one body against the
	 * invited roster of another, so the roster check rejects a genuinely
	 * invited member and the cycle's completion count never moves — with no
	 * error the member can act on, because the rejection is a legitimate-looking
	 * "not invited to this cycle".
	 *
	 * Scope to the evaluation's own governance body. Only when the evaluation
	 * carries no body at all does the unscoped lookup stand in; the roster check
	 * in submitResponse() remains the gate either way.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 * @param string $nextcloudUid The logged-in Nextcloud UID
	 *
	 * @return string|null The responding participant's UUID, or null when the
	 *                     user is not a participant of the evaluation's body
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function resolveResponder(string $evaluationId, string $nextcloudUid): ?string {
		if ($evaluationId === '' || $nextcloudUid === '') {
			return null;
		}

		$governanceBodyId = '';
		try {
			$entity = $this->objectService()->find(
				id: $evaluationId,
				register: 'decidiq',
				schema: 'board-evaluation'
			);
			if ($entity !== null) {
				$evaluation = $entity->jsonSerialize();
				$relations = (array)($evaluation['@self']['relations'] ?? []);
				$governanceBodyId = (string)($evaluation['governanceBody'] ?? ($relations['governanceBody'] ?? ''));
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: resolving the evaluation governance body failed',
				['evaluationId' => $evaluationId, 'error' => $e->getMessage()]
			);
		}

		if ($governanceBodyId === '') {
			return $this->participants->forNextcloudUser(nextcloudUid: $nextcloudUid);
		}

		return $this->participants->forNextcloudUserInBody(
			nextcloudUid: $nextcloudUid,
			governanceBodyId: $governanceBodyId
		);

	}//end resolveResponder()

	/**
	 * Submit (or idempotently re-submit) one member's anonymous response.
	 *
	 * Enforces: the evaluation must be `open`; the participant must be on the
	 * invited roster; exactly one response per invited member (idempotent
	 * upsert keyed on the opaque HMAC token, mirroring VotingService's
	 * secret-round dedup). The response content never stores the
	 * participant id — only the opaque `responseToken`. Completion is
	 * recorded on the BoardEvaluation roster fields, never on the response.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 * @param string $participantId UUID of the responding participant (never persisted on the response)
	 * @param array<int, array<string,mixed>> $answers Each with questionId, dimension, and optionally likertValue and freeText.
	 *
	 * @return array<string, mixed> Keys: success (bool), and optionally message (string)
	 *                              and response (array).
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function submitResponse(string $evaluationId, string $participantId, array $answers): array {
		if ($evaluationId === '' || $participantId === '') {
			return ['success' => false, 'message' => 'An evaluation id and participant id are required.'];
		}

		if (empty($answers) === true) {
			return ['success' => false, 'message' => 'At least one answer is required.'];
		}

		try {
			$objectService = $this->objectService();
			$entity = $objectService->find(id: $evaluationId, register: 'decidiq', schema: 'board-evaluation');
			if ($entity === null) {
				return ['success' => false, 'message' => "BoardEvaluation {$evaluationId} not found."];
			}

			$evaluation = $entity->jsonSerialize();
			if ((string)($evaluation['lifecycle'] ?? '') !== 'open') {
				return ['success' => false, 'message' => 'This evaluation is not open for responses.'];
			}

			$invited = (array)($evaluation['invitedParticipantIds'] ?? []);
			if (in_array($participantId, $invited, true) === false) {
				return ['success' => false, 'message' => 'This participant is not invited to this evaluation cycle.'];
			}

			$responseToken = $this->responseToken(participantId: $participantId, evaluationId: $evaluationId);

			$sanitisedAnswers = $this->sanitiseAnswers(answers: $answers);

			$objectService->setRegister('decidiq');
			$objectService->setSchema('evaluation-response');

			// Idempotent upsert keyed on the opaque token (never the participant
			// id) — a resubmission overwrites rather than creating a second row,
			// mirroring VotingService's secret-round vote dedup.
			$response = [
				'@self' => ['slug' => $responseToken],
				'answers' => $sanitisedAnswers,
				'submittedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'responseToken' => $responseToken,
				'relations' => [
					['register' => 'decidiq', 'schema' => 'board-evaluation', 'id' => $evaluationId],
				],
			];

			$saved = $objectService->saveObject(register: 'decidiq', schema: 'evaluation-response', object: $response);

			// Completion tracking lives on the BoardEvaluation roster, entirely
			// separate from the response content saved above.
			$this->recordCompletion(evaluation: $evaluation, evaluationId: $evaluationId, participantId: $participantId);

			return ['success' => true, 'response' => $this->normaliseSaved(saved: $saved, fallback: $response)];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: submitting board evaluation response failed',
				['evaluationId' => $evaluationId, 'error' => $e->getMessage()]
			);
			return ['success' => false, 'message' => 'Submitting the response failed: ' . $e->getMessage()];
		}//end try

	}//end submitResponse()

	/**
	 * Reduce raw submitted answers to the persisted answer shape.
	 *
	 * Every answer is normalised to the same four keys so the stored response
	 * never carries caller-supplied extras (which could re-identify the member);
	 * an absent or null Likert value / free text stays null.
	 *
	 * @param array<int, array<string,mixed>> $answers Raw submitted answers
	 *
	 * @return array<int, array<string,mixed>> Sanitised answers
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	private function sanitiseAnswers(array $answers): array {
		$sanitised = [];
		foreach ($answers as $answer) {
			$likertValue = ($answer['likertValue'] ?? null);
			if ($likertValue !== null) {
				$likertValue = (int)$likertValue;
			}

			$freeText = ($answer['freeText'] ?? null);
			if ($freeText !== null) {
				$freeText = (string)$freeText;
			}

			// OpenRegister declares `answers.items.likertValue` as `integer` and
			// `answers.items.freeText` as `string`, neither of them nullable, and its
			// validator rejects an explicit null for a typed property outright — it
			// does NOT treat null as "absent". Writing the key with a null value
			// therefore failed the WHOLE saveObject with
			// `Property 'answers.0.freeText' should be type 'string' but is 'null'`,
			// which this service catches and returns as a 422. Every likert answer
			// carries a null freeText, so no likert response could EVER be stored:
			// `recordCompletion()` was never reached and `respondedCount` stayed 0
			// while the UI showed no error (the NoteCard renders outside the card the
			// count lives in). Omit the key instead — an absent optional property is
			// what the schema expects, and it is the fleet-standing rule for OR.
			$entry = [
				'questionId' => (string)($answer['questionId'] ?? ''),
				'dimension' => (string)($answer['dimension'] ?? ''),
			];

			if ($likertValue !== null) {
				$entry['likertValue'] = $likertValue;
			}

			if ($freeText !== null) {
				$entry['freeText'] = $freeText;
			}

			$sanitised[] = $entry;
		}//end foreach

		return $sanitised;
	}//end sanitiseAnswers()

	/**
	 * Non-responders remaining on the roster (for reminder flows) — computed
	 * purely from the roster fields, never from response content.
	 *
	 * @param array<string, mixed> $evaluation The BoardEvaluation payload
	 *
	 * @return string[] Participant UUIDs invited but not yet responded
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function nonResponders(array $evaluation): array {
		$invited = (array)($evaluation['invitedParticipantIds'] ?? []);
		$responded = (array)($evaluation['respondedParticipantIds'] ?? []);

		return array_values(array_diff($invited, $responded));
	}//end nonResponders()

	/**
	 * Record that a participant has responded on the BoardEvaluation's
	 * completion roster (idempotent — adding twice is a no-op).
	 *
	 * @param array<string, mixed> $evaluation The BoardEvaluation payload (pre-fetch)
	 * @param string $evaluationId UUID of the BoardEvaluation
	 * @param string $participantId UUID of the responding participant
	 *
	 * @return void
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	private function recordCompletion(array $evaluation, string $evaluationId, string $participantId): void {
		$objectService = $this->objectService();

		// Re-fetch to reduce (not eliminate) a lost-update race between the
		// response write above and this roster update.
		$entity = $objectService->find(id: $evaluationId, register: 'decidiq', schema: 'board-evaluation');
		if ($entity !== null) {
			$evaluation = $entity->jsonSerialize();
		}

		$responded = (array)($evaluation['respondedParticipantIds'] ?? []);
		if (in_array($participantId, $responded, true) === false) {
			$responded[] = $participantId;
		}

		$evaluation['respondedParticipantIds'] = array_values($responded);
		$evaluation['respondedCount'] = count($responded);

		$objectService->saveObject(register: 'decidiq', schema: 'board-evaluation', object: $evaluation);

	}//end recordCompletion()

	/**
	 * Compute the opaque dedup/anonymity token for a (participant, evaluation)
	 * pair. HMAC-SHA256 over the shared secret-ballot secret — the mapping
	 * cannot be inverted without that server-side-only secret.
	 *
	 * @param string $participantId UUID of the participant
	 * @param string $evaluationId UUID of the BoardEvaluation
	 *
	 * @return string 64-character hex token
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	private function responseToken(string $participantId, string $evaluationId): string {
		return hash_hmac('sha256', $participantId . ':' . $evaluationId, $this->tokenSecret());
	}//end responseToken()

	/**
	 * Return the shared per-app HMAC secret used for secret-ballot voter
	 * tokens (VotingService::voterTokenSecret()) and, here, evaluation
	 * response tokens — the SAME secret, not a new one, so this reuses the
	 * existing anonymity infrastructure rather than deriving a second model.
	 *
	 * @return string 64-character hex secret
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	private function tokenSecret(): string {
		$secret = $this->appConfig->getValueString(Application::APP_ID, self::TOKEN_SECRET_CONFIG_KEY, '');
		if ($secret === '') {
			$secret = bin2hex(random_bytes(32));
			$this->appConfig->setValueString(Application::APP_ID, self::TOKEN_SECRET_CONFIG_KEY, $secret);
		}

		return $secret;
	}//end tokenSecret()

	/**
	 * Normalise a saved ObjectEntity (or array) to an array.
	 *
	 * @param mixed $saved The saveObject() return value
	 * @param array<string, mixed> $fallback The original payload
	 *
	 * @return array<string, mixed>
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
	 * Lazy-load the OpenRegister ObjectService from the container.
	 *
	 * @return object The ObjectService instance
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
