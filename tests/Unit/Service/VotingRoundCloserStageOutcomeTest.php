<?php

/**
 * The vote-method DecisionStage outcome writer.
 *
 * `DecisionStage.outcome` used to be declared as an x-openregister-calculations
 * entry with a `switch` operator, which is not an operator, so the field simply
 * never had a value. No configuration of that annotation could have worked
 * either: a virtual calculation named after a stored property replaces it on
 * every read and cannot read it back, a materialised one would clear the
 * outcome every other method writes directly, and the virtual read path
 * resolves neither `@ref` nor `@aggregate`, so it never sees VotingRound.result
 * across the relation. The round still decides; VotingRoundCloser now records
 * what it decided.
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
 * @spec openspec/specs/decision-methods/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Asserts the closed round records its stage outcome, and only when it may.
 *
 * @spec openspec/specs/decision-methods/spec.md
 */
class VotingRoundCloserStageOutcomeTest extends TestCase {

	/**
	 * Wrap a plain array as an ObjectEntity double.
	 *
	 * @param array<string, mixed> $data The object payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * Build a closer over a fixture store, capturing every save.
	 *
	 * @param array<string, array<string, mixed>> $objects Fixture rows, keyed by UUID
	 * @param array<int, array<string, mixed>> &$saved Captured saveObject() payloads
	 *
	 * @return VotingRoundCloser
	 */
	private function buildCloser(array $objects, array &$saved): VotingRoundCloser {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($objects): ?ObjectEntity {
				if (isset($objects[(string)$id]) === false) {
					return null;
				}

				return $this->entity($objects[(string)$id]);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object) use (&$saved): ObjectEntity {
				$data = $object;
				if ($object instanceof ObjectEntity) {
					$data = $object->getObject();
				}

				$saved[] = $data;

				return $this->entity($data);
			}
		);

		$motionService = $this->createMock(MotionService::class);

		return new VotingRoundCloser(
			logger: new NullLogger(),
			oriService: $this->createMock(OriPublicationService::class),
			motionService: $motionService,
			amendmentOrder: new AmendmentOrderService(
				motionService: $motionService,
				objectService: $this->createMock(ObjectServiceInterface::class),
			),
			relationFilter: new ObjectRelationFilter(),
			fileService: $this->createMock(FileService::class),
			objectService: $objectService,
		);
	}//end buildCloser()

	/**
	 * The fixture pair: one round linked to one stage in the given status.
	 *
	 * @param string $stageStatus The stage's lifecycle state
	 *
	 * @return array<string, array<string, mixed>> Fixture rows keyed by UUID
	 */
	private function fixture(string $stageStatus): array {
		return [
			'round-1' => [
				'id' => 'round-1',
				'closedAt' => '2026-09-01T10:00:00+00:00',
				'decisionStage' => 'stage-1',
			],
			'stage-1' => [
				'id' => 'stage-1',
				'method' => 'vote',
				'status' => $stageStatus,
				'outcome' => null,
				'votingRound' => 'round-1',
			],
		];
	}//end fixture()

	/**
	 * Every round result maps to the outcome the spec names.
	 *
	 * @param string $result The round's computed result
	 * @param string|null $expected The outcome the stage must end up with, or null for none
	 *
	 * @return void
	 *
	 * @dataProvider resultProvider
	 */
	public function testResultMapsToTheStageOutcome(string $result, ?string $expected): void {
		$saved = [];
		$closer = $this->buildCloser($this->fixture('active'), $saved);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => $result]);

		$stages = array_values(array_filter($saved, static fn (array $row): bool => ($row['id'] ?? '') === 'stage-1'));

		if ($expected === null) {
			self::assertSame(
				expected: [],
				actual: $stages,
				message: 'an ' . $result . ' round resolves no stage, so it must not write one'
			);

			return;
		}

		self::assertCount(
			expectedCount: 1,
			haystack: $stages,
			message: 'a ' . $result . ' round must record its outcome on the stage it resolves'
		);
		self::assertSame(expected: $expected, actual: $stages[0]['outcome']);
		self::assertSame(expected: 'decided', actual: $stages[0]['status']);
		self::assertNotEmpty(actual: $stages[0]['decidedAt'], message: 'a decided stage is stamped');
	}//end testResultMapsToTheStageOutcome()

	/**
	 * The result-to-outcome map, per decision-methods.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function resultProvider(): array {
		return [
			'adopted round adopts the stage' => ['adopted', 'adopted'],
			'rejected round rejects the stage' => ['rejected', 'rejected'],
			'a tie rejects the stage' => ['tied', 'rejected'],
			'an invalid round decides nothing' => ['invalid', null],
		];
	}//end resultProvider()

	/**
	 * A stage the route never activated is not this round's to conclude.
	 *
	 * `pending -> decided` is not in the stage's declared lifecycle, so writing
	 * it would be refused at the register anyway. The round stays closed.
	 *
	 * @return void
	 */
	public function testAPendingStageIsLeftAlone(): void {
		$saved = [];
		$closer = $this->buildCloser($this->fixture('pending'), $saved);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);

		$stages = array_filter($saved, static fn (array $row): bool => ($row['id'] ?? '') === 'stage-1');
		self::assertSame(
			expected: [],
			actual: array_values($stages),
			message: 'pending -> decided is not a declared transition, so the stage must not be written'
		);
	}//end testAPendingStageIsLeftAlone()

	/**
	 * A round that resolves no stage writes nothing extra.
	 *
	 * @return void
	 */
	public function testARoundWithNoStageWritesNothing(): void {
		$objects = $this->fixture('active');
		unset($objects['round-1']['decisionStage']);

		$saved = [];
		$closer = $this->buildCloser($objects, $saved);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);

		$stages = array_filter($saved, static fn (array $row): bool => ($row['id'] ?? '') === 'stage-1');
		self::assertSame(expected: [], actual: array_values($stages));
	}//end testARoundWithNoStageWritesNothing()
}//end class
