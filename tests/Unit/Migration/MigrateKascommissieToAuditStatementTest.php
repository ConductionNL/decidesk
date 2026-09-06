<?php

/**
 * Unit tests for MigrateKascommissieToAuditStatement.
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

use OCA\Decidiq\Migration\MigrateKascommissieToAuditStatement;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The copy from `vve-configuration` onto `body-governance-configuration`.
 *
 * @spec openspec/changes/generic-audit-statement/specs/generic-audit-statement/spec.md
 */
class MigrateKascommissieToAuditStatementTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateKascommissieToAuditStatement $migration;

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

		$this->migration = new MigrateKascommissieToAuditStatement(
			$this->settingsService,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * The record survives the rename unchanged; only its schema moves.
	 *
	 * @return void
	 */
	public function testRunCarriesTheStatementAcross(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [
				[
					'id' => 'kv-1',
					'governanceBody' => 'body-1',
					'financialYear' => 2025,
					'verdict' => 'qualified',
					'notes' => 'Twee facturen ontbraken.',
					'agendaItem' => 'agenda-1',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $service->saved);
		$saved = $service->saved[0];
		self::assertSame(expected: 'body-1', actual: $saved['governanceBody']);
		self::assertSame(expected: 2025, actual: $saved['financialYear']);
		self::assertSame(expected: 'qualified', actual: $saved['verdict']);
		self::assertSame(expected: 'Twee facturen ontbraken.', actual: $saved['notes']);
		self::assertSame(expected: 'agenda-1', actual: $saved['agendaItem']);

	}//end testRunCarriesTheStatementAcross()

	/**
	 * A body may file one statement per year, so the SAME year is skipped and a
	 * DIFFERENT year is not.
	 *
	 * 🔴 IDENTITY IS THE PAIR, NOT THE BODY. Keying on the body alone would
	 * silently refuse a body's second year; keying on the source uuid would let
	 * a re-seed duplicate a year that already has one.
	 *
	 * @return void
	 */
	public function testRunKeysOnBodyAndYearTogether(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [['id' => 'as-1', 'governanceBody' => 'body-1', 'financialYear' => 2024]],
			sources: [
				['id' => 'kv-1', 'governanceBody' => 'body-1', 'financialYear' => 2024, 'verdict' => 'approving'],
				['id' => 'kv-2', 'governanceBody' => 'body-1', 'financialYear' => 2025, 'verdict' => 'approving'],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $service->saved);
		self::assertSame(expected: 2025, actual: $service->saved[0]['financialYear']);

	}//end testRunKeysOnBodyAndYearTogether()

	/**
	 * A source with no financial year is skipped: the pair cannot be formed.
	 *
	 * @return void
	 */
	public function testRunSkipsASourceWithNoFinancialYear(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [['id' => 'kv-1', 'governanceBody' => 'body-1', 'verdict' => 'approving']],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->saved);

	}//end testRunSkipsASourceWithNoFinancialYear()

	/**
	 * A source holding a body SLUG does not duplicate a statement stored
	 * against that body's UUID for the same year.
	 *
	 * @return void
	 */
	public function testRunResolvesTheBodySlugBeforeComparing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [['id' => 'as-1', 'governanceBody' => 'uuid-1', 'financialYear' => 2025]],
			sources: [['id' => 'kv-1', 'governanceBody' => 'vve-parkstaete', 'financialYear' => 2025, 'verdict' => 'approving']],
			throwForSchemas: [],
			bodiesBySlug: ['vve-parkstaete' => 'uuid-1'],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->saved);

	}//end testRunResolvesTheBodySlugBeforeComparing()

	/**
	 * A source naming no body is skipped rather than saved without one.
	 *
	 * Both shipped kascommissie seeds carried exactly this: a null-UUID body.
	 *
	 * @return void
	 */
	public function testRunSkipsASourceThatNamesNoBody(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			existing: [],
			sources: [['id' => 'kv-1', 'governanceBody' => '', 'financialYear' => 2025, 'verdict' => 'approving']],
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
			sources: [['id' => 'kv-1', 'governanceBody' => 'body-1', 'financialYear' => 2025, 'verdict' => 'approving']],
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

				if ($this->currentSchema === 'audit-statement') {
					return $this->existing;
				}

				if ($this->currentSchema === 'kascommissie-verklaring') {
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
