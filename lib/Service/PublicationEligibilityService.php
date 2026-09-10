<?php

/**
 * Decidiq Publication Eligibility Service
 *
 * Single home for the public-publication structural deny-list and the
 * server-side gates governing which governance objects may be published to the
 * public surface. Board-governance entities, votes/rounds, conflict
 * declarations and audit logs are never publishable; recordings and raw
 * transcripts of a governance meeting are confidential to the body's members
 * and are NEVER eligible for public publication — the approved Minutes are the
 * only public record.
 *
 * This file is the single deny-list home shared by two changes:
 *   - meeting-transcription-ai-minutes seeds the schema/file deny-list with
 *     `Transcript` and recording files (its task 2.7) via DENIED_SCHEMAS /
 *     DENIED_FILE_MARKERS / DENIED_FILE_EXTENSIONS and the dependency-free
 *     isSchemaDenied/isFileDenied/assertPublishable helpers.
 *   - publish-decisions-via-opencatalogi adds the board-governance family and
 *     the lifecycle eligibility gates (its tasks 2.2/2.3) via DENY_TYPES and
 *     isDeniedType/assertEligible.
 * The two deny-lists are unioned here so the merge reconciles cleanly.
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
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DomainException;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that decides whether a governance object is publishable.
 *
 * Two independent gates, evaluated in order:
 *   1. Structural type DENY-LIST — refused before any eligibility evaluation
 *      (board-governance family, votes/rounds, confidential resolutions,
 *      conflict declarations, audit logs, transcripts, recordings).
 *   2. Lifecycle/state eligibility per source type (decision / agenda / minutes).
 *
 * It also owns the schema/file deny-list seeded by the meeting-transcription
 * change (Transcript schema + recording-file markers/extensions), exposed via
 * the dependency-free isSchemaDenied/isFileDenied/assertPublishable helpers.
 * Both deny-lists live here as a single, testable home.
 *
 * @spec openspec/specs/public-publication/spec.md
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class PublicationEligibilityService {
	/**
	 * Structural deny-list of object/schema types that are NEVER publishable.
	 *
	 * Matched case-insensitively against the schema slug AND the object's
	 * declared type discriminators. Includes the meeting-transcription family
	 * (Transcript, recording) so the deny-lists union cleanly.
	 *
	 * @var string[]
	 */
	public const DENY_TYPES = [
		'boardmeeting',
		'board-meeting',
		'boardminutes',
		'board-minutes',
		'boardmaterial',
		'board-material',
		'boardvote',
		'board-vote',
		'conflictofinterest',
		'conflict-of-interest',
		'boardauditlogentry',
		'audit-trail',
		'auditlogentry',
		'vote',
		'votinground',
		'voting-round',
		'transcript',
		'recording',
	];

	/**
	 * Schema slugs that are structurally ineligible for public publication.
	 *
	 * @var string[]
	 */
	public const DENIED_SCHEMAS = [
		'transcript',
	];

	/**
	 * File-name patterns (lowercase substrings) treated as confidential
	 * recording/transcript artefacts that must never be published.
	 *
	 * @var string[]
	 */
	public const DENIED_FILE_MARKERS = [
		'recording',
		'transcript',
	];

	/**
	 * Audio extensions whose files are confidential recordings.
	 *
	 * @var string[]
	 */
	public const DENIED_FILE_EXTENSIONS = [
		'mp3',
		'wav',
		'm4a',
		'ogg',
		'oga',
		'opus',
		'flac',
		'mka',
		'webm',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Determine whether a schema/type is on the structural deny-list.
	 *
	 * Evaluated BEFORE any lifecycle eligibility check — board-governance and
	 * confidential outputs are structurally non-publishable regardless of state.
	 *
	 * @param string $schema Source schema slug (decision|meeting|minutes|...).
	 * @param array<string,mixed> $objectData The object payload (for discriminators).
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return bool True when publication must be structurally refused.
	 */
	public function isDeniedType(string $schema, array $objectData): bool {
		$candidates = [strtolower($schema)];

		// Object-level discriminators that can flip an otherwise-allowed schema
		// into the deny-list (e.g. a confidential Resolution-type decision).
		foreach (['type', 'objectType', 'decisionType', 'meetingType'] as $key) {
			$value = ($objectData[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				$candidates[] = strtolower($value);
			}
		}

		foreach ($candidates as $candidate) {
			if (in_array($candidate, self::DENY_TYPES, true) === true) {
				return true;
			}
		}

		// Confidential Resolution: a resolution carrying a confidentiality
		// classification is never publishable (board confidentiality wins).
		$classification = strtolower((string)($objectData['confidentiality'] ?? $objectData['classification'] ?? ''));
		if (($objectData['decisionType'] ?? '') === 'resolution'
			&& in_array($classification, ['confidential', 'secret', 'restricted'], true) === true
		) {
			return true;
		}

		return false;
	}//end isDeniedType()

	/**
	 * Whether a schema slug is on the structural publication deny-list.
	 *
	 * @param string $schemaSlug The schema slug (kebab-case, e.g. 'transcript').
	 *
	 * @return bool True when the schema can never be published.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function isSchemaDenied(string $schemaSlug): bool {
		return in_array(strtolower(trim($schemaSlug)), self::DENIED_SCHEMAS, true);
	}//end isSchemaDenied()

	/**
	 * Whether a file is a confidential recording/transcript artefact.
	 *
	 * @param string $fileName File name (with extension) or path.
	 *
	 * @return bool True when the file can never be published.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function isFileDenied(string $fileName): bool {
		$lower = strtolower(basename(trim($fileName)));
		if ($lower === '') {
			return false;
		}

		$ext = (string)pathinfo($lower, PATHINFO_EXTENSION);
		if (in_array($ext, self::DENIED_FILE_EXTENSIONS, true) === true) {
			return true;
		}

		foreach (self::DENIED_FILE_MARKERS as $marker) {
			if (str_contains($lower, $marker) === true) {
				return true;
			}
		}

		return false;
	}//end isFileDenied()

	/**
	 * Assert that a publication target is eligible, throwing on a denied target.
	 *
	 * Payload-construction callers invoke this before building any public
	 * payload so a denied schema or recording file is refused regardless of
	 * status or actor.
	 *
	 * @param string $schemaSlug The target schema slug.
	 * @param string|null $fileName Optional target file name/path.
	 *
	 * @return void
	 *
	 * @throws DomainException When the target is on the deny-list (code 422).
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function assertPublishable(string $schemaSlug, ?string $fileName = null): void {
		if ($this->isSchemaDenied(schemaSlug: $schemaSlug) === true) {
			throw new DomainException(
				sprintf('Objects of type "%s" are not publishable (structural deny-list).', $schemaSlug),
				422
			);
		}

		if ($fileName !== null && $this->isFileDenied(fileName: $fileName) === true) {
			throw new DomainException(
				sprintf('File "%s" is a confidential recording/transcript and is not publishable.', $fileName),
				422
			);
		}

	}//end assertPublishable()

	/**
	 * Evaluate full publication eligibility for a source object.
	 *
	 * Runs the deny-list first, then the per-type lifecycle/state gate. Throws
	 * on any failure so callers can surface a precise error; returns the
	 * resolved object data on success.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 * @param string $sourceId UUID of the source object.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws MissingObjectException When the source object does not exist.
	 * @throws AccessDeniedException When structurally denied or not yet eligible.
	 *
	 * @return array<string,mixed> The resolved source object data.
	 */
	public function assertEligible(string $sourceType, string $sourceId): array {
		$schema = $this->schemaForType(sourceType: $sourceType);
		$data = $this->loadObject(schema: $schema, id: $sourceId);

		if ($this->isDeniedType(schema: $schema, objectData: $data) === true) {
			throw new AccessDeniedException(message: 'This object type is not publishable.');
		}

		switch ($sourceType) {
			case 'decision':
				$this->assertDecisionEligible(data: $data);
				break;
			case 'agenda':
				$this->assertAgendaEligible(data: $data);
				break;
			case 'minutes':
				$this->assertMinutesEligible(data: $data);
				break;
			default:
				throw new AccessDeniedException(message: 'Unknown publication source type: ' . $sourceType);
		}

		return $data;
	}//end assertEligible()

	/**
	 * Assert a decision is in a publishable lifecycle (decided|enacted).
	 *
	 * @param array<string,mixed> $data Decision object data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws AccessDeniedException When not eligible.
	 *
	 * @return void
	 */
	private function assertDecisionEligible(array $data): void {
		$lifecycle = (string)($data['lifecycle'] ?? '');
		if (in_array($lifecycle, ['decided', 'enacted'], true) === false) {
			throw new AccessDeniedException(
				message: 'Only decisions in status "decided" or "enacted" are publishable.'
			);
		}

	}//end assertDecisionEligible()

	/**
	 * Assert a meeting agenda is publishable (isPublic + convocation sent).
	 *
	 * @param array<string,mixed> $data Meeting object data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws AccessDeniedException When not eligible.
	 *
	 * @return void
	 */
	private function assertAgendaEligible(array $data): void {
		if (($data['isPublic'] ?? false) !== true) {
			throw new AccessDeniedException(
				message: 'Only agendas of meetings flagged isPublic are publishable.'
			);
		}

		$convocationSent = ($data['convocationSentAt'] ?? $data['convocationSent'] ?? null);
		if (empty($convocationSent) === true) {
			throw new AccessDeniedException(
				message: 'The meeting convocation must have been sent before the agenda is publishable.'
			);
		}

	}//end assertAgendaEligible()

	/**
	 * Assert a set of minutes is publishable (lifecycle approved).
	 *
	 * @param array<string,mixed> $data Minutes object data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws AccessDeniedException When not eligible.
	 *
	 * @return void
	 */
	private function assertMinutesEligible(array $data): void {
		$lifecycle = (string)($data['lifecycle'] ?? '');
		if (in_array($lifecycle, ['approved', 'signed', 'published'], true) === false) {
			throw new AccessDeniedException(
				message: 'Only minutes in lifecycle "approved" (or later) are publishable.'
			);
		}

	}//end assertMinutesEligible()

	/**
	 * Map a publication source type to its OR schema slug.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string The schema slug.
	 */
	private function schemaForType(string $sourceType): string {
		return match ($sourceType) {
			'decision' => 'decision',
			'agenda' => 'meeting',
			'minutes' => 'minutes',
			default => $sourceType,
		};

	}//end schemaForType()

	/**
	 * Load a source object from OpenRegister and return its data array.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Object UUID.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws MissingObjectException When the object does not exist.
	 *
	 * @return array<string,mixed> The object data.
	 */
	private function loadObject(string $schema, string $id): array {
		try {
			$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq publication: failed to load source object', ['exception' => $e->getMessage()]);
			throw new MissingObjectException(message: 'Source object could not be loaded.');
		}

		if ($entity === null) {
			throw new MissingObjectException(message: 'Source object not found: ' . $id);
		}

		return $entity->jsonSerialize();
	}//end loadObject()
}//end class
