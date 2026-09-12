<?php

/**
 * Decidiq eIDAS Signature Service
 *
 * Delegates QES (Qualified Electronic Signature) work to the openconnector
 * `e-sign` Source. Openconnector configures the QSP credentials, signing
 * profile and EU Trusted List access; this service merely composes calls
 * via openconnector's CallService.
 *
 * The service is constructed via the DI container with a lazy openconnector
 * lookup. If openconnector is absent or the e-sign Source is not configured,
 * the Application wires the dormant {@see LogEIDASSignatureService} fallback
 * instead — the controller / guard never see a hard 500.
 *
 * Retargeted onto the unified `minutes` / `decision` entities (ADR-006).
 * C5 (decision-methods) wires the "signature" decision method: when eIDAS
 * signing completes via {@see self::finalizeMinutes()}, the service locates
 * the related DecisionStage of method=signature and resolves it (sets
 * outcome=adopted + decidedAt + links the signedDocument). See
 * {@see self::resolveSignatureStage()} for the stage-resolution seam (C5 D5).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Concrete eIDAS QES service that delegates to openconnector's e-sign source.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 * @spec openspec/changes/decision-methods/tasks.md#4-eidas-signature-method-wiring-code
 */
class EIDASSignatureService implements IEIDASSignatureService {

	/**
	 * The openconnector Source slug that addresses the configured QSP.
	 *
	 * @var string
	 */
	public const ESIGN_SOURCE_SLUG = 'eidas-qes';

	/**
	 * Docudesk integration slug for the signing registry source (contract #2).
	 * When a source with this slug is registered in the integration registry,
	 * the docudesk e-signature path takes precedence over openconnector (REQ-DCDH-005).
	 *
	 * @var string
	 */
	public const DOCUDESK_SOURCE_SLUG = 'docudesk-signing';

	/**
	 * Construct the eIDAS service.
	 *
	 * @param ContainerInterface $container DI container (lazy openconnector lookup)
	 * @param LoggerInterface $logger Logger
	 * @param AuditLogService $auditLogService Audit log dependency
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly AuditLogService $auditLogService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $minutesId UUID of the BoardMinutes record
	 * @param array<string> $signatories Ordered list of member (Person) UUIDs
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array{success: bool, requestId: ?string, signingUrl: ?string, message: string}
	 */
	public function initializeSigningRequest(string $minutesId, array $signatories): array {
		if ($minutesId === '' || $signatories === []) {
			return [
				'success' => false,
				'requestId' => null,
				'signingUrl' => null,
				'message' => 'minutesId and at least one signatory are required.',
			];
		}

		// Contract #2: prefer docudesk for document e-signature when available (REQ-DCDH-005).
		$docudeskResult = $this->composeDocudeskSigningRequest(
			minutesId: $minutesId,
			signatories: $signatories
		);
		if ($docudeskResult['success'] === true) {
			return $docudeskResult;
		}

		// Fallback to openconnector e-sign Source (REQ-DCDH-005).
		$payload = [
			'minutesId' => $minutesId,
			'signatories' => array_values(array_map('strval', $signatories)),
			'profile' => 'eIDAS-QES',
			'returnTarget' => 'decidiq/board-portal/minutes/' . $minutesId,
		];

		try {
			$response = $this->invokeOpenconnector(
				action: 'initiate',
				payload: $payload
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: eIDAS initiate failed',
				['minutesId' => $minutesId, 'exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'requestId' => null,
				'signingUrl' => null,
				'message' => 'Failed to initialize signing request: ' . $e->getMessage(),
			];
		}

		$requestId = (string)($response['requestId'] ?? '');
		$signingUrl = (string)($response['signingUrl'] ?? '');

		$this->auditLogService->append(
			actor: 'system',
			action: 'signature',
			objectUids: [$minutesId, $requestId],
			payload: ['phase' => 'initiate', 'signatories' => array_values($signatories)]
		);

		return [
			'success' => true,
			'requestId' => $this->nullIfEmpty(value: $requestId),
			'signingUrl' => $this->nullIfEmpty(value: $signingUrl),
			'message' => 'Signing request initiated.',
		];

	}//end initializeSigningRequest()

	/**
	 * Normalise an optional string field: '' becomes null, everything else is
	 * returned unchanged. Used for every nullable field in the response shapes.
	 *
	 * @param string $value The raw field value
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return string|null
	 */
	private function nullIfEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfEmpty()

	/**
	 * Read an ObjectService entity as a plain array, preferring getObject()
	 * when the entity exposes it.
	 *
	 * @param object $entity Entity returned by ObjectService
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array<string, mixed>
	 */
	private function toObjectArray(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			return (array)$entity->getObject();
		}

		return (array)$entity->jsonSerialize();
	}//end toObjectArray()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $requestId UUID of the signing request
	 * @param string $signature Base-64 encoded signature blob
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array{valid: bool, certificateThumbprint: ?string, timestamp: ?string, message: string}
	 */
	public function verifySignature(string $requestId, string $signature): array {
		if ($requestId === '' || $signature === '') {
			return [
				'valid' => false,
				'certificateThumbprint' => null,
				'timestamp' => null,
				'message' => 'requestId and signature are required.',
			];
		}

		try {
			$response = $this->invokeOpenconnector(
				action: 'verify',
				payload: [
					'requestId' => $requestId,
					'signature' => $signature,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: eIDAS verifySignature failed',
				['requestId' => $requestId, 'exception' => $e->getMessage()]
			);
			return [
				'valid' => false,
				'certificateThumbprint' => null,
				'timestamp' => null,
				'message' => 'Failed to verify signature: ' . $e->getMessage(),
			];
		}

		$valid = (bool)($response['valid'] ?? false);
		$thumbprint = (string)($response['certificateThumbprint'] ?? '');
		$timestamp = (string)($response['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z'));

		$messageOut = 'Signature rejected.';
		if ($valid === true) {
			$messageOut = 'Signature verified.';
		}

		return [
			'valid' => $valid,
			'certificateThumbprint' => $this->nullIfEmpty(value: $thumbprint),
			'timestamp' => $timestamp,
			'message' => $messageOut,
		];

	}//end verifySignature()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $minutesId UUID of the BoardMinutes record
	 * @param array<int, array<string>> $signatureList List of {signer, signature, timestamp} tuples
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array{success: bool, pdfArchiveReference: ?string, hashSha256: ?string, message: string}
	 */
	public function finalizeMinutes(string $minutesId, array $signatureList): array {
		if ($minutesId === '' || $signatureList === []) {
			return [
				'success' => false,
				'pdfArchiveReference' => null,
				'hashSha256' => null,
				'message' => 'minutesId and at least one signature are required.',
			];
		}

		try {
			$response = $this->invokeOpenconnector(
				action: 'finalize',
				payload: [
					'minutesId' => $minutesId,
					'signatures' => array_values($signatureList),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: eIDAS finalize failed',
				['minutesId' => $minutesId, 'exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'pdfArchiveReference' => null,
				'hashSha256' => null,
				'message' => 'Failed to finalize minutes: ' . $e->getMessage(),
			];
		}

		$archiveReference = (string)($response['pdfArchiveReference'] ?? '');
		$hash = (string)($response['hashSha256'] ?? '');

		// Persist the archive reference + hash + signed payload on the Minutes row.
		$this->updateMinutesRow(
			minutesId: $minutesId,
			patch: [
				'pdfArchiveReference' => $archiveReference,
				'hashSha256' => $hash,
				'signingCompletionDate' => gmdate('Y-m-d'),
				'eidasSignatureLevel' => 'QES',
				'version' => 'signed',
				'signedBy' => array_values($signatureList),
			]
		);

		// C5 (decision-methods D5): resolve the method=signature DecisionStage
		// that is linked to these minutes, if one exists.
		$this->resolveSignatureStage(minutesId: $minutesId);

		$this->auditLogService->append(
			actor: 'system',
			action: 'signature',
			objectUids: [$minutesId],
			payload: [
				'phase' => 'finalize',
				'pdfArchiveReference' => $archiveReference,
				'hashSha256' => $hash,
				'signatures' => count($signatureList),
			]
		);

		return [
			'success' => true,
			'pdfArchiveReference' => $this->nullIfEmpty(value: $archiveReference),
			'hashSha256' => $this->nullIfEmpty(value: $hash),
			'message' => 'Minutes finalized.',
		];

	}//end finalizeMinutes()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $certThumbprint SHA-256 thumbprint of the cert
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array{valid: bool, issuer: ?string, trustListLevel: ?string, message: string}
	 */
	public function validateCertificateChain(string $certThumbprint): array {
		if ($certThumbprint === '') {
			return [
				'valid' => false,
				'issuer' => null,
				'trustListLevel' => null,
				'message' => 'certificateThumbprint is required.',
			];
		}

		try {
			$response = $this->invokeOpenconnector(
				action: 'validate-cert',
				payload: ['certificateThumbprint' => $certThumbprint]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: eIDAS validateCertificateChain failed',
				['certificateThumbprint' => $certThumbprint, 'exception' => $e->getMessage()]
			);
			return [
				'valid' => false,
				'issuer' => null,
				'trustListLevel' => null,
				'message' => 'Failed to validate certificate: ' . $e->getMessage(),
			];
		}

		$valid = (bool)($response['valid'] ?? false);
		$issuer = (string)($response['issuer'] ?? '');
		$level = (string)($response['trustListLevel'] ?? '');

		$validateMessage = 'Certificate not on EU Trusted List.';
		if ($valid === true) {
			$validateMessage = 'Certificate chain valid.';
		}

		return [
			'valid' => $valid,
			'issuer' => $this->nullIfEmpty(value: $issuer),
			'trustListLevel' => $this->nullIfEmpty(value: $level),
			'message' => $validateMessage,
		];

	}//end validateCertificateChain()

	/**
	 * Invoke the openconnector e-sign source via the CallService. The
	 * openconnector Source slug is fixed (see ::ESIGN_SOURCE_SLUG); the action
	 * is sent as a relative path the Source's mapper resolves into a concrete
	 * API call.
	 *
	 * @param string $action One of initiate|verify|finalize|validate-cert
	 * @param array<string, mixed> $payload Action-specific payload
	 *
	 * @return array<string, mixed>
	 */
	private function invokeOpenconnector(string $action, array $payload): array {
		// Resolve openconnector's CallService lazily. If the app is absent
		// or the binding is missing, throw — the DI factory uses the
		// LogEIDASSignatureService fallback when openconnector is unwired.
		// Resolved across every namespace integriq has shipped under, still
		// throwing when nothing resolves so the DI factory keeps falling back to
		// LogEIDASSignatureService rather than proceeding against nothing.
		$callService = FleetAppId::getService($this->container, 'integriq', 'Service\CallService')
			?? throw new RuntimeException('Integriq CallService is not available under any known namespace.');
		$sourceMapper = FleetAppId::getService($this->container, 'integriq', 'Db\SourceMapper')
			?? throw new RuntimeException('Integriq SourceMapper is not available under any known namespace.');

		$source = $sourceMapper->findBySlug(slug: self::ESIGN_SOURCE_SLUG);
		if ($source === null) {
			throw new RuntimeException("Openconnector source '" . self::ESIGN_SOURCE_SLUG . "' is not configured.");
		}

		$response = $callService->call(
			source: $source,
			endpoint: '/' . $action,
			method: 'POST',
			config: [
				'body' => json_encode($payload),
				'headers' => ['Content-Type' => 'application/json'],
			]
		);

		$body = '';
		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$raw = $response->getResponse();
			$body = (string)($raw['body'] ?? '');
		}

		$decoded = null;
		if ($body !== '') {
			$decoded = json_decode($body, true);
		}

		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end invokeOpenconnector()

	/**
	 * Resolve the DecisionStage of method=signature that is linked (via the
	 * Minutes → Meeting → Decision chain or directly via signedDocument) to the
	 * finalised minutes. When found, links the DigitalDocument (signedDocument),
	 * sets outcome=adopted, and stamps decidedAt (C5 D5 / design decision-methods
	 * #4).
	 *
	 * The lookup strategy: search for a DecisionStage whose signedDocument UUID
	 * matches the minutesId being finalised (treating the Minutes record itself as
	 * the DigitalDocument proxy here, since signedDocument is the sealed artefact).
	 * If openconnector is absent or the stage is not found, the method degrades
	 * silently (warning log) — the signing artefact is still persisted.
	 *
	 * @param string $minutesId UUID of the finalised Minutes record
	 * @param string|null $signingReference Optional signing reference (docudesk signingRequest id) to store on the stage
	 *
	 * @spec openspec/changes/decision-methods/tasks.md#4-eidas-signature-method-wiring-code
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-3
	 *
	 * @return void
	 */
	public function resolveSignatureStage(string $minutesId, ?string $signingReference = null): void {
		try {
			// Find any DecisionStage with method=signature whose signedDocument
			// points at this minutes record. Seeds and UI will wire the correct
			// DigitalDocument UUID; the service resolves the stage it finds.
			// ObjectService::findAll() takes a single $config array — the
			// named-argument form (register:/schema:/filters:) threw "Unknown
			// named parameter" and was swallowed by the catch below, so the
			// signature stage was never resolved. Register/schema are read from
			// inside `filters`.
			$results = $this->objectService->findAll(
				[
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'decision-stage',
						'method' => 'signature',
						'signedDocument' => $minutesId,
					],
				]
			);

			if (empty($results) === true) {
				return;
			}

			$decidedAt = gmdate('Y-m-d\TH:i:s\Z');

			foreach ($results as $stage) {
				$current = $this->toObjectArray(entity: $stage);

				$stageId = (string)($current['id'] ?? ($current['uuid'] ?? ''));
				if ($stageId === '') {
					continue;
				}

				$patch = [
					'outcome' => 'adopted',
					'decidedAt' => $decidedAt,
					'status' => 'decided',
				];
				if ($signingReference !== null) {
					$patch['signingReference'] = $signingReference;
				}

				$this->objectService->saveObject(
					object: array_merge($current, $patch),
					register: 'decidiq',
					schema: 'decision-stage',
					uuid: $stageId
				);

				$this->auditLogService->append(
					actor: 'system',
					action: 'signature',
					objectUids: [$minutesId, $stageId],
					payload: [
						'phase' => 'resolve-signature-stage',
						'stageId' => $stageId,
						'decidedAt' => $decidedAt,
					]
				);
			}//end foreach
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: failed to resolve method=signature DecisionStage after finalizeMinutes',
				['minutesId' => $minutesId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end resolveSignatureStage()

	/**
	 * Compose a docudesk signingRequest via the ADR-019 integration registry
	 * (cross-app contract #2 / REQ-DCDH-005). Returns the same shape as
	 * initializeSigningRequest. Returns success=false silently when docudesk
	 * is absent (allows the openconnector fallback to proceed).
	 *
	 * The method is fail-closed: when docudesk is registered but returns an
	 * error, we propagate the error and do NOT fall through to openconnector —
	 * the document must not be silently "signed" by a lower-trust path.
	 *
	 * @param string $minutesId UUID of the Minutes / document record
	 * @param array<string> $signatories Ordered list of Person UUIDs
	 *
	 * @return array{success: bool, requestId: ?string, signingUrl: ?string, message: string}
	 */
	private function composeDocudeskSigningRequest(string $minutesId, array $signatories): array {
		try {
			$sourceMapper = FleetAppId::getService($this->container, 'integriq', 'Db\SourceMapper')
				?? throw new RuntimeException('Integriq SourceMapper is not available under any known namespace.');
			$source = $sourceMapper->findBySlug(slug: self::DOCUDESK_SOURCE_SLUG);
		} catch (\Throwable) {
			// Openconnector absent or source not configured — docudesk unavailable.
			return ['success' => false, 'requestId' => null, 'signingUrl' => null, 'message' => 'Docudesk source not configured.'];
		}

		if ($source === null) {
			// Docudesk not registered — fall through to openconnector silently.
			return ['success' => false, 'requestId' => null, 'signingUrl' => null, 'message' => 'Docudesk source not registered.'];
		}

		// Docudesk IS registered — compose the signingRequest (fail-closed from here).
		$payload = [
			'documentId' => $minutesId,
			'signatories' => array_values(array_map('strval', $signatories)),
			'signingLevel' => 'QES',
			'returnTarget' => 'decidiq/minutes/' . $minutesId,
		];

		try {
			$callService = FleetAppId::getService($this->container, 'integriq', 'Service\CallService')
				?? throw new RuntimeException('Integriq CallService is not available under any known namespace.');
			$response = $callService->call(
				source: $source,
				endpoint: '/signing-requests',
				method: 'POST',
				config: [
					'body' => json_encode($payload),
					'headers' => ['Content-Type' => 'application/json'],
				]
			);

			$decoded = $this->decodeDocudeskResponse(response: $response);

			$requestId = (string)($decoded['id'] ?? ($decoded['signingRequestId'] ?? ''));
			$signingUrl = (string)($decoded['signingUrl'] ?? '');

			$this->auditLogService->append(
				actor: 'system',
				action: 'signature',
				objectUids: [$minutesId, $requestId],
				payload: ['phase' => 'docudesk-initiate', 'signatories' => array_values($signatories)]
			);

			return [
				'success' => true,
				'requestId' => $this->nullIfEmpty(value: $requestId),
				'signingUrl' => $this->nullIfEmpty(value: $signingUrl),
				'message' => 'Signing request composed via docudesk.',
			];
		} catch (\Throwable $e) {
			// Docudesk was registered but failed — fail CLOSED (REQ-DCDH-005).
			$this->logger->error(
				'Decidiq: docudesk signingRequest composition failed (fail-closed)',
				['minutesId' => $minutesId, 'exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'requestId' => null,
				'signingUrl' => null,
				'message' => 'Docudesk signing failed (fail-closed): ' . $e->getMessage(),
			];
		}//end try

	}//end composeDocudeskSigningRequest()

	/**
	 * Decode a docudesk CallService response body into an array.
	 *
	 * Fail-closed: throws when the body is absent or not valid JSON, so the
	 * caller never treats an unparseable answer as a successful signature.
	 *
	 * @param mixed $response The raw CallService response object
	 *
	 * @throws RuntimeException When the response carries no decodable JSON body.
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array<string, mixed> The decoded response body
	 */
	private function decodeDocudeskResponse(mixed $response): array {
		$body = '';
		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$raw = $response->getResponse();
			$body = (string)($raw['body'] ?? '');
		}

		$decoded = null;
		if ($body !== '') {
			$decoded = json_decode($body, true);
		}

		if (is_array($decoded) === false) {
			throw new RuntimeException('Docudesk returned non-JSON response.');
		}

		return $decoded;
	}//end decodeDocudeskResponse()

	/**
	 * Persist a partial update on a Minutes row. Wrapped in a try/catch so
	 * a failed write degrades the response to a warning without leaking a 500.
	 *
	 * @param string $minutesId Minutes UUID
	 * @param array<string, mixed> $patch Fields to merge
	 *
	 * @return void
	 */
	private function updateMinutesRow(string $minutesId, array $patch): void {
		try {
			$entity = $this->objectService->find(
				id: $minutesId,
				register: 'decidiq',
				schema: 'minutes'
			);
			if ($entity === null) {
				return;
			}

			$this->objectService->saveObject(
				object: array_merge($this->toObjectArray(entity: $entity), $patch),
				register: 'decidiq',
				schema: 'minutes',
				uuid: $minutesId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: failed to persist signed Minutes row',
				['minutesId' => $minutesId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end updateMinutesRow()
}//end class
