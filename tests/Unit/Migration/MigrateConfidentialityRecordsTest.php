<?php

/**
 * Unit tests for MigrateConfidentialityRecords.
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

use OCA\Decidiq\Migration\MigrateConfidentialityRecords;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The rename of the confidentiality records into plain words.
 *
 * @spec openspec/changes/confidentiality-in-plain-words/specs/confidentiality-in-plain-words/spec.md
 */
class MigrateConfidentialityRecordsTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateConfidentialityRecords $migration;

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

		$this->migration = new MigrateConfidentialityRecords(
			$this->settingsService,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * A restriction points at the copied ground, not the retired one.
	 *
	 * 🔴 THIS IS THE ONE THAT MATTERS. A restriction copied across still held its
	 * old ground's identifier. Written unchanged it would live on the NEW schema
	 * while pointing at the RETIRED ground: readable, plausible, and joined to
	 * the wrong side of the migration, with nothing in any list to show for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-in-plain-words/specs/confidentiality-in-plain-words/spec.md#requirement-existing-restrictions-are-carried-across
	 */
	public function testARestrictionPointsAtTheCopiedGround(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			grounds: [['id' => 'gg-1', 'name' => 'Bedrijfsgegevens', 'citation' => 'Woo art. 5.1']],
			restrictions: [
				[
					'id' => 'gh-1',
					'scope' => 'document',
					'ground' => 'gg-1',
					'imposedBy' => 'body',
					'imposedByBody' => 'gemeenteraad',
					'imposedAt' => '2026-06-08T10:00:00Z',
					'lifecycle' => 'imposed',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$grounds      = $service->savedFor('confidentiality-ground');
		$restrictions = $service->savedFor('confidentiality-restriction');
		self::assertCount(expectedCount: 1, haystack: $grounds);
		self::assertCount(expectedCount: 1, haystack: $restrictions);

		self::assertSame(expected: $grounds[0]['id'], actual: $restrictions[0]['ground']);
		self::assertNotSame(expected: 'gg-1', actual: $restrictions[0]['ground']);

	}//end testARestrictionPointsAtTheCopiedGround()

	/**
	 * Every value survives the rename.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-in-plain-words/specs/confidentiality-in-plain-words/spec.md#requirement-existing-restrictions-are-carried-across
	 */
	public function testTheValuesSurviveTheRename(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			grounds: [
				[
					'id' => 'gg-1',
					'name' => 'Bedrijfsgegevens',
					'citation' => 'Woo art. 5.1',
					// The retired enum value. It survives BECAUSE the field
					// stopped constraining it, which is the point of the change.
					'category' => 'woo-absolute',
					'requiresRatification' => true,
					'active' => true,
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$ground = $service->savedFor('confidentiality-ground')[0];
		self::assertSame(expected: 'Bedrijfsgegevens', actual: $ground['name']);
		self::assertSame(expected: 'Woo art. 5.1', actual: $ground['citation']);
		self::assertSame(expected: 'woo-absolute', actual: $ground['category']);
		self::assertTrue(condition: $ground['requiresRatification']);
		self::assertSame(expected: 'gg-1', actual: $ground['migratedFromObject']);

	}//end testTheValuesSurviveTheRename()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-in-plain-words/specs/confidentiality-in-plain-words/spec.md#requirement-existing-restrictions-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			grounds: [['id' => 'gg-1', 'name' => 'Al gedaan']],
			existing: ['confidentiality-ground' => [['id' => 'cg-1', 'migratedFromObject' => 'gg-1']]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('confidentiality-ground'));

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
	 * @param array<int,array<string,mixed>>               $grounds      Legacy grounds.
	 * @param array<int,array<string,mixed>>               $restrictions Legacy restrictions.
	 * @param array<string,array<int,array<string,mixed>>> $existing     Rows already copied, by schema.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $grounds = [],
		array $restrictions = [],
		array $existing = [],
	): object {
		return new class($grounds, $restrictions, $existing) {
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
			 * @param array<int,array<string,mixed>>               $grounds      Legacy grounds.
			 * @param array<int,array<string,mixed>>               $restrictions Legacy restrictions.
			 * @param array<string,array<int,array<string,mixed>>> $existing     Rows already copied.
			 *
			 * @return void
			 */
			public function __construct(
				private array $grounds,
				private array $restrictions,
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
					'geheimhouding-grond' => $this->grounds,
					'geheimhouding' => $this->restrictions,
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
