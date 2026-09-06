<?php

/**
 * Decidiq Transcript Retention Background Job
 *
 * Daily job that enforces the per-body retention policy on meeting recordings
 * and raw transcripts after the meeting's minutes are approved.
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use DateTimeImmutable;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily retention sweep over `done` Transcripts whose meeting's minutes are
 * approved beyond the body's retention window.
 *
 * Policy resolution (per governance body): `keep` (no deletion),
 * `delete-recording` (remove the source recording only), `delete-both` (remove
 * the recording and the raw transcript file). Default `delete-both` / 30 days.
 * Each deletion is recorded in the meeting's audit trail and the Transcript's
 * retentionState is advanced (active → recording-deleted → purged). Pure
 * derivation over stored data; safe to re-run (idempotent on already-purged
 * transcripts).
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptRetentionJob extends TimedJob {

	/**
	 * Run interval: 24 hours.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Default retention window in days when the body has no explicit policy.
	 */
	private const DEFAULT_DAYS = 30;

	/**
	 * Default retention policy when the body has no explicit policy.
	 */
	private const DEFAULT_POLICY = 'delete-both';

	/**
	 * The retention policies a governance body may declare.
	 *
	 * @var string[]
	 */
	private const POLICIES = ['keep', 'delete-recording', 'delete-both'];

	/**
	 * Entity methods tried, in order, when normalising an OR entity to an array.
	 *
	 * @var string[]
	 */
	private const ENTITY_ARRAY_METHODS = ['jsonSerialize', 'getObject'];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time NC time factory (injected by TimedJob).
	 * @param ContainerInterface $container DI container (lazy OR services).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Execute the retention sweep.
	 *
	 * @param mixed $argument Unused; required by TimedJob.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by the
	 * abstract OCP\BackgroundJob\Job::run() signature; this job is scheduled with
	 * no argument, so the parameter cannot be removed.
	 */
	protected function run(mixed $argument): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq TranscriptRetentionJob: OpenRegister unavailable, skipping.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$now = new DateTimeImmutable();
		$transcripts = $this->fetchActiveDoneTranscripts(objectService: $objectService);

		foreach ($transcripts as $transcript) {
			try {
				$this->enforceForTranscript(objectService: $objectService, transcript: $transcript, now: $now);
			} catch (\Throwable $e) {
				$this->logger->error(
					'Decidiq TranscriptRetentionJob: enforcement failed for a transcript',
					['exception' => $e->getMessage()]
				);
			}
		}

	}//end run()

	/**
	 * Enforce the retention policy for one Transcript (pure, testable).
	 *
	 * Exposed (public) so it can be unit-tested with a mocked ObjectService and
	 * a fixed `now`. Resolves the meeting + body policy, checks the
	 * approval-age window, deletes files per policy, advances retentionState,
	 * and appends a meeting audit-trail entry on any deletion.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array<string,mixed> $transcript The Transcript object.
	 * @param DateTimeImmutable $now Current time (injectable for tests).
	 *
	 * @return string The resulting retention state.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function enforceForTranscript(object $objectService, array $transcript, DateTimeImmutable $now): string {
		$currentState = (string)($transcript['retentionState'] ?? 'active');
		if ($currentState === 'purged') {
			return 'purged';
		}

		$meetingId = $this->resolveMeetingId(transcript: $transcript);
		if ($meetingId === null) {
			return $currentState;
		}

		$meeting = $this->fetchObject(objectService: $objectService, id: $meetingId, schema: 'meeting');
		if ($meeting === null) {
			return $currentState;
		}

		$approvedAt = $this->resolveMinutesApprovedAt(objectService: $objectService, meetingId: $meetingId);
		if ($approvedAt === null) {
			// Minutes not approved yet — retention window has not started.
			return $currentState;
		}

		[$policy, $days] = $this->resolveBodyPolicy(objectService: $objectService, meeting: $meeting);
		if ($policy === 'keep') {
			return $currentState;
		}

		$threshold = $approvedAt->modify('+' . $days . ' days');
		if ($now < $threshold) {
			return $currentState;
		}

		[$transcript, $currentState, $deleted] = $this->applyDeletions(
			transcript: $transcript,
			state: $currentState,
			policy: $policy
		);

		if ($deleted === []) {
			return $currentState;
		}

		$transcript['retentionState'] = $currentState;
		$objectService->saveObject(
			object: $transcript,
			register: 'decidiq',
			schema: 'transcript',
			uuid: $this->objectId(object: $transcript)
		);

		$this->appendAudit(meetingId: $meetingId, deleted: $deleted, policy: $policy);

		return $currentState;
	}//end enforceForTranscript()

	/**
	 * Delete the files the policy calls for and advance the retention state.
	 *
	 * The source recording goes on both deleting policies but only while the
	 * transcript is still `active`; `delete-both` additionally removes the raw
	 * transcript text file and always ends in `purged` — a transcript with no
	 * remaining raw text is purged whether or not a file had to be removed.
	 *
	 * @param array<string,mixed> $transcript The Transcript object.
	 * @param string $state The current retention state.
	 * @param string $policy The resolved retention policy.
	 *
	 * @return array{0:array<string,mixed>,1:string,2:string[]} [transcript, state, deletedPaths].
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function applyDeletions(array $transcript, string $state, string $policy): array {
		$deleted = [];

		// Delete the source recording (both deleting policies).
		$recordingPath = (string)($transcript['sourceFilePath'] ?? '');
		if ($recordingPath !== '' && $state === 'active' && $this->deleteFile(path: $recordingPath) === true) {
			$deleted[] = $recordingPath;
			$transcript['sourceFilePath'] = '';
			$state = 'recording-deleted';
		}

		if ($policy !== 'delete-both') {
			return [$transcript, $state, $deleted];
		}

		// Delete-both also removes the raw transcript text file.
		$transcriptPath = (string)($transcript['transcriptFilePath'] ?? '');
		if ($transcriptPath !== '' && $this->deleteFile(path: $transcriptPath) === true) {
			$deleted[] = $transcriptPath;
			$transcript['transcriptFilePath'] = '';
		}

		return [$transcript, 'purged', $deleted];
	}//end applyDeletions()

	/**
	 * Resolve the per-body retention policy and window (days).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array<string,mixed> $meeting The meeting object.
	 *
	 * @return array{0:string,1:int} [policy, days].
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function resolveBodyPolicy(object $objectService, array $meeting): array {
		$bodyId = ($meeting['governanceBody'] ?? ($meeting['relations']['GovernanceBody'][0] ?? ($meeting['relations']['governanceBody'] ?? null)));
		if (is_array($bodyId) === true) {
			$bodyId = ($bodyId['id'] ?? ($bodyId[0] ?? null));
		}

		// An unresolvable body leaves $body null, which falls through to the
		// defaults below exactly as a body without an explicit policy does.
		$body = null;
		if ((string)($bodyId ?? '') !== '') {
			$body = $this->fetchObject(objectService: $objectService, id: (string)$bodyId, schema: 'governance-body');
		}

		$policy = (string)(($body['transcriptRetentionPolicy'] ?? null) ?? self::DEFAULT_POLICY);
		if (in_array($policy, self::POLICIES, true) === false) {
			$policy = self::DEFAULT_POLICY;
		}

		$days = (int)(($body['transcriptRetentionDays'] ?? null) ?? self::DEFAULT_DAYS);
		if ($days < 0) {
			$days = self::DEFAULT_DAYS;
		}

		return [$policy, $days];
	}//end resolveBodyPolicy()

	/**
	 * Resolve the approval timestamp of the meeting's approved minutes.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $meetingId Meeting UUID.
	 *
	 * @return DateTimeImmutable|null The approval time, or null when not approved.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function resolveMinutesApprovedAt(object $objectService, string $meetingId): ?DateTimeImmutable {
		$entities = $objectService->findAll(
			[
				'register' => 'decidiq',
				'schema' => 'minutes',
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'minutes',
					'_relations.meeting' => $meetingId,
				],
			]
		);

		foreach ($entities as $entity) {
			$minutes = $this->toArray(entity: $entity);
			$lifecycle = (string)($minutes['lifecycle'] ?? '');
			if (in_array($lifecycle, ['approved', 'signed', 'published'], true) === false) {
				continue;
			}

			$approvedAt = (string)($minutes['approvedAt'] ?? '');
			if ($approvedAt === '') {
				continue;
			}

			try {
				return new DateTimeImmutable($approvedAt);
			} catch (\Throwable) {
				continue;
			}
		}

		return null;
	}//end resolveMinutesApprovedAt()

	/**
	 * Fetch all active/recording-deleted `done` transcripts.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<int,array<string,mixed>> Transcript objects.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function fetchActiveDoneTranscripts(object $objectService): array {
		$entities = $objectService->findAll(
			[
				'register' => 'decidiq',
				'schema' => 'transcript',
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'transcript',
					'status' => 'done',
				],
			]
		);

		$result = [];
		foreach ($entities as $entity) {
			$result[] = $this->toArray(entity: $entity);
		}

		return $result;
	}//end fetchActiveDoneTranscripts()

	/**
	 * Delete a file by Files path via the OR FileService (fail-soft).
	 *
	 * @param string $path The file path.
	 *
	 * @return bool True when the file was deleted.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function deleteFile(string $path): bool {
		try {
			$fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
			$folderNode = $fileService->createFolder(dirname($path));
			$node = $folderNode->get(basename($path));
			$node->delete();
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq TranscriptRetentionJob: file delete failed',
				['path' => $path, 'error' => $e->getMessage()]
			);
			return false;
		}

	}//end deleteFile()

	/**
	 * Append a retention deletion entry to the meeting's audit trail (fail-soft).
	 *
	 * @param string $meetingId The meeting UUID.
	 * @param string[] $deleted Paths deleted.
	 * @param string $policy The applied policy.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function appendAudit(string $meetingId, array $deleted, string $policy): void {
		try {
			$auditLog = $this->container->get(\OCA\Decidiq\Service\AuditLogService::class);
			// 'transcript-retention-purge' is a member of AuditLogService::ACTIONS;
			// the dotted 'transcript.retention.purge' this used to send never was,
			// so every retention purge audit append failed with "Unknown action".
			$auditLog->append(
				'system:retention',
				'transcript-retention-purge',
				[$meetingId],
				['policy' => $policy, 'deletedFiles' => $deleted]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq TranscriptRetentionJob: audit append failed',
				['meetingId' => $meetingId, 'error' => $e->getMessage()]
			);
		}

	}//end appendAudit()

	/**
	 * Fetch a single object as an array (or null).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $id Object UUID.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string,mixed>|null The object data.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function fetchObject(object $objectService, string $id, string $schema): ?array {
		try {
			$entity = $objectService->find(id: $id, register: 'decidiq', schema: $schema);
		} catch (\Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(entity: $entity);
	}//end fetchObject()

	/**
	 * Normalise an OR entity (object or array) to an array.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return array<string,mixed> The object data.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function toArray(mixed $entity): array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === false) {
			return [];
		}

		foreach (self::ENTITY_ARRAY_METHODS as $method) {
			if (method_exists($entity, $method) === true) {
				return (array)$entity->$method();
			}
		}

		return [];
	}//end toArray()

	/**
	 * Resolve the linked meeting UUID from a Transcript object.
	 *
	 * @param array<string,mixed> $transcript The Transcript object.
	 *
	 * @return string|null The meeting UUID.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function resolveMeetingId(array $transcript): ?string {
		$relation = ($transcript['relations']['meeting'] ?? ($transcript['meeting'] ?? null));
		if (is_array($relation) === true) {
			$relation = ($relation['id'] ?? ($relation[0] ?? null));
		}

		$meetingId = (string)($relation ?? '');
		if ($meetingId === '') {
			return null;
		}

		return $meetingId;
	}//end resolveMeetingId()

	/**
	 * Extract an object UUID (id or @self.id).
	 *
	 * @param array<string,mixed> $object The object.
	 *
	 * @return string|null The UUID.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function objectId(array $object): ?string {
		$id = (string)(($object['id'] ?? ($object['@self']['id'] ?? null)) ?? '');
		if ($id === '') {
			return null;
		}

		return $id;
	}//end objectId()
}//end class
