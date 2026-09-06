<?php

/**
 * Unit tests for AuditLogService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Service\AuditLogService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AuditHashService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AuditLogService consuming OR's audit-trail surface.
 *
 * The service used to save into an app-local `audit-trail` schema that NO
 * register carries ("Schema slug audit-trail is not carried by register
 * decidiq"), so every governance action silently produced no audit row.
 * These tests pin the two properties the fix must hold: a write lands as an
 * OR audit entry that can be read back (verifiable), and a broken audit
 * surface fails LOUDLY (error log + success=false), never silently.
 *
 * @covers \OCA\Decidiq\Service\AuditLogService
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
class AuditLogServiceTest extends TestCase {

	/**
	 * Entries "persisted" by the in-memory mapper double.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $trail = [];

	/**
	 * Mock logger, exposed so tests can assert loud failures.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock OR chain-verification service.
	 *
	 * @var AuditHashService&MockObject
	 */
	private AuditHashService&MockObject $hashService;

	/**
	 * Set up per-test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->trail = [];
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->hashService = $this->createMock(AuditHashService::class);

	}//end setUp()

	/**
	 * Build the service against an object service that resolves the given
	 * uids and an in-memory mapper that stores entries in $this->trail.
	 *
	 * The mapper double implements the exact production signatures (named
	 * arguments included) — a double with looser parameter names would go
	 * green on calls production rejects (#399).
	 *
	 * @param string[] $resolvableUids Object uids that resolve to an ObjectEntity
	 *
	 * @return AuditLogService
	 */
	private function makeService(array $resolvableUids = []): AuditLogService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($resolvableUids): ?ObjectEntity {
				if (in_array((string)$id, $resolvableUids, true) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				return $entity;
			}
		);

		$trail = &$this->trail;
		$mapper = new class($trail) extends AuditTrailMapper {
			/**
			 * @param array<int, AuditTrail> $trail Shared entry store
			 */
			public function __construct(private array &$trail) {
			}

			/**
			 * In-memory createAuditTrailEntry (production signature).
			 *
			 * @param ObjectEntity $object The object the entry relates to
			 * @param string $action The namespaced action string
			 * @param array $context Context payload
			 *
			 * @return AuditTrail
			 */
			public function createAuditTrailEntry(ObjectEntity $object, string $action, array $context = [],): AuditTrail {
				$entry = new AuditTrail();
				$entry->setId(count($this->trail) + 1);
				$entry->setUuid('trail-' . (count($this->trail) + 1));
				$entry->setAction($action);
				$entry->setChanged($context);
				$entry->setUser('session-user');
				$entry->setCreated(new DateTime('2026-09-01T10:0' . count($this->trail) . ':00Z'));
				$this->trail[] = $entry;
				return $entry;
			}

			/**
			 * In-memory findAll honouring the comma-separated action filter,
			 * the uuid filter and created-order (production signature).
			 *
			 * @param int|null $limit Maximum rows
			 * @param int|null $offset Pagination offset
			 * @param array|null $filters Column equality filters
			 * @param array|null $sort Column => direction map
			 * @param string|null $search LIKE search
			 *
			 * @return array
			 */
			public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = ['created' => 'DESC'], ?string $search = null,): array {
				$rows = $this->trail;

				$actionFilter = ($filters['action'] ?? null);
				if ($actionFilter !== null) {
					$allowed = array_map('trim', explode(',', (string)$actionFilter));
					$rows = array_values(
						array_filter(
							$rows,
							static fn (AuditTrail $row): bool => in_array((string)$row->getAction(), $allowed, true)
						)
					);
				}

				$uuidFilter = ($filters['uuid'] ?? null);
				if ($uuidFilter !== null) {
					$rows = array_values(
						array_filter(
							$rows,
							static fn (AuditTrail $row): bool => ((string)$row->getUuid() === (string)$uuidFilter)
						)
					);
				}

				if (strtoupper((string)($sort['created'] ?? 'DESC')) === 'DESC') {
					$rows = array_reverse($rows);
				}

				if ($limit !== null) {
					$rows = array_slice($rows, (int)$offset, $limit);
				}

				return $rows;
			}
		};

		return new AuditLogService(
			logger: $this->logger,
			objectService: $objectService,
			auditTrailMapper: $mapper,
			auditHashService: $this->hashService,
		);
	}//end makeService()

	/**
	 * A decision transition append lands as a namespaced OR audit entry and
	 * can be read back through query() — the verifiable audit row a
	 * transition must produce.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testAppendLandsAsNamespacedOrAuditEntryAndIsQueryable(): void {
		$service = $this->makeService(resolvableUids: ['dec-1']);

		$result = $service->append(
			actor: 'alice',
			action: 'decision-transition',
			objectUids: ['dec-1'],
			payload: ['transition' => 'decide', 'from' => 'deliberating', 'to' => 'decided']
		);

		self::assertTrue($result['success']);
		self::assertCount(1, $this->trail);
		self::assertSame('decidiq.audit.decision-transition', $this->trail[0]->getAction());
		self::assertSame(
			['actorUuid' => 'alice', 'objectUids' => ['dec-1'], 'payload' => ['transition' => 'decide', 'from' => 'deliberating', 'to' => 'decided']],
			$this->trail[0]->getChanged()
		);

		$query = $service->query(['objectUuid' => 'dec-1']);
		self::assertTrue($query['success']);
		self::assertCount(1, $query['entries']);
		self::assertSame('decision-transition', $query['entries'][0]['action']);
		self::assertSame('alice', $query['entries'][0]['actorUuid']);
		self::assertSame(['dec-1'], $query['entries'][0]['objectUids']);

	}//end testAppendLandsAsNamespacedOrAuditEntryAndIsQueryable()

	/**
	 * An unknown action is still refused before anything is written.
	 *
	 * @return void
	 */
	public function testAppendRejectsUnknownAction(): void {
		$service = $this->makeService(resolvableUids: ['dec-1']);

		$result = $service->append(actor: 'alice', action: 'no-such-action', objectUids: ['dec-1']);

		self::assertFalse($result['success']);
		self::assertStringContainsString('Unknown action', $result['message']);
		self::assertCount(0, $this->trail);

	}//end testAppendRejectsUnknownAction()

	/**
	 * The three action names whose callers were silently rejected for the
	 * whole life of the old chain (integration-create, integration-subscribe
	 * and the retention purge) are members of the vocabulary now.
	 *
	 * @return void
	 */
	public function testPreviouslyRejectedCallerActionsAreAccepted(): void {
		foreach (['integration-create', 'integration-subscribe', 'transcript-retention-purge'] as $action) {
			$this->trail = [];
			$service = $this->makeService(resolvableUids: ['obj-1']);

			$result = $service->append(actor: 'alice', action: $action, objectUids: ['obj-1']);

			self::assertTrue($result['success'], "Action '{$action}' must be appendable");
			self::assertSame('decidiq.audit.' . $action, $this->trail[0]->getAction());
		}
	}//end testPreviouslyRejectedCallerActionsAreAccepted()

	/**
	 * When the audit surface throws (the failure mode observed live: the
	 * write target does not exist), append() logs at ERROR level and returns
	 * success=false — loud, never a silent no-op.
	 *
	 * @return void
	 */
	public function testAppendFailsLoudlyWhenTheAuditSurfaceThrows(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($this->createMock(ObjectEntity::class));

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willThrowException(
			new \RuntimeException('audit surface unavailable')
		);

		$this->logger->expects(self::once())->method('error');

		$service = new AuditLogService(
			logger: $this->logger,
			objectService: $objectService,
			auditTrailMapper: $mapper,
			auditHashService: $this->hashService,
		);

		$result = $service->append(actor: 'alice', action: 'decision-transition', objectUids: ['dec-1']);

		self::assertFalse($result['success']);
		self::assertStringContainsString('audit surface unavailable', $result['message']);

	}//end testAppendFailsLoudlyWhenTheAuditSurfaceThrows()

	/**
	 * When no referenced uid resolves to an OR object there is nothing to
	 * attach the entry to: error log + failure, not a dropped row.
	 *
	 * @return void
	 */
	public function testAppendFailsLoudlyWhenNoObjectResolves(): void {
		$this->logger->expects(self::once())->method('error');

		$service = $this->makeService(resolvableUids: []);

		$result = $service->append(actor: 'alice', action: 'decision-transition', objectUids: ['ghost-1']);

		self::assertFalse($result['success']);
		self::assertCount(0, $this->trail);

	}//end testAppendFailsLoudlyWhenNoObjectResolves()

	/**
	 * An append with several uids attaches the entry to the first uid that
	 * resolves and keeps the full list in the entry context (the regulator
	 * export passes a checksum stand-in that is not an OR object).
	 *
	 * @return void
	 */
	public function testAppendAttachesToFirstResolvableUid(): void {
		$service = $this->makeService(resolvableUids: ['board-1']);

		$result = $service->append(
			actor: 'alice',
			action: 'material-access',
			objectUids: ['board-1', 'sha256-checksum-not-an-object']
		);

		self::assertTrue($result['success']);
		self::assertCount(1, $this->trail);
		self::assertSame(
			['board-1', 'sha256-checksum-not-an-object'],
			$this->trail[0]->getChanged()['objectUids']
		);

	}//end testAppendAttachesToFirstResolvableUid()

	/**
	 * query() narrows to the decidiq action namespace and applies the
	 * actor/action filters on the mapped rows.
	 *
	 * @return void
	 */
	public function testQueryFiltersByActorAndAction(): void {
		$service = $this->makeService(resolvableUids: ['dec-1', 'proxy-1']);

		$service->append(actor: 'alice', action: 'decision-transition', objectUids: ['dec-1']);
		$service->append(actor: 'bob', action: 'proxy-created', objectUids: ['proxy-1']);

		$result = $service->query(['actor' => 'bob', 'action' => 'proxy-created']);

		self::assertTrue($result['success']);
		self::assertCount(1, $result['entries']);
		self::assertSame('proxy-created', $result['entries'][0]['action']);
		self::assertSame('bob', $result['entries'][0]['actorUuid']);

	}//end testQueryFiltersByActorAndAction()

	/**
	 * export() produces the CSV header and one line per entry in range.
	 *
	 * @return void
	 */
	public function testExportCsvIncludesHeader(): void {
		$service = $this->makeService(resolvableUids: ['dec-1']);
		$service->append(actor: 'alice', action: 'decision-transition', objectUids: ['dec-1']);

		$result = $service->export('2026-01-01T00:00:00Z', '2026-12-31T23:59:59Z', 'csv');

		self::assertTrue($result['success']);
		self::assertSame(1, $result['count']);
		$lines = explode("\n", $result['body']);
		self::assertSame('id,timestamp,actor,action,objectUids,previousHash,currentHash', $lines[0]);
		self::assertStringContainsString('decision-transition', $lines[1]);

	}//end testExportCsvIncludesHeader()

	/**
	 * verify() delegates to the platform chain verification and maps its
	 * result onto the decidiq shape.
	 *
	 * @return void
	 */
	public function testVerifyDelegatesToPlatformChain(): void {
		$this->hashService->method('verifyChain')->willReturn(
			[
				'valid' => true,
				'entriesVerified' => 42,
				'brokenAt' => null,
				'skippedNullHashes' => 0,
				'purgedTombstones' => 0,
			]
		);

		$service = $this->makeService();

		$result = $service->verify();

		self::assertTrue($result['valid']);
		self::assertSame(42, $result['checked']);
		self::assertSame([], $result['tampered']);

	}//end testVerifyDelegatesToPlatformChain()

	/**
	 * A broken platform chain surfaces as invalid with the breaking row named.
	 *
	 * @return void
	 */
	public function testVerifyReportsBrokenChain(): void {
		$this->hashService->method('verifyChain')->willReturn(
			[
				'valid' => false,
				'entriesVerified' => 10,
				'brokenAt' => 7,
				'skippedNullHashes' => 0,
				'purgedTombstones' => 0,
			]
		);

		$service = $this->makeService();

		$result = $service->verify();

		self::assertFalse($result['valid']);
		self::assertSame(10, $result['checked']);
		self::assertSame(['7'], $result['tampered']);

	}//end testVerifyReportsBrokenChain()

	/**
	 * verify() with an entry uuid resolves the entry's row id and bounds the
	 * platform verification to it; an unknown uuid is invalid without ever
	 * calling the platform.
	 *
	 * @return void
	 */
	public function testVerifyWithEntryUuidBoundsTheChainWalk(): void {
		$service = $this->makeService(resolvableUids: ['dec-1']);
		$service->append(actor: 'alice', action: 'decision-transition', objectUids: ['dec-1']);

		$this->hashService->expects(self::once())
			->method('verifyChain')
			->with(null, 1)
			->willReturn(
				[
					'valid' => true,
					'entriesVerified' => 1,
					'brokenAt' => null,
					'skippedNullHashes' => 0,
					'purgedTombstones' => 0,
				]
			);

		$result = $service->verify('trail-1');
		self::assertTrue($result['valid']);

		$unknown = $service->verify('trail-does-not-exist');
		self::assertFalse($unknown['valid']);
		self::assertSame(0, $unknown['checked']);

	}//end testVerifyWithEntryUuidBoundsTheChainWalk()
}//end class
