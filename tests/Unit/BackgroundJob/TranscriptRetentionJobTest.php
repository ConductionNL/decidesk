<?php

/**
 * Unit tests for TranscriptRetentionJob.
 *
 * Covers per-policy enforcement after minutes approval: keep (no deletion),
 * delete-recording (recording only), delete-both (recording + transcript), the
 * window not-yet-elapsed case, and the not-yet-approved case.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\TranscriptRetentionJob;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for TranscriptRetentionJob.
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
 */
class TranscriptRetentionJobTest extends TestCase {

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Mock time factory.
	 *
	 * @var ITimeFactory&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ITimeFactory $time;

	/**
	 * Deleted file paths captured by the FileService mock.
	 *
	 * @var string[]
	 */
	private array $deleted = [];

	/**
	 * Saved transcript captured by the ObjectService mock.
	 *
	 * @var array<string,mixed>
	 */
	private array $saved = [];

	/**
	 * Audit entries captured.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $audits = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(time());
		$this->deleted = [];
		$this->saved = [];
		$this->audits = [];

	}//end setUp()

	/**
	 * Build an ObjectService mock + a FileService mock + AuditLogService mock.
	 *
	 * @param string $approvedAt The approved minutes' approvedAt (ISO) or '' for none.
	 *
	 * @return object The ObjectService mock.
	 */
	private function buildObjectService(string $approvedAt): object {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

		$minutes = [];
		if ($approvedAt !== '') {
			$minutes = [['lifecycle' => 'approved', 'approvedAt' => $approvedAt, 'relations' => ['meeting' => 'm1']]];
		}

		$objectService->method('find')->willReturnCallback(
			function (string $id, ?array $extend = null, bool $files = false, $register = null, $schema = null) {
				return match ($schema) {
					'meeting' => $this->entity(['id' => 'm1', 'governanceBody' => 'b1']),
					'governance-body' => $this->entity(
						['id' => 'b1', 'transcriptRetentionPolicy' => 'delete-both', 'transcriptRetentionDays' => 30]
					),
					default => null,
				};
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($minutes): array {
				$schema = $config['schema'] ?? '';
				return match ($schema) {
					'minutes' => $minutes,
					default => [],
				};
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			// saveObject() is typed `: ObjectEntity` in production and can never
			// return the payload array it was handed (#399).
			function (array $object): object {
				$this->saved = $object;
				return $this->entity($object);
			}
		);

		return $objectService;
	}//end buildObjectService()

	/**
	 * Build a FileService mock whose nodes record deletions.
	 *
	 * @return object The FileService mock.
	 */
	private function buildFileService(): object {
		$fileService = $this->getMockBuilder(\stdClass::class)->addMethods(['createFolder'])->getMock();
		$fileService->method('createFolder')->willReturnCallback(
			function () {
				$folder = $this->getMockBuilder(\stdClass::class)->addMethods(['get'])->getMock();
				$folder->method('get')->willReturnCallback(
					function (string $name) {
						$node = $this->getMockBuilder(\stdClass::class)->addMethods(['delete'])->getMock();
						$node->method('delete')->willReturnCallback(
							function () use ($name): void {
								$this->deleted[] = $name;
							}
						);
						return $node;
					}
				);
				return $folder;
			}
		);

		return $fileService;
	}//end buildFileService()

	/**
	 * Build an AuditLogService mock.
	 *
	 * @return object The AuditLogService mock.
	 */
	private function buildAuditLog(): object {
		$audit = $this->getMockBuilder(\stdClass::class)->addMethods(['append'])->getMock();
		$audit->method('append')->willReturnCallback(
			function (string $actor, string $action, array $uids, array $payload = []): array {
				$this->audits[] = ['action' => $action, 'payload' => $payload];
				return [];
			}
		);
		return $audit;
	}//end buildAuditLog()

	/**
	 * ObjectEntity double returning data from jsonSerialize().
	 *
	 * Must be an ObjectEntity double, not a stdClass one: ObjectService::find()
	 * is typed `?ObjectEntity` in production, so a stdClass mock is a value the
	 * service can never hand the code under test (#399).
	 *
	 * @param array<string,mixed> $data Object data.
	 *
	 * @return object
	 */
	private function entity(array $data): object {
		$mock = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$mock->method('jsonSerialize')->willReturn($data);
		return $mock;
	}//end entity()

	/**
	 * Wire the container to return the OR/File/Audit mocks.
	 *
	 * @param object $objectService The ObjectService mock.
	 *
	 * @return void
	 */
	private function wireContainer(object $objectService): void {
		$fileService = $this->buildFileService();
		$audit = $this->buildAuditLog();

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $fileService, $audit) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objectService,
					'OCA\OpenRegister\Service\FileService' => $fileService,
					\OCA\Decidiq\Service\AuditLogService::class => $audit,
					default => throw new \RuntimeException('unexpected ' . $id),
				};
			}
		);

	}//end wireContainer()

	/**
	 * Test delete-both purges recording + transcript and audits after the window.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testDeleteBothPurgesAfterWindow(): void {
		$objectService = $this->buildObjectService(approvedAt: '2020-01-01T00:00:00Z');
		$this->wireContainer(objectService: $objectService);

		$job = new TranscriptRetentionJob($this->time, $this->container, $this->logger);

		$transcript = [
			'id' => 't1',
			'status' => 'done',
			'retentionState' => 'active',
			'sourceFilePath' => 'Decidesk/x/recording.mp3',
			'transcriptFilePath' => 'Decidesk/x/Minutes/transcript-t1.txt',
			'relations' => ['meeting' => 'm1'],
		];

		$state = $job->enforceForTranscript(
			objectService: $objectService,
			transcript: $transcript,
			now: new \DateTimeImmutable('2026-06-15T00:00:00Z')
		);

		self::assertSame('purged', $state);
		self::assertContains('recording.mp3', $this->deleted);
		self::assertContains('transcript-t1.txt', $this->deleted);
		self::assertSame('purged', $this->saved['retentionState']);
		self::assertNotEmpty($this->audits);
		// The action must be a member of the closed audit vocabulary: the job
		// used to send the dotted 'transcript.retention.purge', which
		// AuditLogService::ACTIONS never contained, so the real append was
		// refused with "Unknown action" while this fake accepted anything.
		self::assertContains(
			$this->audits[0]['action'],
			\OCA\Decidiq\Service\AuditLogService::ACTIONS
		);

	}//end testDeleteBothPurgesAfterWindow()

	/**
	 * Test delete-recording removes only the recording.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testDeleteRecordingOnly(): void {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (string $id, ?array $extend = null, bool $files = false, $register = null, $schema = null) {
				return match ($schema) {
					'meeting' => $this->entity(['id' => 'm1', 'governanceBody' => 'b1']),
					'governance-body' => $this->entity(
						['id' => 'b1', 'transcriptRetentionPolicy' => 'delete-recording', 'transcriptRetentionDays' => 30]
					),
					default => null,
				};
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => ($config['schema'] ?? '') === 'minutes'
				? [['lifecycle' => 'approved', 'approvedAt' => '2020-01-01T00:00:00Z', 'relations' => ['meeting' => 'm1']]]
				: []
		);
		$objectService->method('saveObject')->willReturnCallback(
			// saveObject() is typed `: ObjectEntity` in production and can never
			// return the payload array it was handed (#399).
			function (array $object): object {
				$this->saved = $object;
				return $this->entity($object);
			}
		);
		$this->wireContainer(objectService: $objectService);

		$job = new TranscriptRetentionJob($this->time, $this->container, $this->logger);

		$transcript = [
			'id' => 't2',
			'status' => 'done',
			'retentionState' => 'active',
			'sourceFilePath' => 'Decidesk/x/recording.mp3',
			'transcriptFilePath' => 'Decidesk/x/Minutes/transcript-t2.txt',
			'relations' => ['meeting' => 'm1'],
		];

		$state = $job->enforceForTranscript(
			objectService: $objectService,
			transcript: $transcript,
			now: new \DateTimeImmutable('2026-06-15T00:00:00Z')
		);

		self::assertSame('recording-deleted', $state);
		self::assertContains('recording.mp3', $this->deleted);
		self::assertNotContains('transcript-t2.txt', $this->deleted);

	}//end testDeleteRecordingOnly()

	/**
	 * Test keep policy deletes nothing.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testKeepPolicyDeletesNothing(): void {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (string $id, ?array $extend = null, bool $files = false, $register = null, $schema = null) {
				return match ($schema) {
					'meeting' => $this->entity(['id' => 'm1', 'governanceBody' => 'b1']),
					'governance-body' => $this->entity(['id' => 'b1', 'transcriptRetentionPolicy' => 'keep']),
					default => null,
				};
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => ($config['schema'] ?? '') === 'minutes'
				? [['lifecycle' => 'approved', 'approvedAt' => '2020-01-01T00:00:00Z', 'relations' => ['meeting' => 'm1']]]
				: []
		);
		// saveObject() is typed `: ObjectEntity` in production and can never
		// return the payload array it was handed (#399).
		$objectService->method('saveObject')->willReturnCallback(fn (array $o): object => $this->entity($o));
		$this->wireContainer(objectService: $objectService);

		$job = new TranscriptRetentionJob($this->time, $this->container, $this->logger);

		$state = $job->enforceForTranscript(
			objectService: $objectService,
			transcript: [
				'id' => 't3',
				'retentionState' => 'active',
				'sourceFilePath' => 'Decidesk/x/recording.mp3',
				'relations' => ['meeting' => 'm1'],
			],
			now: new \DateTimeImmutable('2026-06-15T00:00:00Z')
		);

		self::assertSame('active', $state);
		self::assertSame([], $this->deleted);

	}//end testKeepPolicyDeletesNothing()

	/**
	 * Test that retention is a no-op before the window elapses.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testNoOpBeforeWindow(): void {
		$objectService = $this->buildObjectService(approvedAt: '2026-06-10T00:00:00Z');
		$this->wireContainer(objectService: $objectService);

		$job = new TranscriptRetentionJob($this->time, $this->container, $this->logger);

		// Only 5 days after approval; default window is 30 days.
		$state = $job->enforceForTranscript(
			objectService: $objectService,
			transcript: [
				'id' => 't4',
				'retentionState' => 'active',
				'sourceFilePath' => 'Decidesk/x/recording.mp3',
				'relations' => ['meeting' => 'm1'],
			],
			now: new \DateTimeImmutable('2026-06-15T00:00:00Z')
		);

		self::assertSame('active', $state);
		self::assertSame([], $this->deleted);

	}//end testNoOpBeforeWindow()

	/**
	 * Test that retention is a no-op when minutes are not yet approved.
	 *
	 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
	 *
	 * @return void
	 */
	public function testNoOpWhenMinutesNotApproved(): void {
		$objectService = $this->buildObjectService(approvedAt: '');
		$this->wireContainer(objectService: $objectService);

		$job = new TranscriptRetentionJob($this->time, $this->container, $this->logger);

		$state = $job->enforceForTranscript(
			objectService: $objectService,
			transcript: [
				'id' => 't5',
				'retentionState' => 'active',
				'sourceFilePath' => 'Decidesk/x/recording.mp3',
				'relations' => ['meeting' => 'm1'],
			],
			now: new \DateTimeImmutable('2026-06-15T00:00:00Z')
		);

		self::assertSame('active', $state);
		self::assertSame([], $this->deleted);

	}//end testNoOpWhenMinutesNotApproved()
}//end class
