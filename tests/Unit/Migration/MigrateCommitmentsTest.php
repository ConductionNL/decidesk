<?php

/**
 * Unit tests for MigrateCommitments.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Migration;

use OCA\Decidiq\Migration\MigrateCommitments;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The rename of the commitment schema into plain words.
 *
 * @spec openspec/changes/commitment-in-plain-words/specs/commitment-in-plain-words/spec.md
 */
class MigrateCommitmentsTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateCommitments $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container       = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger          = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output          = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigrateCommitments(
			$this->settingsService,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * Every value survives the rename, and every reference is resolved.
	 *
	 * 🔴 THE REFERENCES ARE THE RISK. `directedTo`, `madeBy`, `meeting` and
	 * `agendaItem` all declare `format: uuid`, and a SEEDED row holds a slug. Copy
	 * one across untouched and saveObject() rejects the whole row, which this
	 * step reports as a warning that does not fail an upgrade — so the migration
	 * would say "0 migrated" on exactly the installs it exists for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/commitment-in-plain-words/specs/commitment-in-plain-words/spec.md#requirement-existing-commitments-are-carried-across
	 */
	public function testTheValuesSurviveAndReferencesAreResolved(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			commitments: [
				[
					'id' => 'tz-1',
					'text' => 'Het college stuurt de raad een memo over de parkeertarieven.',
					'madeBy' => 'femke-halsema',
					'meeting' => 'raadsvergadering-2025-01-15',
					'directedTo' => 'gemeenteraad-amsterdam',
					'deadline' => '2026-09-01',
					'lifecycle' => 'open',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$copied = $service->savedFor('governance-commitment');
		self::assertCount(expectedCount: 1, haystack: $copied);

		$commitment = $copied[0];
		self::assertSame(
			expected: 'Het college stuurt de raad een memo over de parkeertarieven.',
			actual: $commitment['text'],
		);
		self::assertSame(expected: 'open', actual: $commitment['lifecycle']);
		self::assertSame(expected: '2026-09-01', actual: $commitment['deadline']);

		// Resolved, not copied.
		self::assertSame(expected: 'uuid-of-femke-halsema', actual: $commitment['madeBy']);
		self::assertSame(expected: 'uuid-of-raadsvergadering-2025-01-15', actual: $commitment['meeting']);
		self::assertSame(expected: 'uuid-of-gemeenteraad-amsterdam', actual: $commitment['directedTo']);
		self::assertSame(expected: 'tz-1', actual: $commitment['migratedFromObject']);

	}//end testTheValuesSurviveAndReferencesAreResolved()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/commitment-in-plain-words/specs/commitment-in-plain-words/spec.md#requirement-existing-commitments-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			commitments: [['id' => 'tz-1', 'text' => 'Al gedaan']],
			existing: ['governance-commitment' => [['id' => 'gc-1', 'migratedFromObject' => 'tz-1']]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('governance-commitment'));

	}//end testASecondRunCopiesNothing()

	/**
	 * Nothing runs when OpenRegister is unavailable.
	 *
	 * @return void
	 *
	 * @spec exclude Guard clause; asserts the migration is inert without OpenRegister.
	 */
	public function testNothingRunsWithoutOpenRegister(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects(self::never())->method('get');

		$this->migration->run(output: $this->output);

	}//end testNothingRunsWithoutOpenRegister()

	/**
	 * A fake ObjectService recording what the migration writes.
	 *
	 * @param array<int,array<string,mixed>>               $commitments Legacy commitments.
	 * @param array<string,array<int,array<string,mixed>>> $existing    Rows already copied, by schema.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $commitments = [],
		array $existing = [],
	): object {
		return new class($commitments, $existing) {
			/**
			 * The schema currently selected.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Saves, as [schema, payload, id] triples.
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>,2:string}>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>>               $commitments Legacy commitments.
			 * @param array<string,array<int,array<string,mixed>>> $existing    Rows already copied.
			 *
			 * @return void
			 */
			public function __construct(
				private array $commitments,
				private array $existing,
			) {
			}//end __construct()

			/**
			 * Payloads saved for one schema, each carrying the id it was given.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return array<int,array<string,mixed>> The payloads.
			 */
			public function savedFor(string $schema): array {
				$out = [];
				foreach ($this->saves as $save) {
					if ($save[0] === $schema) {
						$out[] = ($save[1] + ['id' => $save[2]]);
					}
				}

				return $out;

			}//end savedFor()

			/**
			 * Run an operation as the system user.
			 *
			 * @param callable $operation The operation to run.
			 *
			 * @return mixed The operation's result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();

			}//end runAsSystem()

			/**
			 * Select the register.
			 *
			 * @param string $register The register slug.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
			}//end setRegister()

			/**
			 * Select the schema.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
				$this->currentSchema = $schema;

			}//end setSchema()

			/**
			 * Return the rows for the selected schema.
			 *
			 * @param array<string,mixed> $filters Slug lookups.
			 *
			 * @return array<int,array<string,mixed>> The rows.
			 */
			public function findAll(array $filters = []): array {
				$slug = (string)($filters['filters']['@self']['slug'] ?? '');
				if ($slug !== '') {
					return [['id' => 'uuid-of-' . $slug]];
				}

				return match ($this->currentSchema) {
					'toezegging' => $this->commitments,
					default => array_merge(
						($this->existing[$this->currentSchema] ?? []),
						$this->savedFor($this->currentSchema)
					),
				};

			}//end findAll()

			/**
			 * Record a save, and hand back an object carrying a NEW id.
			 *
			 * The id deliberately does not look like the source id, so a test can
			 * tell a retargeted reference from one that was copied verbatim.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The payload.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				$id = 'new-' . $schema . '-' . (count($this->saves) + 1);
				$this->saves[] = [$schema, $object, $id];

				return ($object + ['id' => $id]);

			}//end saveObject()
		};

	}//end makeObjectService()
}//end class
