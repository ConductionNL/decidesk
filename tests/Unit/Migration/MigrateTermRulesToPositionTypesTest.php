<?php

/**
 * Unit tests for MigrateTermRulesToPositionTypes.
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

use OCA\Decidiq\Migration\MigrateTermRulesToPositionTypes;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The move of term rules onto the positions they govern.
 *
 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md
 */
class MigrateTermRulesToPositionTypesTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateTermRulesToPositionTypes $migration;

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

		$this->migration = new MigrateTermRulesToPositionTypes(
			$this->settingsService,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * A term rule becomes a named position carrying the same rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md#requirement-term-rules-are-carried-across
	 */
	public function testATermRuleBecomesANamedPosition(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			rules: [
				[
					'id' => 'tr-1',
					'body' => 'gemeenteraad',
					'role' => 'vice-chair',
					'termDurationMonths' => 48,
					'maxConsecutivePeriods' => 2,
					'notes' => 'Statuten art. 12.',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$types = $service->savedFor('position-type');
		self::assertCount(expectedCount: 1, haystack: $types);

		$type = $types[0];
		// 🔑 THE ROLE ENUM BECOMES A NAME. A position type is named; a term rule
		// was keyed on one of seven fixed roles, which is the vocabulary this
		// change removes.
		self::assertSame(expected: 'Vice-chair', actual: $type['name']);
		self::assertSame(expected: 'uuid-of-gemeenteraad', actual: $type['governanceBody']);
		self::assertSame(expected: 48, actual: $type['termDurationMonths']);
		// The same number under a different name; nothing about the rule changes.
		self::assertSame(expected: 2, actual: $type['maxConsecutiveTerms']);
		self::assertArrayNotHasKey(key: 'maxConsecutivePeriods', array: $type);
		self::assertSame(expected: 'Statuten art. 12.', actual: $type['notes']);
		self::assertSame(expected: 'tr-1', actual: $type['migratedFromObject']);

	}//end testATermRuleBecomesANamedPosition()

	/**
	 * A body-wide rule naming no role becomes the position every body has.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md#requirement-term-rules-are-carried-across
	 */
	public function testABodyWideRuleBecomesTheMemberPosition(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			rules: [['id' => 'tr-1', 'body' => 'raad', 'termDurationMonths' => 48]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertSame(
			expected: 'Member',
			actual: $service->savedFor('position-type')[0]['name'],
		);

	}//end testABodyWideRuleBecomesTheMemberPosition()

	/**
	 * A rule naming no body is skipped rather than bound to nothing.
	 *
	 * 🔴 `governanceBody` IS REQUIRED, and a position belonging to no body is
	 * not a position.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md#requirement-term-rules-are-carried-across
	 */
	public function testARuleWithNoBodyIsSkipped(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			rules: [['id' => 'tr-1', 'role' => 'chair', 'termDurationMonths' => 48]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('position-type'));

	}//end testARuleWithNoBodyIsSkipped()

	/**
	 * The projection is never promoted to source data.
	 *
	 * 🔴 THIS IS THE POINT OF THE CHANGE. rooster-regel rows recorded only the
	 * END of a term, and position-hold requires a start date, so any hold written
	 * from one would carry an invented date that looks authoritative. The
	 * migration must write NO position holds at all, however many rooster rows
	 * it can see.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md#requirement-the-projection-is-not-promoted-to-source-data
	 */
	public function testNoPositionHoldIsInventedFromTheProjection(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			rules: [['id' => 'tr-1', 'body' => 'raad', 'role' => 'chair']],
			roosterRegels: [
				['id' => 'rr-1', 'personName' => 'A. Bakker', 'role' => 'chair', 'termNumber' => 2, 'endTermDate' => '2028-01-01'],
				['id' => 'rr-2', 'personName' => 'B. de Vries', 'role' => 'member', 'termNumber' => 1, 'endTermDate' => '2027-01-01'],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('position-hold'));

	}//end testNoPositionHoldIsInventedFromTheProjection()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md#requirement-term-rules-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			rules: [['id' => 'tr-1', 'body' => 'raad', 'role' => 'chair']],
			existingTypes: [['id' => 'pt-1', 'migratedFromObject' => 'tr-1']],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('position-type'));

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
	 * @param array<int,array<string,mixed>> $rules         Legacy term rules.
	 * @param array<int,array<string,mixed>> $roosterRegels Legacy projection rows.
	 * @param array<int,array<string,mixed>> $existingTypes Position types already copied.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $rules = [],
		array $roosterRegels = [],
		array $existingTypes = [],
	): object {
		return new class($rules, $roosterRegels, $existingTypes) {
			/**
			 * The schema currently selected.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Saves, as [schema, payload] pairs.
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>}>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $rules         Legacy term rules.
			 * @param array<int,array<string,mixed>> $roosterRegels Legacy projection rows.
			 * @param array<int,array<string,mixed>> $existingTypes Position types already copied.
			 *
			 * @return void
			 */
			public function __construct(
				private array $rules,
				private array $roosterRegels,
				private array $existingTypes,
			) {
			}//end __construct()

			/**
			 * Payloads saved for one schema.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return array<int,array<string,mixed>> The payloads.
			 */
			public function savedFor(string $schema): array {
				$out = [];
				foreach ($this->saves as $save) {
					if ($save[0] === $schema) {
						$out[] = $save[1];
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

				$saved = [];
				foreach ($this->saves as $save) {
					if ($save[0] === $this->currentSchema) {
						$saved[] = $save[1];
					}
				}

				return match ($this->currentSchema) {
					'termijn-regeling' => $this->rules,
					// Visible on purpose: the migration must be able to SEE the
					// projection and still write nothing from it.
					'rooster-regel' => $this->roosterRegels,
					'position-type' => array_merge($this->existingTypes, $saved),
					default => [],
				};

			}//end findAll()

			/**
			 * Record a save.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The payload.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				$this->saves[] = [$schema, $object];

				return ($object + ['id' => $schema . '-' . count($this->saves)]);

			}//end saveObject()
		};

	}//end makeObjectService()
}//end class
