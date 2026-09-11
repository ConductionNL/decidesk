<?php

/**
 * Decidiq ORI API 1.4 Controller
 *
 * ORI-compliant JSON-LD endpoints at `/api/ori/v1/{resource}` for council
 * information transparency (REQ-ORI-001..004).
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\OriSerializer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ORI API 1.4 compatibility controller.
 *
 * Serializes Decidiq register objects per the VNG ORI specification. ORI is
 * versioned independently from the general v1 REST API (REQ-ORI-001) so its
 * URL and response envelope live in this dedicated controller.
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-11
 */
class OriController extends Controller {

	/**
	 * Map of ORI resource slug → register schema slug.
	 *
	 * @var array<string, string>
	 */
	private const RESOURCE_MAP = [
		'organizations' => 'governance-body',
		'persons' => 'person',
		'memberships' => 'membership',
		'events' => 'meeting',
		'agendaitems' => 'agenda-item',
		'motions' => 'decision',
		'amendments' => 'decision',
		'voteevents' => 'voting-round',
		'votes' => 'vote',
		'reports' => 'minutes',
		// Publish-decisions-via-opencatalogi task 5.2 — ORI harvest feed over the
		// derived, immutable PublicationPayload objects produced by the publication
		// flow. This is the harvest-able feed the deferred follow-up specifies: a
		// single ORI surface a national/OAI-PMH harvester can poll to discover all
		// published decisions/agendas/minutes without per-type endpoints. Visibility
		// is gated by the same RBAC published-predicate the payload schema declares
		// (publicationDate <= $now, not depublished).
		'publications' => 'publication-payload',
	];

	/**
	 * ORI resource slugs that are now sourced from the unified `decision`
	 * schema (ADR-005). Maps the resource slug to the `decisionType`
	 * discriminator used to filter decisions down to that ORI resource.
	 *
	 * @var array<string, string>
	 */
	private const DECISION_TYPE_MAP = [
		'motions' => 'motion',
		'amendments' => 'amendment',
	];

	/**
	 * ORI resource slugs that must NOT be filtered by lifecycle.
	 *
	 * Person and Membership are public reference data (Popolo identity and
	 * org-relationship) and carry no lifecycle field. Adding a lifecycle=published
	 * filter would return zero objects.
	 *
	 * @var list<string>
	 */
	private const NO_LIFECYCLE_GATE = [
		'persons',
		'memberships',
	];

	/**
	 * Map of ORI resource slug → ORI/Akoma Ntoso @type label.
	 *
	 * @var array<string, string>
	 */
	private const ORI_TYPE_MAP = [
		'organizations' => 'Organization',
		'persons' => 'Person',
		'memberships' => 'Membership',
		'events' => 'Event',
		'agendaitems' => 'AgendaItem',
		'motions' => 'Motion',
		'amendments' => 'Amendment',
		'voteevents' => 'VoteEvent',
		'votes' => 'Vote',
		'reports' => 'Report',
		// The publication-payload feed self-declares its ORI @type per item via the
		// payload's own `oriType` (Besluit / Vergadering / Verslag); this envelope
		// label describes the harvest collection.
		'publications' => 'Publication',
	];

	/**
	 * ORI JSON-LD context URL.
	 */
	private const ORI_CONTEXT = OriSerializer::ORI_CONTEXT;

	/**
	 * The ORI resource slug backed by derived PublicationPayload objects.
	 */
	private const PUBLICATIONS = OriSerializer::PUBLICATIONS_RESOURCE;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param IConfig $config The Nextcloud config service
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger PSR-3 logger
	 * @param OriSerializer $serializer The ORI JSON-LD serializer
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly OriSerializer $serializer,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * List ORI resources.
	 *
	 * ORI (Open Raads Informatie) is an OPEN DATA API by design: the ids are
	 * published resource identifiers, not credentials, and enumerating council
	 * decisions is the intended use rather than an attack. So these endpoints
	 * get a volume ceiling and deliberately NO #[BruteForceProtection] --
	 * there is no secret to guess, and a brute-force counter here would
	 * throttle exactly the consumers the standard exists to serve.
	 * See ADR-082 for the distinction.
	 *
	 * @param string $resource The ORI resource slug (e.g. `events`)
	 *
	 * @return JSONResponse JSON-LD list envelope or error
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-11
	 * @spec openspec/specs/public-publication/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(string $resource): JSONResponse {
		$schema = self::RESOURCE_MAP[$resource] ?? null;
		if ($schema === null) {
			return $this->errorResponse(message: 'Unknown resource', status: Http::STATUS_NOT_FOUND);
		}

		try {
			$objectService = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService');
			$objects = $objectService->findAll(
				[
					'limit' => 100,
					'filters' => $this->buildFilters(resource: $resource, schema: $schema),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(message: 'OriController index failed', context: ['resource' => $resource, 'exception' => $e]);
			return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		$type = self::ORI_TYPE_MAP[$resource];
		$items = $this->serializer->serializeCollection(resource: $resource, type: $type, objects: ($objects ?? []));

		return $this->jsonLdResponse(
			payload: [
				'@context' => self::ORI_CONTEXT,
				'@type' => $type,
				'count' => count($items),
				'items' => $items,
			]
		);

	}//end index()

	/**
	 * Build the OpenRegister findAll() filter set for an ORI resource.
	 *
	 * #316: Only return published objects on public ORI endpoints — draft/closed/
	 * unpublished objects must not be visible to anonymous callers.
	 * ADR-005: motions/amendments are now `decision` objects discriminated by
	 * `decisionType`; decisions gate public visibility via `isPublished=public`
	 * (not the meeting `lifecycle=published` state used by other resources).
	 * OpenRegister resolves the register/schema context from INSIDE the `filters`
	 * array (ObjectService::prepareFindAllConfig) and takes a single config array
	 * — not named register:/schema:/params: arguments (the latter raised
	 * "Unknown named parameter $register").
	 *
	 * @param string $resource The ORI resource slug
	 * @param string $schema The resolved register schema slug
	 *
	 * @return array<string, string> The filter set
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-11
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function buildFilters(string $resource, string $schema): array {
		$filters = [
			'register' => 'decidiq',
			'schema' => $schema,
		];

		if ($resource === self::PUBLICATIONS) {
			// Publish-decisions-via-opencatalogi task 5.2 — the PublicationPayload
			// feed has no `lifecycle`/`isPublished` field; its anonymous visibility
			// is governed solely by the RBAC published-predicate the schema declares
			// (public group when publicationDate <= $now). OR enforces that rule for
			// anonymous callers; the serializer additionally filters the window in
			// PHP (defence-in-depth so a misconfigured RBAC rule cannot leak
			// future-dated or depublished payloads through the harvest feed).
			return $filters;
		}

		$decisionType = self::DECISION_TYPE_MAP[$resource] ?? null;
		if ($decisionType !== null) {
			// ADR-005: motion/amendment ORI resources are decisions gated by
			// `isPublished=public` and discriminated by `decisionType`.
			$filters['isPublished'] = 'public';
			$filters['decisionType'] = $decisionType;

			return $filters;
		}

		if (in_array(needle: $resource, haystack: self::NO_LIFECYCLE_GATE, strict: true) === false) {
			// Person/Membership are public reference data without a lifecycle field;
			// all other resources require the published lifecycle gate (#316).
			$filters['lifecycle'] = 'published';
		}

		return $filters;
	}//end buildFilters()

	/**
	 * Drop an object whose `decisionType` does not match the ORI resource.
	 *
	 * ADR-005 folded motion and amendment into the one `decision` schema, so
	 * `/motions/{id}` and `/amendments/{id}` resolve through the same schema and
	 * the schema no longer distinguishes them. index() narrows by `decisionType`
	 * (DECISION_TYPE_MAP); the detail endpoint must apply the same discriminator,
	 * or `/amendments/{id}` would serve a motion as an ORI Amendment.
	 *
	 * @param string $resource The ORI resource slug
	 * @param array<string, mixed>|null $object The serialized object, or null
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return array<string, mixed>|null The object, or null when it is the wrong type
	 */
	private function narrowToDecisionType(string $resource, ?array $object): ?array {
		$decisionType = (self::DECISION_TYPE_MAP[$resource] ?? null);
		if ($object === null || $decisionType === null) {
			return $object;
		}

		if (($object['decisionType'] ?? null) !== $decisionType) {
			return null;
		}

		return $object;
	}//end narrowToDecisionType()

	/**
	 * Retrieve a single ORI resource by id.
	 *
	 * @param string $resource The ORI resource slug
	 * @param string $id The entity UUID
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-11
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return JSONResponse The JSON-LD entity or error
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string $resource, string $id): JSONResponse {
		$schema = self::RESOURCE_MAP[$resource] ?? null;
		if ($schema === null) {
			return $this->errorResponse(message: 'Unknown resource', status: Http::STATUS_NOT_FOUND);
		}

		try {
			$objectService = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService');
			$entity = $objectService->find(id: $id, register: 'decidiq', schema: $schema);
			$object = null;
			if ($entity !== null) {
				$object = $entity->jsonSerialize();
			}

			$object = $this->narrowToDecisionType(resource: $resource, object: $object);
		} catch (DoesNotExistException $e) {
			// OpenRegister's published-predicate RBAC hides a future-dated or
			// depublished payload from an anonymous caller by making find() THROW,
			// not by returning null — so the not-live 404 branch below was never
			// reached and the blanket Throwable catch turned it into a 500. A 500
			// is itself a disclosure (it separates "exists but hidden" from
			// "unknown id"), which is exactly what this endpoint must not do.
			// An id we cannot read is not-found, full stop.
			return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(message: 'OriController show failed', context: ['resource' => $resource, 'id' => $id, 'exception' => $e]);
			return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		if ($object === null) {
			return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
		}

		$type = self::ORI_TYPE_MAP[$resource];

		if ($resource === self::PUBLICATIONS) {
			// PublicationPayload visibility is the RBAC published-predicate window
			// (publicationDate <= $now, not depublished). A future-dated or
			// depublished payload is not-found for anonymous callers — return 404
			// (not 403) so the endpoint never confirms an unpublished payload exists.
			if ($this->serializer->isPayloadLive(object: $object) === false) {
				return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
			}

			return $this->jsonLdResponse(
				payload: $this->serializer->serializePayload(object: $object, fallbackType: $type)
			);
		}//end if

		// #316: Treat non-published objects as not-found for anonymous callers.
		// Return 404 (not 403) to avoid confirming the object exists.
		if ($this->isLifecycleBlocked(object: $object) === true) {
			return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
		}

		return $this->jsonLdResponse(payload: $this->serializer->serialize(type: $type, object: $object));
	}//end show()

	/**
	 * Decide whether the lifecycle gate hides an object from anonymous callers.
	 *
	 * M2: only enforce the lifecycle gate when the object actually carries a
	 * lifecycle/status field; schemas without it (votes, persons, etc.) pass
	 * through.
	 *
	 * @param array<string, mixed> $object The serialized register object
	 *
	 * @return bool True when the object must be reported as not-found
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-11
	 */
	private function isLifecycleBlocked(array $object): bool {
		$lifecycle = ($object['lifecycle'] ?? ($object['status'] ?? null));

		return ($lifecycle !== null && $lifecycle !== 'published');
	}//end isLifecycleBlocked()

	/**
	 * Wrap a payload in a CORS-decorated JSON-LD response.
	 *
	 * @param array<string, mixed> $payload The JSON-LD body
	 *
	 * @return JSONResponse The decorated response
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-11
	 */
	private function jsonLdResponse(array $payload): JSONResponse {
		$response = new JSONResponse($payload);
		$response->addHeader(name: 'Content-Type', value: 'application/ld+json');
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end jsonLdResponse()

	/**
	 * CORS preflight handler for the list endpoint.
	 *
	 * @param string $resource The ORI resource slug
	 *
	 * @return JSONResponse HTTP 200 with Access-Control-* headers
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflight(string $resource): JSONResponse {
		unset($resource);

		$response = new JSONResponse([], Http::STATUS_OK);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end preflight()

	/**
	 * CORS preflight handler for the item endpoint.
	 *
	 * The browser sends one preflight before each cross-origin call, so the
	 * AnonRateLimit ceiling is deliberately looser than on the data endpoints
	 * it precedes.
	 *
	 * @param string $resource The ORI resource slug
	 * @param string $id The entity UUID
	 *
	 * @return JSONResponse HTTP 200 with Access-Control-* headers
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightItem(string $resource, string $id): JSONResponse {
		unset($resource, $id);

		$response = new JSONResponse([], Http::STATUS_OK);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end preflightItem()

	/**
	 * Build a consistent JSON error envelope (REQ-API-003).
	 *
	 * @param string $message The user-facing message
	 * @param int $status The HTTP status code
	 *
	 * @return JSONResponse The decorated error response
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.2
	 */
	private function errorResponse(string $message, int $status): JSONResponse {
		$response = new JSONResponse(['message' => $message, 'code' => $status], $status);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end errorResponse()

	/**
	 * Apply CORS headers using the configured proxy origin when available.
	 *
	 * @param JSONResponse $response The response to decorate
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 * @spec openspec/changes/p4-integration/tasks.md#task-10.4
	 */
	private function applyCorsHeaders(JSONResponse $response): void {
		$origin = $this->config->getSystemValueString(key: 'overwrite.cli.url', default: '*');

		$allowedOrigin = '*';
		if ($origin !== '') {
			$allowedOrigin = $origin;
		}

		$response->addHeader(name: 'Access-Control-Allow-Origin', value: $allowedOrigin);
		$response->addHeader(name: 'Access-Control-Allow-Methods', value: 'GET, OPTIONS');
		$response->addHeader(name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type, X-Requested-With');

	}//end applyCorsHeaders()
}//end class
