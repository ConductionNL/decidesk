<?php

/**
 * Decidiq OpenCatalogi Publisher
 *
 * Routes derived publication payloads into a configured OpenCatalogi catalog
 * and retracts them on withdraw. Degrades gracefully when OpenCatalogi is
 * absent (the caller surfaces a staff-visible warning).
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
use DateTimeInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin adapter over OpenCatalogi's publication API.
 *
 * Mirrors OriPublicationService's OpenCatalogi-absent graceful-degrade posture:
 * when the OpenCatalogi publication service cannot be resolved, publish/retract
 * fail honestly (returning '' / false) so the caller can mark the
 * PublicationRecord pending and warn staff — never a silent success.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class OpenCatalogiPublisher {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy OpenCatalogi service).
	 * @param IAppManager $appManager Detects OpenCatalogi presence.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a publication for a payload in the given catalog.
	 *
	 * @param string $catalogId Target OpenCatalogi catalog id.
	 * @param string $payloadId UUID of the published PublicationPayload object.
	 * @param array<string,mixed> $payload The payload data (for catalog metadata).
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string The catalog publication reference, or '' on failure/degrade.
	 */
	public function publish(string $catalogId, string $payloadId, array $payload): string {
		if ($this->appManager->isEnabledForAnyone('opencatalogi') === false) {
			return '';
		}

		$service = $this->resolvePublicationService();
		if ($service === null) {
			return '';
		}

		try {
			$publication = $service->saveObject(
				object: [
					'title' => (string)($payload['title'] ?? ''),
					'summary' => (string)($payload['title'] ?? ''),
					'catalog' => $catalogId,
					'published' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
					'reference' => $payloadId,
					'register' => 'decidiq',
					'schema' => 'publication-payload',
				],
				register: 'opencatalogi',
				schema: 'publication',
			);

			return $this->referenceOf(publication: $publication);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq publication: OpenCatalogi publish failed', ['exception' => $e->getMessage()]);
			return '';
		}//end try

	}//end publish()

	/**
	 * Extract the catalog publication reference from whatever OpenCatalogi returned.
	 *
	 * Prefers the entity's own `getUuid()`, then falls back to the serialized
	 * id/uuid/@self.id. Returns '' when no usable reference is present.
	 *
	 * @param mixed $publication The saveObject() return value.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string The catalog publication reference, or '' when absent.
	 */
	private function referenceOf(mixed $publication): string {
		if (is_object($publication) === false) {
			return '';
		}

		if (method_exists($publication, 'getUuid') === true) {
			$uuid = $publication->getUuid();
			if (is_string($uuid) === true && $uuid !== '') {
				return $uuid;
			}
		}

		if (method_exists($publication, 'jsonSerialize') === true) {
			$data = $publication->jsonSerialize();
			$id = ($data['id'] ?? $data['uuid'] ?? ($data['@self']['id'] ?? null));
			if (is_string($id) === true && $id !== '') {
				return $id;
			}
		}

		return '';
	}//end referenceOf()

	/**
	 * Retract a previously-created catalog publication.
	 *
	 * @param string $catalogPublication The catalog publication reference.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return bool True when retraction succeeded; false to mark pending + warn.
	 */
	public function retract(string $catalogPublication): bool {
		if ($catalogPublication === '') {
			return true;
		}

		if ($this->appManager->isEnabledForAnyone('opencatalogi') === false) {
			return false;
		}

		$service = $this->resolvePublicationService();
		if ($service === null) {
			return false;
		}

		try {
			$service->deleteObject(
				uuid: $catalogPublication,
				register: 'opencatalogi',
				schema: 'publication',
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq publication: OpenCatalogi retraction failed', ['exception' => $e->getMessage()]);
			return false;
		}

	}//end retract()

	/**
	 * Resolve OpenCatalogi's ObjectService, or null when unavailable.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return object|null
	 */
	private function resolvePublicationService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq publication: OpenCatalogi ObjectService unresolvable', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end resolvePublicationService()
}//end class
