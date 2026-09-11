<?php

/**
 * Decidiq ORI Publication Service
 *
 * Service for publishing voting round results to the ORI (Open Raadsinformatie) API.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\Decidiq\AppInfo\Application;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service that sends voting round results to the ORI 1.0 API endpoint
 * as JSON-LD. Publication is skipped silently when no endpoint is configured.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 */
class OriPublicationService {
	/**
	 * Construct the OriPublicationService.
	 *
	 * @param ContainerInterface $container The DI container for lazy-loading services
	 * @param IAppConfig $appConfig App configuration for reading ORI endpoint
	 * @param IClientService $clientService Nextcloud HTTP client service
	 * @param LoggerInterface $logger Logger interface
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get the configured ORI endpoint URL.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
	 *
	 * @return string|null The URL or null if not configured
	 */
	private function getEndpoint(): ?string {
		$endpoint = $this->appConfig->getValueString(Application::APP_ID, 'ori_endpoint', '');
		if (empty($endpoint) === false) {
			return $endpoint;
		}

		return null;
	}//end getEndpoint()

	/**
	 * Validate that an ORI endpoint URL is safe to call (HTTPS-only, non-empty host).
	 *
	 * IP-range SSRF protection is intentionally delegated to Nextcloud's IClientService,
	 * which enforces the `allow_local_remote_servers` system config at request time and
	 * avoids DNS TOCTOU races that arise from resolving the hostname twice.
	 *
	 * @param string $url The URL to validate
	 *
	 * @return bool True when the URL passes basic format checks
	 */
	private function isValidOriEndpoint(string $url): bool {
		$parsed = parse_url($url);
		if (($parsed['scheme'] ?? '') !== 'https') {
			return false;
		}

		$host = ($parsed['host'] ?? '');
		if (empty($host) === true) {
			return false;
		}

		// Reject direct private/loopback IP ranges to prevent SSRF (OWASP A10).
		// DNS-based rebinding is handled separately by Nextcloud IClientService via allow_local_remote_servers.
		$parsedIp = filter_var($host, FILTER_VALIDATE_IP);
		if ($parsedIp !== false) {
			$isPublicIp = filter_var($parsedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
			if ($isPublicIp === false) {
				return false;
			}
		}

		return true;
	}//end isValidOriEndpoint()

	/**
	 * Publish a VotingRound's results to the ORI API.
	 *
	 * Reads the ORI endpoint from IAppConfig. If not configured or the URL fails
	 * validation, returns silently. Builds a JSON-LD payload following ORI 1.0
	 * format and POSTs it via Nextcloud's IClientService. On success, stamps
	 * oriPublishedAt on the VotingRound object. On failure, logs a warning.
	 *
	 * @param string $votingRoundId UUID of the VotingRound to publish
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
	 *
	 * @return void
	 */
	public function publish(string $votingRoundId): void {
		// Structural publication deny-list (meeting-transcription-ai-minutes
		// task 2.7): refuse before any payload is built if the target type is
		// never publishable. VotingRound is publishable today; the guard is the
		// single enforcement seam so the deny-list home stays exercised and the
		// publish-decisions change extends the same list.
		// @spec openspec/specs/meeting-transcription/spec.md
		// The deny-list requirement itself lives in that spec.
		try {
			$eligibility = $this->container->get(\OCA\Decidiq\Service\PublicationEligibilityService::class);
			$eligibility->assertPublishable(schemaSlug: 'voting-round');
		} catch (\DomainException $e) {
			$this->logger->warning('Decidiq ORI: publication refused by deny-list — ' . $e->getMessage());
			return;
		} catch (Throwable) {
			// Eligibility service unavailable (very old container): do not block
			// the existing publish path on a missing optional guard.
		}

		$endpoint = $this->getEndpoint();
		if ($endpoint === null) {
			return;
		}

		if ($this->isValidOriEndpoint(url: $endpoint) === false) {
			$this->logger->warning("Decidiq ORI: endpoint '$endpoint' failed safety validation — publication skipped");
			return;
		}

		try {
			$this->objectService->setRegister('decidiq');
			$this->objectService->setSchema('voting-round');
			$roundObject = $this->objectService->find($votingRoundId);

			if ($roundObject === null) {
				$this->logger->warning("Decidiq ORI: VotingRound $votingRoundId not found for publication");
				return;
			}

			$roundData = $roundObject->getObject();

			$payload = $this->buildJsonLd(votingRoundId: $votingRoundId, roundData: $roundData);

			$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($body === false) {
				throw new RuntimeException('JSON encoding of ORI payload failed: ' . json_last_error_msg());
			}

			// #320: Attach the configurable ORI bearer token when set.
			// The secret is stored in IAppConfig under key 'ori_bearer_secret'.
			// When not configured the Authorization header is omitted (ORI endpoints
			// that require auth will return 401, surfacing a configuration gap).
			$headers = [
				'Content-Type' => 'application/ld+json',
				'Accept' => 'application/json',
			];

			$bearerSecret = $this->appConfig->getValueString(Application::APP_ID, 'ori_bearer_secret', '');
			if ($bearerSecret !== '') {
				$headers['Authorization'] = 'Bearer ' . $bearerSecret;
			}

			$client = $this->clientService->newClient();
			$client->post(
				$endpoint,
				[
					'headers' => $headers,
					'body' => $body,
					'timeout' => 10,
				]
			);

			// Stamp oriPublishedAt to distinguish "published" from merely "closed".
			$this->objectService->saveObject(
				object: array_merge($roundData, ['oriPublishedAt' => (new DateTimeImmutable())->format(\DateTimeInterface::ATOM)]),
				register: 'decidiq',
				schema: 'voting-round',
				uuid: $votingRoundId,
			);

			$this->logger->info("Decidiq ORI: VotingRound $votingRoundId published successfully to $endpoint");
		} catch (Throwable $e) {
			// M3: log here for diagnostics, then rethrow so the caller (VotingService)
			// can attach the error to the round data and surface it in the response.
			$this->logger->warning(
				"Decidiq ORI: Publication error for round $votingRoundId: {$e->getMessage()}"
			);
			throw $e;
		}//end try

	}//end publish()

	/**
	 * Build a JSON-LD payload following the ORI 1.0 standard for a VotingRound.
	 *
	 * @param string $votingRoundId UUID of the VotingRound
	 * @param array<string,mixed> $roundData VotingRound object data
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
	 *
	 * @return array<string,mixed> JSON-LD payload
	 */
	private function buildJsonLd(string $votingRoundId, array $roundData): array {
		return [
			'@context' => 'https://schema.org/',
			'@type' => 'VoteAction',
			'@id' => 'urn:voting-round:' . $votingRoundId,
			'name' => 'Stemuitslag ' . $votingRoundId,
			'startTime' => $roundData['openedAt'] ?? null,
			'endTime' => $roundData['closedAt'] ?? null,
			'result' => $roundData['result'] ?? null,
			'object' => [
				'@type' => 'GovernmentPermit',
				'votesFor' => $roundData['votesFor'] ?? 0,
				'votesAgainst' => $roundData['votesAgainst'] ?? 0,
				'votesAbstain' => $roundData['votesAbstain'] ?? 0,
				'votingMethod' => $roundData['votingMethod'] ?? 'for-against-abstain',
				'isSecret' => $roundData['isSecret'] ?? false,
				'quorumWith' => $roundData['quorumWith'] ?? false,
			],
		];

	}//end buildJsonLd()

	/**
	 * Get the current publication status for a VotingRound.
	 *
	 * Returns 'not_configured' if no ORI endpoint is set, 'published' if the
	 * round has an oriPublishedAt timestamp (set after a successful ORI POST),
	 * or 'pending' otherwise.
	 *
	 * @param string $votingRoundId UUID of the VotingRound
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.1
	 *
	 * @return string 'not_configured' | 'published' | 'pending'
	 */
	public function getPublicationStatus(string $votingRoundId): string {
		if ($this->getEndpoint() === null) {
			return 'not_configured';
		}

		try {
			$this->objectService->setRegister('decidiq');
			$this->objectService->setSchema('voting-round');
			$roundObject = $this->objectService->find($votingRoundId);

			if ($roundObject === null) {
				return 'pending';
			}

			$roundData = $roundObject->getObject();
			if (($roundData['oriPublishedAt'] ?? null) !== null) {
				return 'published';
			}

			return 'pending';
		} catch (Throwable $e) {
			return 'pending';
		}

	}//end getPublicationStatus()
}//end class
