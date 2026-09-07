<?php

/**
 * Unit tests for MigrateVveToBodyConfiguration.
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

use OCA\Decidiq\Migration\MigrateVveToBodyConfiguration;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The copy from `vve-configuration` onto `body-governance-configuration`.
 *
 * @spec openspec/changes/generic-body-configuration/specs/generic-body-configuration/spec.md
 */
class MigrateVveToBodyConfigurationTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateVveToBodyConfiguration $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigrateVveToBodyConfiguration(
			$this->settingsService,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * The four facts survive the rename, in generic terms.
	 *
	 * @return void
	 */
	public function testRunMapsEveryFactOntoItsGenericName(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [
				[
					'id' => 'cfg-1',
					'governanceBody' => 'body-1',
					'modelRegulation' => 'modelreglement-2017',
					'modelReglementVersion' => '2017',
					'fractionDenominator' => 10000,
					'deedOfDivisionDocument' => 'splitsingsakte-1',
					'majorityOverrides' => [
						['decisionCategory' => 'discharge', 'voteThreshold' => 'qualified-majority-three-quarters'],
					],
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $service->saved);
		$saved = $service->saved[0];
		self::assertSame(expected: 'body-1', actual: $saved['governanceBody']);
		self::assertSame(expected: 'splitsingsakte-1', actual: $saved['constitutiveDocument']);
		self::assertSame(expected: '2017', actual: $saved['regulationVersion']);
		self::assertSame(expected: 10000, actual: $saved['voteWeightDenominator']);
		self::assertSame(expected: 'discharge', actual: $saved['majorityOverrides'][0]['templateCategory']);

		// 🔴 `modelRegulation` REFERENCED A RETIRED SCHEMA. Carrying it forward
		// would plant a dangling reference to modelreglement-preset, which
		// unified-decision-templates superseded.
		self::assertArrayNotHasKey(key: 'modelRegulation', array: $saved);
		self::assertArrayNotHasKey(key: 'decisionCategory', array: $saved['majorityOverrides'][0]);

	}//end testRunMapsEveryFactOntoItsGenericName()

	/**
	 * A body that already has a configuration does not gain a second one.
	 *
	 * 🔴 IDEMPOTENT BY BODY, NOT BY SOURCE ROW. The schema declares exactly one
	 * configuration per governance body, so the body is the identity. Keying on
	 * the source uuid would let a re-seeded source create a second
	 * configuration — the defect that duplicated every built-in decision
	 * template.
	 *
	 * @return void
	 */
	public function testRunSkipsABodyThatAlreadyHasAConfiguration(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [['id' => 'bgc-1', 'governanceBody' => 'body-1']],
			sources: [['id' => 'cfg-1', 'governanceBody' => 'body-1', 'fractionDenominator' => 10000]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->saved);

	}//end testRunSkipsABodyThatAlreadyHasAConfiguration()

	/**
	 * A source holding a body SLUG does not duplicate a configuration stored
	 * against that body's UUID.
	 *
	 * 🔴 THE BUG THIS EXISTS FOR. The legacy rows hold `governanceBody` as a
	 * slug; the row this migration writes holds the resolved UUID. Comparing
	 * the source's slug against an index of UUIDs never matches, so a second
	 * run created a THIRD configuration for a body that already had one —
	 * measured on a live instance, twice.
	 *
	 * @return void
	 */
	public function testRunDoesNotDuplicateWhenTheSourceHoldsASlugAndTheTargetAUuid(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [['id' => 'bgc-1', 'governanceBody' => '2be0bcfa-4dfb-48a2-a04c-f40164ea9341']],
			sources: [['id' => 'cfg-1', 'governanceBody' => 'vve-parkstaete', 'fractionDenominator' => 10000]],
			throwForSchemas: [],
			bodiesBySlug: ['vve-parkstaete' => '2be0bcfa-4dfb-48a2-a04c-f40164ea9341'],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->saved);

	}//end testRunDoesNotDuplicateWhenTheSourceHoldsASlugAndTheTargetAUuid()

	/**
	 * A body slug is resolved to its UUID before the row is written.
	 *
	 * @return void
	 */
	public function testRunResolvesABodySlugToItsUuid(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [['id' => 'cfg-1', 'governanceBody' => 'vve-parkstaete', 'fractionDenominator' => 10000]],
			throwForSchemas: [],
			bodiesBySlug: ['vve-parkstaete' => '2be0bcfa-4dfb-48a2-a04c-f40164ea9341'],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $service->saved);
		self::assertSame(
			expected: '2be0bcfa-4dfb-48a2-a04c-f40164ea9341',
			actual: $service->saved[0]['governanceBody']
		);

	}//end testRunResolvesABodySlugToItsUuid()

	/**
	 * A null inside a majority override is dropped, not written.
	 *
	 * The validator rejects `quorumFraction: null` with "should be type
	 * 'string' but is 'null'" rather than treating it as unset.
	 *
	 * @return void
	 */
	public function testRunDropsNullsInsideAMajorityOverride(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [
				[
					'id' => 'cfg-1',
					'governanceBody' => '2be0bcfa-4dfb-48a2-a04c-f40164ea9341',
					'majorityOverrides' => [
						['decisionCategory' => 'discharge', 'quorumFraction' => null, 'deedArticle' => 'art. 62'],
					],
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $service->saved);
		self::assertArrayNotHasKey(key: 'quorumFraction', array: $service->saved[0]['majorityOverrides'][0]);
		self::assertSame(expected: 'art. 62', actual: $service->saved[0]['majorityOverrides'][0]['deedArticle']);

	}//end testRunDropsNullsInsideAMajorityOverride()

	/**
	 * Two source rows for one body produce ONE configuration, not two.
	 *
	 * @return void
	 */
	public function testRunCreatesOneConfigurationWhenTwoSourcesShareABody(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [
				['id' => 'cfg-1', 'governanceBody' => 'body-1', 'fractionDenominator' => 100],
				['id' => 'cfg-2', 'governanceBody' => 'body-1', 'fractionDenominator' => 200],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $service->saved);

	}//end testRunCreatesOneConfigurationWhenTwoSourcesShareABody()

	/**
	 * A source row naming no body is skipped rather than saved without one.
	 *
	 * The seeds carried exactly this: `vve-zeewaarts-configuratie` referenced a
	 * null-UUID body that exists nowhere.
	 *
	 * @return void
	 */
	public function testRunSkipsASourceThatNamesNoBody(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [['id' => 'cfg-1', 'governanceBody' => '', 'fractionDenominator' => 10000]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->saved);

	}//end testRunSkipsASourceThatNamesNoBody()

	/**
	 * The migration never deletes or edits a source row.
	 *
	 * @return void
	 */
	public function testRunNeverDeletesASourceRow(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [['id' => 'cfg-1', 'governanceBody' => 'body-1', 'fractionDenominator' => 10000]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertSame(expected: 0, actual: $service->deleteCalls);

	}//end testRunNeverDeletesASourceRow()

	/**
	 * With OpenRegister unavailable the step reports and returns.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$this->migration->run(output: $this->output);

	}//end testRunSkipsWhenOpenRegisterUnavailable()

	/**
	 * A findAll() that throws leaves the step reporting, not raising.
	 *
	 * A repair step that throws fails the whole `occ upgrade`.
	 *
	 * @return void
	 */
	public function testRunDoesNotRaiseWhenTheSourceCannotBeRead(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(existing: [], sources: [], throwForSchemas: ['vve-configuration']);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->saved);

	}//end testRunDoesNotRaiseWhenTheSourceCannotBeRead()

	/**
	 * A recording fake standing in for OpenRegister's ObjectService.
	 *
	 * 🔴 THE PARAMETER NAMES MATCH THE REAL SERVICE. A fake written from the
	 * call site encodes the caller's assumptions rather than the collaborator's
	 * contract, which is how a green suite ships a 500.
	 *
	 * @param array<int,array<string,mixed>> $existing        Existing body-governance-configuration rows.
	 * @param array<int,array<string,mixed>> $sources         Live vve-configuration rows.
	 * @param array<int,string>              $throwForSchemas Schema slugs whose findAll() throws.
	 * @param array<string,string>           $bodiesBySlug    governance-body slug => uuid.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $existing,
		array $sources,
		array $throwForSchemas = [],
		array $bodiesBySlug = [],
	): object {
		return new class($existing, $sources, $throwForSchemas, $bodiesBySlug) {

			/**
			 * Saved objects in call order.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * Number of deleteObject calls.
			 *
			 * @var integer
			 */
			public int $deleteCalls = 0;

			/**
			 * Currently selected schema.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $existing        Existing target rows.
			 * @param array<int,array<string,mixed>> $sources         Live source rows.
			 * @param array<int,string>              $throwForSchemas Schema slugs whose findAll() throws.
			 * @param array<string,string>           $bodiesBySlug    governance-body slug => uuid.
			 */
			public function __construct(
				private readonly array $existing,
				private readonly array $sources,
				private readonly array $throwForSchemas = [],
				private readonly array $bodiesBySlug = [],
			) {
			}//end __construct()

			/**
			 * Run the operation with the system actor.
			 *
			 * The real service elevates here; the migration is only correct
			 * BECAUSE it does, so the fake must offer the seam rather than let
			 * the call fall through.
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
			 * @param array<string,mixed> $filters Ignored.
			 *
			 * @return array<int,array<string,mixed>> The rows.
			 *
			 * @throws \RuntimeException When configured to throw for this schema.
			 */
			public function findAll(array $filters = []): array {
				if (in_array($this->currentSchema, $this->throwForSchemas, true) === true) {
					throw new \RuntimeException('schema not resolvable: ' . $this->currentSchema);
				}

				// The real service resolves a seeded slug through `@self`, which
				// is the whole reason the migration has to resolve BEFORE it
				// compares. A fake that skipped this would let the idempotency
				// bug pass.
				if ($this->currentSchema === 'governance-body') {
					$slug = (string)($filters['filters']['@self']['slug'] ?? '');
					$uuid = ($this->bodiesBySlug[$slug] ?? null);

					return $uuid === null ? [] : [['id' => $uuid]];
				}

				if ($this->currentSchema === 'body-governance-configuration') {
					return $this->existing;
				}

				if ($this->currentSchema === 'vve-configuration') {
					return $this->sources;
				}

				return [];

			}//end findAll()

			/**
			 * Record a save.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The payload.
			 *
			 * @return array<string,mixed> The payload.
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				$this->saved[] = $object;

				return $object;

			}//end saveObject()

			/**
			 * Record a delete.
			 *
			 * @param string $register The register slug.
			 * @param string $schema   The schema slug.
			 * @param string $uuid     The object id.
			 *
			 * @return void
			 */
			public function deleteObject(string $register, string $schema, string $uuid): void {
				$this->deleteCalls++;

			}//end deleteObject()
		};

	}//end makeObjectService()
}//end class
