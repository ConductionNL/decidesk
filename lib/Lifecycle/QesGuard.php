<?php

/**
 * QES Guard
 *
 * Refuses to advance a Resolution to `adopted` unless every required
 * signatory has produced a verified Qualified Electronic Signature (QES).
 * The guard composes {@see IEIDASSignatureService::validateCertificateChain}
 * with the persisted signedBy list on the linked BoardMinutes row.
 *
 * @category Lifecycle
 * @package  OCA\Decidiq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Lifecycle;

use OCA\Decidiq\Service\IEIDASSignatureService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Guard QES presence + validity before Resolution.conclude promotes the
 * resolution to `adopted`.
 *
 * Verification semantics:
 *  - The guard reads the linked BoardMinutes row for the resolution's
 *    parent meeting.
 *  - The `signedBy` array on the row is iterated; every signer's
 *    certificate-thumbprint is validated via {@see IEIDASSignatureService::validateCertificateChain}.
 *  - If any required signer is missing or has an invalid cert chain, the
 *    guard refuses with a structured `{allowed:false, reason:string}` payload.
 *
 * Soft-block semantics: if the configured adapter is the dormant
 * LogEIDASSignatureService, every chain validation returns `valid:false` with
 * the well-known "eIDAS QES integration is not configured." reason. Callers
 * (the decision conclude flow) treat the soft-block as an
 * `HTTP 422 Unprocessable` rather than a 500.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
class QesGuard {
	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Logger
	 * @param IEIDASSignatureService $signatureService eIDAS adapter
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IEIDASSignatureService $signatureService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Decide whether the resolution may transition to `adopted` based on
	 * eIDAS QES presence + validity. Returns a structured tuple so the caller
	 * can map success/failure to HTTP status without throwing.
	 *
	 * @param string $resolutionId UUID of the resolution
	 * @param array<int, string> $requiredSigners List of required member (Person) UUIDs
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array{allowed: bool, reason: string, missing: array<int,string>, invalid: array<int,string>}
	 */
	public function canConclude(string $resolutionId, array $requiredSigners): array {
		if ($resolutionId === '') {
			return $this->refuse(reason: 'resolutionId is required.', missing: [], invalid: []);
		}

		try {
			$signed = $this->loadSignedBy(resolutionId: $resolutionId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: QesGuard could not load signed-by list',
				['resolutionId' => $resolutionId, 'exception' => $e->getMessage()]
			);
			return $this->refuse(
				reason: 'Failed to inspect minutes for QES signatures.',
				missing: $requiredSigners,
				invalid: []
			);
		}

		$verified = $this->verifySignatures(signed: $signed);
		$invalid = $verified['invalid'];
		$missing = array_values(array_diff($requiredSigners, array_keys($verified['present'])));

		if ($missing !== [] || $invalid !== []) {
			return $this->refuse(
				reason: $this->buildReason(missing: $missing, invalid: $invalid),
				missing: $missing,
				invalid: $invalid
			);
		}

		return [
			'allowed' => true,
			'reason' => 'QES present and verified.',
			'missing' => [],
			'invalid' => [],
		];

	}//end canConclude()

	/**
	 * Verify every persisted signature entry against the eIDAS adapter.
	 *
	 * @param array<int, array<string, mixed>> $signed The persisted signedBy entries
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array{present: array<string, true>, invalid: array<int, string>}
	 */
	private function verifySignatures(array $signed): array {
		$invalid = [];
		$present = [];
		foreach ($signed as $entry) {
			$signer = (string)($entry['signerUuid'] ?? $entry['signer'] ?? '');
			$thumbprint = (string)($entry['certificateThumbprint'] ?? $entry['thumbprint'] ?? '');
			if ($signer === '' || $thumbprint === '') {
				continue;
			}

			$check = $this->signatureService->validateCertificateChain($thumbprint);
			if ($check['valid'] !== true) {
				$invalid[] = $signer;
				continue;
			}

			$present[$signer] = true;
		}

		return ['present' => $present, 'invalid' => $invalid];
	}//end verifySignatures()

	/**
	 * Build the structured refusal tuple the conclude flow maps to HTTP 422.
	 *
	 * @param string $reason Human-readable refusal reason
	 * @param array<int, string> $missing Member UUIDs without a QES
	 * @param array<int, string> $invalid Member UUIDs whose chain failed
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array{allowed: bool, reason: string, missing: array<int,string>, invalid: array<int,string>}
	 */
	private function refuse(string $reason, array $missing, array $invalid): array {
		return [
			'allowed' => false,
			'reason' => $reason,
			'missing' => $missing,
			'invalid' => $invalid,
		];

	}//end refuse()

	/**
	 * Load the `signedBy` array from the BoardMinutes row linked to the
	 * Resolution's parent meeting.
	 *
	 * @param string $resolutionId UUID of the resolution
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadSignedBy(string $resolutionId): array {
		$resolution = $this->objectService->find(
			id: $resolutionId,
			register: 'decidiq',
			schema: 'decision'
		);
		if ($resolution === null) {
			return [];
		}

		// `getObject(): array` is declared on ObjectEntityInterface, so the
		// method_exists() probe this replaces could never be false and the
		// jsonSerialize() default was unreachable.
		$resolutionRow = $resolution->getObject();

		$meetingId = (string)($resolutionRow['meetingIntegration'] ?? '');
		if ($meetingId === '') {
			return [];
		}

		$minutesRows = $this->objectService->findAll(
			[
				'register' => 'decidiq',
				'schema' => 'minutes',
				'filters' => ['meetingIntegration' => $meetingId],
				'limit' => 50,
			]
		);

		foreach ($minutesRows as $row) {
			$minutes = $this->toArray(row: $row);
			if (($minutes['meetingIntegration'] ?? null) !== $meetingId) {
				continue;
			}

			if (($minutes['version'] ?? '') === 'signed') {
				return array_values((array)($minutes['signedBy'] ?? []));
			}
		}

		return [];
	}//end loadSignedBy()

	/**
	 * Normalise an OpenRegister row (entity or array) to a plain array.
	 *
	 * @param mixed $row The ObjectService row
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array<string, mixed> The property map, empty when unusable
	 */
	private function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return [];
	}//end toArray()

	/**
	 * Build the soft-block reason string for the response tuple.
	 *
	 * @param array<int, string> $missing List of member (Person) UUIDs without a QES
	 * @param array<int, string> $invalid List of member (Person) UUIDs whose chain failed
	 *
	 * @return string
	 */
	private function buildReason(array $missing, array $invalid): string {
		$parts = [];
		if ($missing !== []) {
			$parts[] = 'missing QES signatures: ' . implode(', ', $missing);
		}

		if ($invalid !== []) {
			$parts[] = 'invalid QES certificate chain: ' . implode(', ', $invalid);
		}

		return 'Resolution cannot be adopted — ' . implode('; ', $parts) . '.';
	}//end buildReason()
}//end class
