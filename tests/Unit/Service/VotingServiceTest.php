<?php

/**
 * Unit tests for VotingService.
 *
 * Runs the real voting graph (opener, caster, closer, results) over an
 * in-memory double of OpenRegister's published ObjectServiceInterface
 * (ADR-084), the same double VotingServiceCastAsTest uses. Only storage, the
 * participant resolver, the motion service and ORI publication are doubled.
 *
 * This class was unconditionally markTestSkipped() for issue #90 ("real
 * OpenRegister ObjectService loads instead of the stub"). That cause is gone:
 * the services take the contract interface, the same class with or without
 * OpenRegister installed. What the skip had been hiding since was a suite
 * written against a service that no longer exists: every test configured
 * `getObject()` to return a raw array and `findObjects()`, which is not on the
 * contract at all, so not one of them reached the code it names.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\Decidiq\Service\VotingOpenedNotifier;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\Decidiq\Service\VotingRoundProjection;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for VotingService.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
 */
class VotingServiceTest extends TestCase {

	/**
	 * Captured saveObject() calls, in order.
	 *
	 * @var \ArrayObject<int, array{schema: string, object: array<string, mixed>}>
	 */
	private \ArrayObject $saves;

	/**
	 * Mock MotionService (shared so tests can assert the subject transition).
	 *
	 * @var MotionService&MockObject
	 */
	private MotionService&MockObject $motionService;

	/**
	 * Build a VotingService over an in-memory object store double.
	 *
	 * The store maps object id => ['schema' => ..., 'object' => [...]]. find()
	 * resolves by id (and schema, when one is passed); findAll() filters the
	 * currently selected schema on plain field equality, treating
	 * `_relations.*` keys as presence-only the way OpenRegister does;
	 * saveObject() records the payload and upserts the store.
	 *
	 * @param array<string, array{schema: string, object: array<string, mixed>}> $store Seed objects by id
	 * @param array<int, array<string, mixed>> $meetingParticipants What ParticipantResolver returns for any meeting
	 *
	 * @return VotingService
	 */
	private function buildService(array $store, array $meetingParticipants = []): VotingService {
		$this->saves = new \ArrayObject();
		$objectService = $this->makeObjectService(store: new \ArrayObject($store), saves: $this->saves);

		$this->motionService = $this->createMock(MotionService::class);

		// VoteBallotFactory (behind VoteCastingService) still resolves OpenRegister
		// through the container, so the same double is served both ways.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				// Notification/activity lookups are fail-soft in the service.
				throw new \RuntimeException('not wired in test: ' . $id);
			}
		);

		$participantResolver = $this->createMock(ParticipantResolver::class);
		$participantResolver->method('resolveMeetingParticipants')->willReturn($meetingParticipants);

		$templateService = $this->createMock(ProcessTemplateService::class);
		$templateService->method('resolveVotingRuleForBody')->willReturn(null);

		// VotingService is a thin facade: every operation is delegated to a
		// single-purpose collaborator, so the graph is built explicitly here
		// where production relies on Nextcloud's constructor auto-wiring.
		$logger = new NullLogger();
		$amendmentOrder = new AmendmentOrderService(
			motionService: $this->motionService,
			objectService: $objectService,
		);
		$relationFilter = new ObjectRelationFilter();

		return new VotingService(
			opener: new VotingRoundOpener(
				motionService: $this->motionService,
				participantResolver: $participantResolver,
				preflight: new VotingRoundPreflight(
					logger: $logger,
					motionService: $this->motionService,
					participantResolver: $participantResolver,
					templateService: $templateService,
					objectService: $objectService,
				),
				notifier: new VotingOpenedNotifier(
					logger: $logger,
					participantResolver: $participantResolver,
					container: $container,
				),
				objectService: $objectService,
			),
			caster: new VoteCastingService(
				logger: $logger,
				participantResolver: $participantResolver,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
				objectService: $objectService,
				container: $container,
			),
			closer: new VotingRoundCloser(
				logger: $logger,
				oriService: $this->createMock(OriPublicationService::class),
				motionService: $this->motionService,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
				objectService: $objectService,
				fileService: $this->createMock(FileService::class),
			),
			results: new VotingRoundResults(
				motionService: $this->motionService,
				participantResolver: $participantResolver,
				objectService: $objectService,
			),
			projection: new VotingRoundProjection(
				objectService: $objectService,
			),
			participants: new ParticipantUuidLookup(
				objectService: $objectService,
			),
		);

	}//end buildService()

	/**
	 * Build an in-memory ObjectServiceInterface double over the seeded store.
	 *
	 * @param \ArrayObject $store In-memory object store keyed by id
	 * @param \ArrayObject $saves Captured saveObject() payloads
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function makeObjectService(\ArrayObject $store, \ArrayObject $saves): ObjectServiceInterface {
		$schema = '';
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema, $objectService): ObjectServiceInterface {
				$schema = (string)$slug;
				return $objectService;
			}
		);

		$objectService->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use ($store): ?ObjectEntity {
				$row = ($store[(string)$id] ?? null);
				if ($row === null || ($schema !== null && $row['schema'] !== $schema)) {
					return null;
				}

				return $this->entity($row['object']);
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($store, &$schema): array {
				$out = [];
				foreach ($store as $row) {
					if ($row['schema'] !== $schema) {
						continue;
					}

					foreach (($config['filters'] ?? []) as $key => $value) {
						if (str_starts_with((string)$key, '_relations.') === true) {
							continue;
						}

						if (($row['object'][$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$out[] = $this->entity($row['object']);
				}

				return $out;
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use ($store, $saves): ObjectEntity {
				$saves->append(['schema' => (string)$schema, 'object' => $object]);
				$id = (string)($uuid ?? $object['id'] ?? $object['uuid'] ?? ('new-' . count($saves)));
				$store[$id] = ['schema' => (string)$schema, 'object' => $object];
				return $this->entity($object);
			}
		);

		return $objectService;

	}//end makeObjectService()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it verbatim.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * Captured saves for one schema, in order.
	 *
	 * @param string $schema Schema slug
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savesFor(string $schema): array {
		$out = [];
		foreach ($this->saves as $save) {
			if ($save['schema'] === $schema) {
				$out[] = $save['object'];
			}
		}

		return $out;

	}//end savesFor()

	/**
	 * A meeting requiring the given quorum.
	 *
	 * @param int $quorumRequired The quorum
	 *
	 * @return array<string, array{schema: string, object: array<string, mixed>}>
	 */
	private static function meeting(int $quorumRequired): array {
		return [
			'meeting-uuid' => [
				'schema' => 'meeting',
				'object' => [
					'id' => 'meeting-uuid',
					'quorumRequired' => $quorumRequired,
					'relations' => [['schema' => 'governance-body', 'id' => 'gb-uuid']],
				],
			],
		];

	}//end meeting()

	/**
	 * An open voting round, plus the given ballots cast in it.
	 *
	 * @param array<string, mixed> $roundFields Overrides for the round
	 * @param array<int, array<string, mixed>> $ballots Vote payloads (id is derived when absent)
	 *
	 * @return array<string, array{schema: string, object: array<string, mixed>}>
	 */
	private static function roundWithBallots(array $roundFields = [], array $ballots = []): array {
		$store = [
			'round-uuid' => [
				'schema' => 'voting-round',
				'object' => array_merge(
					[
						'id' => 'round-uuid',
						'openedAt' => '2025-04-14T20:05:00+02:00',
						'closedAt' => null,
						'relations' => [],
						'notes' => [],
					],
					$roundFields
				),
			],
		];

		foreach ($ballots as $i => $ballot) {
			$id = (string)($ballot['id'] ?? ('vote-' . $i));
			$store[$id] = [
				'schema' => 'vote',
				'object' => array_merge(
					[
						'id' => $id,
						'weight' => 1,
						'relations' => [['schema' => 'voting-round', 'id' => 'round-uuid']],
					],
					$ballot
				),
			];
		}

		return $store;

	}//end roundWithBallots()

	/**
	 * Test that checkQuorum returns true when active participants meet the quorum.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCheckQuorumMet(): void {
		$service = $this->buildService(
			self::meeting(3),
			[
				['id' => 'p-a', 'displayName' => 'A', 'leftAt' => null],
				['id' => 'p-b', 'displayName' => 'B', 'leftAt' => null],
				['id' => 'p-c', 'displayName' => 'C', 'leftAt' => null],
			]
		);

		self::assertTrue($service->checkQuorum('meeting-uuid'));

	}//end testCheckQuorumMet()

	/**
	 * Test that checkQuorum returns false when not enough active participants.
	 *
	 * Three participants against a quorum of three, one of whom has left: only
	 * the `leftAt` filter makes this false, so the assertion pins that filter
	 * rather than a head count that could never have reached the quorum.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCheckQuorumNotMet(): void {
		$service = $this->buildService(
			self::meeting(3),
			[
				['id' => 'p-a', 'displayName' => 'A', 'leftAt' => null],
				['id' => 'p-b', 'displayName' => 'B', 'leftAt' => '2025-04-14T20:00:00+02:00'],
				['id' => 'p-c', 'displayName' => 'C', 'leftAt' => null],
			]
		);

		self::assertFalse($service->checkQuorum('meeting-uuid'));

	}//end testCheckQuorumNotMet()

	/**
	 * Test that openVotingRound throws when quorum is not met, and writes nothing.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testOpenVotingRoundBlocksOnQuorumFailure(): void {
		// Only 1 active participant against a quorum of 10.
		$service = $this->buildService(
			self::meeting(10),
			[['id' => 'p-a', 'displayName' => 'A', 'leftAt' => null]]
		);

		$this->motionService->expects($this->never())->method('transitionLifecycle');

		// Caught into a variable rather than asserted inside the catch: PHPUnit's
		// own AssertionFailedError is a RuntimeException, so a self::fail() in the
		// try would be swallowed by this catch.
		$refusal = null;
		try {
			$service->openVotingRound('motion-uuid', 'meeting-uuid', 'for-against-abstain', false, null);
		} catch (\RuntimeException $e) {
			$refusal = $e;
		}

		self::assertNotNull($refusal, 'A round must not open without quorum');
		self::assertSame('Quorum niet bereikt', $refusal->getMessage());

		self::assertCount(0, $this->saves, 'No voting round may be written when quorum fails');

	}//end testOpenVotingRoundBlocksOnQuorumFailure()

	/**
	 * Test that castVote overwrites an existing vote (duplicate update).
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCastVoteOverwritesDuplicate(): void {
		$service = $this->buildService(
			self::roundWithBallots(
				[],
				[
					[
						'id' => 'existing-vote-uuid',
						'value' => 'against',
						'isProxy' => false,
						'relations' => [
							['schema' => 'voting-round', 'id' => 'round-uuid'],
							['schema' => 'participant', 'id' => 'participant-uuid'],
						],
					],
				]
			)
		);

		$result = $service->castVote('round-uuid', 'participant-uuid', 'for', false, null);

		$votes = $this->savesFor('vote');
		self::assertCount(1, $votes);
		self::assertSame('existing-vote-uuid', $votes[0]['id'], 'The existing ballot is updated, not a second one created');
		self::assertSame('for', $votes[0]['value']);
		self::assertSame('for', $result['value']);

	}//end testCastVoteOverwritesDuplicate()

	/**
	 * Test that castVote enforces one-proxy-per-round rule.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testCastVoteEnforcesOneProxyPerRound(): void {
		// The round carries a proxy grant, so authorization passes and the
		// duplicate-proxy check is what decides.
		$service = $this->buildService(
			self::roundWithBallots(
				[
					'notes' => [
						[
							'title' => 'Proxy',
							'body' => json_encode(
								[
									'fromParticipantId' => 'delegator-uuid',
									'toParticipantId' => 'delegate-uuid',
									'votingRoundId' => 'round-uuid',
									'grantedAt' => '2025-04-14T19:00:00+02:00',
								]
							),
						],
					],
				],
				[
					[
						'id' => 'proxy-vote-uuid',
						'value' => 'for',
						'isProxy' => true,
						'relations' => [
							['schema' => 'voting-round', 'id' => 'round-uuid'],
							['schema' => 'participant',  'id' => 'delegator-uuid', 'type' => 'delegator'],
						],
					],
				]
			)
		);

		$refusal = null;
		try {
			$service->castVote('round-uuid', 'delegate-uuid', 'for', true, 'delegator-uuid');
		} catch (\RuntimeException $e) {
			$refusal = $e;
		}

		self::assertNotNull($refusal, 'A second proxy vote for the same delegator must be refused');
		self::assertSame(
			'Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde',
			$refusal->getMessage()
		);

		self::assertCount(0, $this->savesFor('vote'), 'The refused proxy vote is not written');

	}//end testCastVoteEnforcesOneProxyPerRound()

	/**
	 * Test that tallyResults returns 'adopted' when votes for exceed votes against.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testTallyResultsAdopted(): void {
		$service = $this->buildService(
			self::roundWithBallots(
				[],
				[
					['value' => 'for'],
					['value' => 'for'],
					['value' => 'against'],
				]
			)
		);

		$result = $service->tallyResults('round-uuid');

		self::assertSame('adopted', $result['result']);
		self::assertSame(2, $result['votesFor']);
		self::assertSame(1, $result['votesAgainst']);

		// The tally is persisted on the round for the audit trail.
		$rounds = $this->savesFor('voting-round');
		self::assertCount(1, $rounds);
		self::assertSame('adopted', $rounds[0]['result']);

	}//end testTallyResultsAdopted()

	/**
	 * Test that tallyResults returns 'rejected' when votes against exceed votes for.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testTallyResultsRejected(): void {
		$service = $this->buildService(
			self::roundWithBallots(
				[],
				[
					['value' => 'for'],
					['value' => 'against'],
					['value' => 'against'],
				]
			)
		);

		$result = $service->tallyResults('round-uuid');

		self::assertSame('rejected', $result['result']);
		self::assertSame(1, $result['votesFor']);
		self::assertSame(2, $result['votesAgainst']);

	}//end testTallyResultsRejected()

	/**
	 * Test that tallyResults returns 'tied' when votes for equal votes against
	 * AND the round's tie-break rule is one that yields a tie.
	 *
	 * ⚠️ `tieBreakRule` is load-bearing here, not decoration. This test used to
	 * pass a round of `['openedAt' => …]` only and still assert 'tied' — but
	 * with no stored rule VotingResultCalculator falls back to the spec default
	 * `rejected`, so 'tied' is the one answer that round CANNOT produce
	 * (openspec/specs/voting-system/spec.md, "Handle a tie vote": *with
	 * `rejected` (default) the result MUST be "rejected" … with `chair-decides`
	 * or `revote` the result MUST be "tied"*).
	 *
	 * It was never caught because this whole class was markTestSkipped() in
	 * setUp (issue #90), and a skip is not a pass. The same wrong expectation was
	 * copied into tests/e2e/workflows/voting-quorum-workflow.spec.ts, where it
	 * DOES run, and it failed every full-scope run while the production
	 * calculator was correct throughout. The rule was added in c9071f14; this
	 * class now runs, so the corrected expectation is finally executed.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 *
	 * @return void
	 */
	public function testTallyResultsTied(): void {
		$ballots = [
			['value' => 'for'],
			['value' => 'against'],
		];

		$service = $this->buildService(self::roundWithBallots(['tieBreakRule' => 'revote'], $ballots));
		self::assertSame('tied', $service->tallyResults('round-uuid')['result']);

		// The same 1-1 tie with no stored rule takes the spec default and fails.
		$service = $this->buildService(self::roundWithBallots([], $ballots));
		self::assertSame('rejected', $service->tallyResults('round-uuid')['result']);

	}//end testTallyResultsTied()

	/**
	 * Test that closeVotingRound closes the round and triggers motion lifecycle update.
	 *
	 * ADR-005: a closed round produces an OUTCOME, not a lifecycle state. The
	 * motion enters `decided` whether the vote carried or not, and the result
	 * travels as the `outcome` argument.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.5
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testCloseVotingRoundTransitionsLifecycle(): void {
		$service = $this->buildService(
			self::roundWithBallots(
				['relations' => [['schema' => 'motion', 'id' => 'motion-uuid']]],
				[['value' => 'against']]
			)
		);

		$this->motionService->expects($this->once())
			->method('transitionLifecycle')
			->with('motion-uuid', 'motion', 'decided', 'system', 'rejected');

		$closed = $service->closeVotingRound('round-uuid');

		self::assertNotNull($closed['closedAt'] ?? null, 'The round is stamped closed');
		self::assertSame('rejected', $closed['result']);

	}//end testCloseVotingRoundTransitionsLifecycle()

	// The proxy (volmacht) delegation rules moved to ProxyDelegationService
	// together with grantProxy()/revokeProxy(); they are covered by
	// ProxyDelegationServiceTest.
}//end class
