<?php

/**
 * Decidiq Audit Log Service
 *
 * Append-only audit log for governance actions, persisted through
 * OpenRegister's audit-trail surface (AuditTrailMapper::createAuditTrailEntry,
 * hash-chained and sealed platform-side by AuditSealJob / AuditHashService).
 * Every action (vote, conflict declaration, material access, signature,
 * notice send, proxy grant or revocation, decision transition) lands as an OR
 * audit row with a namespaced `decidiq.audit.{action}` action string and the
 * decidiq context (actor, object uids, payload) in the `changed` column, per
 * the audit-trail-fleet-wide-consumption spec and ADR-022.
 *
 * This service previously kept its own hash chain in an app-local
 * `audit-trail` schema. That schema was retired with the board portal (C3,
 * "board-audit-log-entry=OR auditTrail") but the writer was never repointed:
 * it kept saving to a schema slug NO register carries, so every append failed
 * ("Schema slug audit-trail is not carried by register decidiq") and every
 * governance action since C3 silently produced no audit row.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AuditHashService;
use Psr\Log\LoggerInterface;

/**
 * Tamper-evident append-only audit log service, consuming OR's audit trail.
 *
 * Writes go through AuditTrailMapper::createAuditTrailEntry() — the same
 * surface dossiq's parafering audit uses — so decidiq rows join the
 * platform-wide hash chain instead of maintaining a parallel one. Reads
 * (query/export) select the `decidiq.audit.*` action namespace back out of
 * the trail; verification delegates to OR's AuditHashService::verifyChain().
 *
 * Failure mode is LOUD by contract: when the audit surface is unavailable or
 * no referenced object resolves, append() logs at error level and returns
 * success=false, and callers on governance paths surface that. It never
 * pretends a row was written.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
class AuditLogService {

	/**
	 * Namespace prefixed to every decidiq action written into OR's audit
	 * trail, per the fleet convention `{app}.{domain}.{event}`.
	 *
	 * @var string
	 */
	public const ACTION_NAMESPACE = 'decidiq.audit.';

	/**
	 * Allowed action enum values (the closed decidiq audit vocabulary).
	 *
	 * `integration-create`, `integration-subscribe` and
	 * `transcript-retention-purge` were appended by their callers all along
	 * but were missing here, so those appends always failed with "Unknown
	 * action" before ever reaching storage.
	 *
	 * @var string[]
	 */
	public const ACTIONS = [
		'vote',
		'conflict-declaration',
		'material-access',
		'signature',
		'notice-sent',
		'proxy-created',
		'proxy-revoked',
		'decision-transition',
		'series-generated',
		'integration-create',
		'integration-subscribe',
		'transcript-retention-purge',
	];

	/**
	 * Maximum audit rows loaded for query/export (mirrors the previous
	 * whole-chain load bound).
	 *
	 * @var int
	 */
	private const CHAIN_LIMIT = 10000;

	/**
	 * Exact-match query filters applied to the mapped decidiq row shape.
	 *
	 * @var array<string, string>
	 */
	private const QUERY_EQUALITY_FIELDS = [
		'actor' => 'actorUuid',
		'action' => 'action',
	];

	/**
	 * Constructor for AuditLogService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param ObjectServiceInterface $objectService OpenRegister object service (uid → entity resolution)
	 * @param AuditTrailMapper $auditTrailMapper OR audit-trail writer (hash-chained, immutable)
	 * @param AuditHashService $auditHashService OR audit chain verification service
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly AuditHashService $auditHashService,
	) {
	}//end __construct()

	/**
	 * Append a new entry to the audit log.
	 *
	 * Resolves the first referenced object uid to its OR ObjectEntity and
	 * writes one `decidiq.audit.{action}` entry onto it through
	 * AuditTrailMapper::createAuditTrailEntry(). The decidiq context (actor,
	 * the full uid list, the payload) is carried in the entry's `changed`
	 * column; hash chaining and sealing are the platform's.
	 *
	 * LOUD on failure: an unavailable audit surface, an unknown action or a
	 * uid list in which nothing resolves produces an error log plus
	 * success=false — never a silently absent row.
	 *
	 * @param string $actor Nextcloud UID of the acting user (or 'system')
	 * @param string $action One of self::ACTIONS
	 * @param string[] $objectUids List of object UUIDs the action touched
	 * @param array $payload Optional extra structured detail; stored in the entry context
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return array{success: bool, entry: array|null, message: string}
	 */
	public function append(string $actor, string $action, array $objectUids, array $payload = []): array {
		if (in_array($action, self::ACTIONS, true) === false) {
			return [
				'success' => false,
				'entry' => null,
				'message' => 'Unknown action: ' . $action,
			];
		}

		$canonicalObjectUids = array_values(array_filter(array_map('strval', $objectUids), static fn (string $uid): bool => ($uid !== '')));
		sort($canonicalObjectUids);

		if ($canonicalObjectUids === []) {
			$this->logger->error(
				'Decidiq: audit append refused — no object uids to attach the entry to',
				['actor' => $actor, 'action' => $action]
			);
			return [
				'success' => false,
				'entry' => null,
				'message' => 'An audit entry must reference at least one object uid.',
			];
		}

		try {
			$entity = $this->resolveFirstEntity(objectUids: $canonicalObjectUids);
			if ($entity === null) {
				$this->logger->error(
					'Decidiq: audit append failed — none of the referenced object uids resolve to an OpenRegister object',
					['actor' => $actor, 'action' => $action, 'objectUids' => $canonicalObjectUids]
				);
				return [
					'success' => false,
					'entry' => null,
					'message' => 'No referenced object uid resolves to an OpenRegister object.',
				];
			}

			$entry = $this->auditTrailMapper->createAuditTrailEntry(
				object: $entity,
				action: (self::ACTION_NAMESPACE . $action),
				context: [
					'actorUuid' => $actor,
					'objectUids' => $canonicalObjectUids,
					'payload' => $payload,
				]
			);

			$this->logger->info(
				'Decidiq: audit log entry appended to the OR audit trail',
				['actor' => $actor, 'action' => $action, 'entryUuid' => $entry->getUuid()]
			);

			return [
				'success' => true,
				'entry' => $this->mapRow(entry: $entry),
				'message' => 'Audit log entry appended.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to append audit log entry to the OR audit trail',
				['actor' => $actor, 'action' => $action, 'exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'entry' => null,
				'message' => 'Failed to append audit log entry: ' . $e->getMessage(),
			];
		}//end try

	}//end append()

	/**
	 * Verify the audit hash chain.
	 *
	 * Delegates to OR's AuditHashService::verifyChain() — decidiq rows sit in
	 * the platform-wide chain, so chain integrity is the platform's claim,
	 * not a parallel decidiq computation. When an entry uuid is given, the
	 * chain is verified from its start up to (and including) that entry.
	 *
	 * @param string|null $entryUuid Optional entry UUID to stop at; null verifies the
	 *                               entire chain.
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return array{valid: bool, checked: int, tampered: array<int, string>}
	 */
	public function verify(?string $entryUuid = null): array {
		try {
			$to = null;
			if ($entryUuid !== null) {
				$rows = $this->auditTrailMapper->findAll(
					limit: 1,
					offset: 0,
					filters: ['uuid' => $entryUuid]
				);

				$row = false;
				if (is_array($rows) === true) {
					$row = reset($rows);
				}

				if (($row instanceof AuditTrail) === false) {
					return [
						'valid' => false,
						'checked' => 0,
						'tampered' => [],
					];
				}

				$to = (int)$row->getId();
			}

			$result = $this->auditHashService->verifyChain(to: $to);

			$tampered = [];
			$brokenAt = ($result['brokenAt'] ?? null);
			if ($brokenAt !== null) {
				$tampered[] = (string)$brokenAt;
			}

			return [
				'valid' => (bool)($result['valid'] ?? false),
				'checked' => (int)($result['entriesVerified'] ?? 0),
				'tampered' => $tampered,
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to verify the OR audit chain',
				['exception' => $e->getMessage()]
			);
			return [
				'valid' => false,
				'checked' => 0,
				'tampered' => [],
			];
		}//end try

	}//end verify()

	/**
	 * Export audit log entries between two ISO-8601 UTC timestamps in the
	 * requested format.
	 *
	 * @param string $startDate Inclusive start (ISO-8601, e.g. "2026-01-01T00:00:00Z")
	 * @param string $endDate Inclusive end (ISO-8601)
	 * @param string $format 'json' (default) or 'csv'
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return array{success: bool, format: string, body: string, count: int}
	 */
	public function export(string $startDate, string $endDate, string $format = 'json'): array {
		$format = strtolower($format);
		if (in_array($format, ['json', 'csv'], true) === false) {
			return [
				'success' => false,
				'format' => $format,
				'body' => '',
				'count' => 0,
			];
		}

		try {
			$chain = $this->loadChain();
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to load audit log for export',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'format' => $format,
				'body' => '',
				'count' => 0,
			];
		}

		$filtered = array_values(
			array_filter(
				$chain,
				static function (array $row) use ($startDate, $endDate): bool {
					$timestamp = ($row['timestamp'] ?? '');
					return ($timestamp >= $startDate && $timestamp <= $endDate);
				}
			)
		);

		if ($format === 'csv') {
			$lines = ['id,timestamp,actor,action,objectUids,previousHash,currentHash'];
			foreach ($filtered as $row) {
				$lines[] = implode(
					',',
					[
						(string)($row['id'] ?? ''),
						(string)($row['timestamp'] ?? ''),
						(string)($row['actorUuid'] ?? ''),
						(string)($row['action'] ?? ''),
						'"' . implode('|', (array)($row['objectUids'] ?? [])) . '"',
						(string)($row['previousHash'] ?? ''),
						(string)($row['currentHash'] ?? ''),
					]
				);
			}

			return [
				'success' => true,
				'format' => 'csv',
				'body' => implode("\n", $lines),
				'count' => count($filtered),
			];
		}//end if

		$body = json_encode(
			['entries' => $filtered],
			(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);

		$jsonBody = '{}';
		if ($body !== false) {
			$jsonBody = $body;
		}

		return [
			'success' => true,
			'format' => 'json',
			'body' => $jsonBody,
			'count' => count($filtered),
		];

	}//end export()

	/**
	 * Filter the audit log by actor / action / date-range / object-uuid. All
	 * filters are optional and combined with AND semantics. Pagination defaults
	 * to 100 rows.
	 *
	 * @param array<string, mixed> $filters Filter criteria (actor / action / startDate / endDate / objectUuid / limit / offset)
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return array{success: bool, entries: array, count: int}
	 */
	public function query(array $filters = []): array {
		$actionFilter = null;
		if (($filters['action'] ?? null) !== null) {
			$actionFilter = (string)$filters['action'];
		}

		try {
			$chain = $this->loadChain(action: $actionFilter);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: failed to load audit log for query',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'entries' => [],
				'count' => 0,
			];
		}

		$filtered = array_values(
			array_filter(
				$chain,
				fn (array $row): bool => $this->matchesEqualityFilters(row: $row, filters: $filters) === true
					&& $this->matchesDateRange(row: $row, filters: $filters) === true
					&& $this->matchesObjectUuid(row: $row, filters: $filters) === true
			)
		);

		$limit = (int)($filters['limit'] ?? 100);
		$offset = (int)($filters['offset'] ?? 0);
		if ($limit <= 0) {
			$limit = 100;
		}

		$page = array_slice($filtered, $offset, $limit);

		return [
			'success' => true,
			'entries' => $page,
			'count' => count($filtered),
		];

	}//end query()

	/**
	 * Whether an audit row satisfies the exact-match filters (actor, action).
	 *
	 * @param array<string, mixed> $row A single mapped audit row
	 * @param array<string, mixed> $filters Filter criteria
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return bool
	 */
	private function matchesEqualityFilters(array $row, array $filters): bool {
		foreach (self::QUERY_EQUALITY_FIELDS as $filterKey => $rowKey) {
			$expected = ($filters[$filterKey] ?? null);
			if ($expected !== null && ($row[$rowKey] ?? null) !== $expected) {
				return false;
			}
		}

		return true;
	}//end matchesEqualityFilters()

	/**
	 * Whether an audit row's timestamp falls inside the requested date range.
	 *
	 * Both bounds are optional and compared lexicographically on the ISO-8601
	 * timestamp.
	 *
	 * @param array<string, mixed> $row A single mapped audit row
	 * @param array<string, mixed> $filters Filter criteria
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return bool
	 */
	private function matchesDateRange(array $row, array $filters): bool {
		$timestamp = ($row['timestamp'] ?? '');

		$startDate = ($filters['startDate'] ?? null);
		if ($startDate !== null && $timestamp < $startDate) {
			return false;
		}

		$endDate = ($filters['endDate'] ?? null);
		if ($endDate !== null && $timestamp > $endDate) {
			return false;
		}

		return true;
	}//end matchesDateRange()

	/**
	 * Whether an audit row references the requested object uuid.
	 *
	 * Matched against the mapped `objectUids` list (the full uid set the
	 * append carried), not only the OR row's own object_uuid column.
	 *
	 * @param array<string, mixed> $row A single mapped audit row
	 * @param array<string, mixed> $filters Filter criteria
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
	 *
	 * @return bool
	 */
	private function matchesObjectUuid(array $row, array $filters): bool {
		$objectUuid = ($filters['objectUuid'] ?? null);
		if ($objectUuid === null) {
			return true;
		}

		$uids = array_map('strval', (array)($row['objectUids'] ?? []));

		return in_array($objectUuid, $uids, true);
	}//end matchesObjectUuid()

	/**
	 * Resolve the first object uid that answers to an OR ObjectEntity.
	 *
	 * The OR audit entry is attached to one object; the full uid list still
	 * travels in the entry context. Not every uid a caller passes is an OR
	 * object (a checksum stands in for an export record that failed to
	 * persist), so resolution walks the list instead of failing on the first
	 * miss.
	 *
	 * @param string[] $objectUids Candidate object uids
	 *
	 * @return ObjectEntity|null The first resolving entity, or null when none does
	 */
	private function resolveFirstEntity(array $objectUids): ?ObjectEntity {
		foreach ($objectUids as $uid) {
			try {
				$entity = $this->objectService->find($uid);
			} catch (\Throwable) {
				continue;
			}

			if ($entity instanceof ObjectEntity) {
				return $entity;
			}
		}

		return null;
	}//end resolveFirstEntity()

	/**
	 * Load the decidiq slice of the OR audit trail, ordered oldest-first, as
	 * mapped decidiq audit rows.
	 *
	 * @param string|null $action Optional decidiq action to narrow the read to
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadChain(?string $action = null): array {
		$actions = self::ACTIONS;
		if ($action !== null && in_array($action, self::ACTIONS, true) === true) {
			$actions = [$action];
		}

		$namespaced = array_map(
			static fn (string $name): string => (self::ACTION_NAMESPACE . $name),
			$actions
		);

		$rows = $this->auditTrailMapper->findAll(
			limit: self::CHAIN_LIMIT,
			offset: 0,
			filters: ['action' => implode(',', $namespaced)],
			sort: ['created' => 'ASC']
		);

		$out = [];
		foreach ((array)$rows as $row) {
			if ($row instanceof AuditTrail) {
				$out[] = $this->mapRow(entry: $row);
			}
		}

		return $out;
	}//end loadChain()

	/**
	 * Map an OR AuditTrail entity onto the decidiq audit row shape.
	 *
	 * The decidiq context written by append() (actorUuid, objectUids,
	 * payload) is read back out of the `changed` column; the platform's own
	 * chain hash is exposed as `currentHash` (empty until AuditSealJob's next
	 * sweep seals the row).
	 *
	 * @param AuditTrail $entry The OR audit trail entity
	 *
	 * @return array<string, mixed> The mapped decidiq audit row
	 */
	private function mapRow(AuditTrail $entry): array {
		$changed = ($entry->getChanged() ?? []);
		if (is_array($changed) === false) {
			$changed = [];
		}

		$action = (string)($entry->getAction() ?? '');
		if (str_starts_with($action, self::ACTION_NAMESPACE) === true) {
			$action = substr($action, strlen(self::ACTION_NAMESPACE));
		}

		$objectUids = ($changed['objectUids'] ?? null);
		if (is_array($objectUids) === false || $objectUids === []) {
			$objectUids = array_values(array_filter([(string)($entry->getObjectUuid() ?? '')]));
		}

		$timestamp = '';
		$created = $entry->getCreated();
		if ($created !== null) {
			$timestamp = $created->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
		}

		return [
			'id' => (string)($entry->getUuid() ?? $entry->getId()),
			'actorUuid' => (string)($changed['actorUuid'] ?? ($entry->getUser() ?? '')),
			'action' => $action,
			'objectUids' => array_values(array_map('strval', $objectUids)),
			'timestamp' => $timestamp,
			'payload' => (array)($changed['payload'] ?? []),
			'currentHash' => (string)($entry->getHash() ?? ''),
			'previousHash' => (string)($entry->getPreviousHash() ?? ''),
		];
	}//end mapRow()
}//end class
