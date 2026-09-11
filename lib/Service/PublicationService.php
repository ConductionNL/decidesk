<?php

/**
 * Decidiq Publication Service
 *
 * Orchestrates the public-publication flow: eligibility, payload construction,
 * setting the OpenRegister published-predicate (publicationDate), OpenCatalogi
 * routing, PublicationRecord lifecycle, and the source-object audit trail.
 * Covers publish, withdraw, and rectify.
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
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Stateful orchestration of a publication action.
 *
 * Anonymous read of published data happens EXCLUSIVELY through OpenRegister's
 * RBAC published-predicate surface and OpenCatalogi — never through an app-local
 * route. "Publish" means setting `publicationDate` on the derived payload object
 * via the normal OR object API; the PublicationPayload schema's
 * `authorization.read` rule then grants the public group read access while
 * `publicationDate <= $now` (and no `depublicationDate` in the past). When
 * OpenCatalogi is absent the catalog step is skipped and the service degrades
 * gracefully with a staff-visible warning. It shares OriPublicationService's
 * graceful-degrade posture.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationService {

	/**
	 * OpenRegister persistence gateway for the publication flow.
	 *
	 * @var PublicationRepository
	 */
	private readonly PublicationRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger, handed to the repository.
	 * @param IAppManager $appManager Detects OpenCatalogi presence.
	 * @param PublicationEligibilityService $eligibility Eligibility + deny-list gates.
	 * @param PublicationPayloadService $payloadService Allow-list payload builder.
	 * @param PublicationConfigService $configService Per-body publication config.
	 * @param OpenCatalogiPublisher $catalogPublisher OpenCatalogi catalog routing.
	 * @param AuditLogService $auditLogService Immutable audit trail.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service, handed to the repository.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		LoggerInterface $logger,
		private readonly IAppManager $appManager,
		private readonly PublicationEligibilityService $eligibility,
		private readonly PublicationPayloadService $payloadService,
		private readonly PublicationConfigService $configService,
		private readonly OpenCatalogiPublisher $catalogPublisher,
		private readonly AuditLogService $auditLogService,
		ObjectServiceInterface $objectService,
	) {
		$this->repository = new PublicationRepository(logger: $logger, objectService: $objectService);

	}//end __construct()

	/**
	 * Publish an eligible governance object.
	 *
	 * Runs the deny-list + eligibility gates, builds the allow-list payload,
	 * persists it as an immutable PublicationPayload, sets `publicationDate` so
	 * the public-group RBAC rule makes it anonymously readable, routes into the
	 * configured OpenCatalogi catalog, writes the PublicationRecord, and appends
	 * a publish audit entry.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 * @param string $sourceId UUID of the source object.
	 * @param string $actorId Nextcloud UID of the publishing staff member.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed> Keys: `record` (the PublicationRecord) and `warnings` (string[]).
	 */
	public function publish(string $sourceType, string $sourceId, string $actorId): array {
		$source = $this->eligibility->assertEligible($sourceType, $sourceId);
		$bodyId = $this->resolveBodyId(source: $source);

		$version = 1;
		$payload = $this->payloadService->build($sourceType, $source, $bodyId, $version);

		// Set publicationDate on the payload so OR's public-group RBAC rule
		// (publicationDate <= $now) makes it anonymously readable through the
		// published-predicate surface. This is a normal field on a register-owned
		// object — written on the standard saveObject path, not a magic predicate.
		$publishedAt = $this->now();

		$payload['publicationDate'] = $publishedAt;
		$payload['depublicationDate'] = null;
		$payloadId = $this->repository->persistPayload(payload: $payload);

		$warnings = [];

		// OpenCatalogi routing (create publication in the per-body target catalog).
		$catalogRef = '';
		$targetCatalog = '';
		if ($bodyId !== null) {
			$targetCatalog = $this->configService->getForBody($bodyId)['catalog'];
		}

		$catalogAvailable = ($this->isOpenCatalogiAvailable() === true && $targetCatalog !== '');
		if ($catalogAvailable === false) {
			$warnings[] = 'opencatalogi-absent';
		}

		if ($catalogAvailable === true) {
			$catalogRef = $this->catalogPublisher->publish($targetCatalog, $payloadId, $payload);
			if ($catalogRef === '') {
				$warnings[] = 'catalog-publish-failed';
			}
		}

		$record = [
			'sourceType' => $sourceType,
			'sourceObject' => $sourceId,
			'sourceTitle' => (string)($source['title'] ?? ''),
			'governanceBody' => ($bodyId ?? ''),
			'payloadObject' => $payloadId,
			'payloadVersion' => $version,
			'oriType' => (string)$payload['oriType'],
			'catalogPublication' => $catalogRef,
			'targetCatalog' => $targetCatalog,
			'status' => 'published',
			'catalogRetractionStatus' => 'none',
			'publishedBy' => $actorId,
			'publishedAt' => $publishedAt,
		];
		$recordId = $this->repository->persistRecord(record: $record);
		$record['id'] = $recordId;

		$this->repository->markSourcePublished(
			sourceType: $sourceType,
			sourceId: $sourceId,
			source: $source,
			publishedAt: $publishedAt
		);
		$this->audit(
			actorId: $actorId,
			action: 'publish',
			objectUids: [$sourceId, $payloadId],
			payload: ['sourceType' => $sourceType, 'payloadVersion' => $version]
		);

		return [
			'record' => $record,
			'warnings' => $warnings,
		];

	}//end publish()

	/**
	 * Withdraw a publication with a mandatory reason.
	 *
	 * Sets `depublicationDate` on the payload (removing it from the public-group
	 * RBAC surface), retracts the OpenCatalogi publication (surfacing + marking
	 * pending on failure — never a silent success), resets the source object's
	 * published state, records actor/reason/timestamp on the record and in the
	 * audit trail, and soft-retains the payload.
	 *
	 * @param string $recordId UUID of the PublicationRecord.
	 * @param string $actorId Nextcloud UID of the withdrawing staff member.
	 * @param string $reason Mandatory withdraw reason.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws InvalidArgumentException When the reason is empty.
	 * @throws MissingObjectException When the record does not exist.
	 *
	 * @return array<string,mixed> Keys: `record` (the PublicationRecord) and `warnings` (string[]).
	 */
	public function withdraw(string $recordId, string $actorId, string $reason): array {
		if (trim($reason) === '') {
			throw new InvalidArgumentException('A withdraw reason is required.');
		}

		$record = $this->repository->loadRecord(recordId: $recordId);

		$warnings = [];

		// Set depublicationDate on the payload first so the public-group RBAC
		// rule stops returning it (publicationDate <= $now is no longer the only
		// gate once depublicationDate is in the past) — the data stops being
		// anonymously readable even if the remote retraction fails.
		$this->repository->setDepublicationDate(
			payloadId: (string)$record['payloadObject'],
			timestamp: $this->now()
		);

		$retractionStatus = 'none';
		$catalogRef = (string)($record['catalogPublication'] ?? '');
		if ($catalogRef !== '') {
			$retracted = $this->catalogPublisher->retract($catalogRef);
			$retractionStatus = 'done';
			if ($retracted !== true) {
				// Surface the failure and mark pending — never report success.
				$retractionStatus = 'pending';
				$warnings[] = 'catalog-retraction-failed';
			}
		}

		$record['status'] = 'withdrawn';
		$record['withdrawnBy'] = $actorId;
		$record['withdrawnAt'] = $this->now();
		$record['withdrawReason'] = $reason;
		$record['catalogRetractionStatus'] = $retractionStatus;
		$this->repository->persistRecord(record: $record, uuid: $recordId);

		$this->repository->markSourcePublished(
			sourceType: (string)$record['sourceType'],
			sourceId: (string)$record['sourceObject'],
			source: null,
			publishedAt: null
		);
		$this->audit(
			actorId: $actorId,
			action: 'withdraw',
			objectUids: [(string)$record['sourceObject'], (string)$record['payloadObject']],
			payload: ['reason' => $reason]
		);

		return [
			'record' => $record,
			'warnings' => $warnings,
		];

	}//end withdraw()

	/**
	 * Rectify a publication: publish a corrected new version and withdraw the
	 * old one in the same operation. Published payloads are never edited in place.
	 *
	 * @param string $recordId UUID of the PublicationRecord to rectify.
	 * @param string $actorId Nextcloud UID of the rectifying staff member.
	 * @param string $reason Reason recorded against the withdrawn prior version.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws MissingObjectException When the prior record does not exist.
	 *
	 * @return array<string,mixed> Keys: `record` (new), `previous` (withdrawn) and `warnings` (string[]).
	 */
	public function rectify(string $recordId, string $actorId, string $reason): array {
		$prior = $this->repository->loadRecord(recordId: $recordId);
		$sourceType = (string)$prior['sourceType'];
		$sourceId = (string)$prior['sourceObject'];
		$newVersion = ((int)($prior['payloadVersion'] ?? 1) + 1);

		// Re-validate eligibility for the corrected source state.
		$source = $this->eligibility->assertEligible($sourceType, $sourceId);
		$bodyId = $this->resolveBodyId(source: $source);

		$publishedAt = $this->now();

		$payload = $this->payloadService->build($sourceType, $source, $bodyId, $newVersion);
		$payload['publicationDate'] = $publishedAt;
		$payload['depublicationDate'] = null;
		$payloadId = $this->repository->persistPayload(payload: $payload);

		$warnings = [];

		$catalogRef = '';
		$targetCatalog = (string)($prior['targetCatalog'] ?? '');
		$catalogAvailable = ($this->isOpenCatalogiAvailable() === true && $targetCatalog !== '');
		if ($catalogAvailable === false) {
			$warnings[] = 'opencatalogi-absent';
		}

		if ($catalogAvailable === true) {
			$catalogRef = $this->catalogPublisher->publish($targetCatalog, $payloadId, $payload);
			if ($catalogRef === '') {
				$warnings[] = 'catalog-publish-failed';
			}
		}

		$newRecord = [
			'sourceType' => $sourceType,
			'sourceObject' => $sourceId,
			'sourceTitle' => (string)($source['title'] ?? ($prior['sourceTitle'] ?? '')),
			'governanceBody' => ($bodyId ?? (string)($prior['governanceBody'] ?? '')),
			'payloadObject' => $payloadId,
			'payloadVersion' => $newVersion,
			'oriType' => (string)$payload['oriType'],
			'catalogPublication' => $catalogRef,
			'targetCatalog' => $targetCatalog,
			'status' => 'published',
			'catalogRetractionStatus' => 'none',
			'rectifiesVersion' => (int)($prior['payloadVersion'] ?? 1),
			'publishedBy' => $actorId,
			'publishedAt' => $publishedAt,
		];
		$newRecordId = $this->repository->persistRecord(record: $newRecord);
		$newRecord['id'] = $newRecordId;

		// Withdraw the prior version in the same operation.
		$withdrawReason = $reason;
		if (trim($withdrawReason) === '') {
			$withdrawReason = 'Superseded by rectified version ' . $newVersion;
		}

		$withdrawResult = $this->withdraw(recordId: $recordId, actorId: $actorId, reason: $withdrawReason);
		$warnings = array_values(array_unique(array_merge($warnings, $withdrawResult['warnings'])));

		$this->repository->markSourcePublished(
			sourceType: $sourceType,
			sourceId: $sourceId,
			source: $source,
			publishedAt: $publishedAt
		);
		$rectifyDetail = [
			'rectifiesVersion' => (int)($prior['payloadVersion'] ?? 1),
			'newVersion' => $newVersion,
		];
		$this->audit(actorId: $actorId, action: 'rectify', objectUids: [$sourceId, $payloadId], payload: $rectifyDetail);

		return [
			'record' => $newRecord,
			'previous' => $withdrawResult['record'],
			'warnings' => $warnings,
		];

	}//end rectify()

	/**
	 * Append an entry to the immutable decision audit trail.
	 *
	 * @param string $actorId Nextcloud UID of the actor.
	 * @param string $action 'publish'|'withdraw'|'rectify'.
	 * @param string[] $objectUids Touched object UUIDs.
	 * @param array $payload Structured detail.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	private function audit(string $actorId, string $action, array $objectUids, array $payload): void {
		// The hash-chained audit trail enumerates its accepted actions; map the
		// publication actions onto the generic 'decision-transition' action and
		// carry the specific verb in the structured payload.
		$this->auditLogService->append(
			actor: $actorId,
			action: 'decision-transition',
			objectUids: $objectUids,
			payload: array_merge(['publicationAction' => $action], $payload),
		);

	}//end audit()

	/**
	 * Resolve the governance body UUID for a source object.
	 *
	 * @param array<string,mixed> $source The source object data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string|null
	 */
	private function resolveBodyId(array $source): ?string {
		$direct = ($source['governanceBody'] ?? ($source['relations']['GovernanceBody'] ?? $source['relations']['governanceBody'] ?? null));
		if (is_array($direct) === true) {
			$direct = ($direct[0] ?? ($direct['id'] ?? null));
		}

		if (is_string($direct) === true && $direct !== '') {
			return $direct;
		}

		return null;
	}//end resolveBodyId()

	/**
	 * Determine whether OpenCatalogi is installed.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return bool
	 */
	private function isOpenCatalogiAvailable(): bool {
		return $this->appManager->isEnabledForAnyone('opencatalogi');
	}//end isOpenCatalogiAvailable()

	/**
	 * Current UTC timestamp in ATOM format.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
	}//end now()
}//end class
