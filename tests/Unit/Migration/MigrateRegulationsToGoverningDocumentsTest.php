<?php

/**
 * Unit tests for MigrateRegulationsToGoverningDocuments.
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

use OCA\Decidiq\Migration\MigrateRegulationsToGoverningDocuments;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The fold from `regeling` onto `governing-document`.
 *
 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md
 */
class MigrateRegulationsToGoverningDocumentsTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateRegulationsToGoverningDocuments $migration;

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

		$this->migration = new MigrateRegulationsToGoverningDocuments(
			$this->settingsService,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * A regulation becomes a governing document, under generic field names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
	 */
	public function testARegulationBecomesAGoverningDocument(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			regulations: [
				[
					'id' => 'reg-1',
					'type' => 'by-law',
					'citationTitle' => 'Afvalstoffenverordening',
					'officialTitle' => 'Verordening op de inzameling',
					'statutoryBasis' => ['Gemeentewet art. 149'],
					'determiningBody' => 'gemeenteraad',
					'cvdrIdentifier' => 'CVDR641871',
					'currentVersionNumber' => 2,
					'status' => 'in-effect',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$docs = $service->savedFor('governing-document');
		self::assertCount(expectedCount: 1, haystack: $docs);

		$doc = $docs[0];
		self::assertSame(expected: 'Afvalstoffenverordening', actual: $doc['citationTitle']);
		self::assertSame(expected: 'by-law', actual: $doc['type']);
		// 🔴 THE STORED STATUS IS NOT REWRITTEN. `in-effect` and `in-force` mean
		// the same thing, and both are already stored under the two schemas this
		// fold merges. Collapsing them here would rewrite data the change
		// promises not to touch.
		self::assertSame(expected: 'in-effect', actual: $doc['status']);

		// The body reference is RESOLVED, not copied: the target declares
		// `format: uuid` and a seeded row holds a slug.
		self::assertSame(expected: 'uuid-of-gemeenteraad', actual: $doc['governingBody']);

		// 🔑 ONE FIELD CHANGES NAME. `cvdrIdentifier` was named after one
		// national register in one country; the generic schema calls it what it
		// is. Everything else keeps its name.
		self::assertSame(expected: 'CVDR641871', actual: $doc['externalRegisterIdentifier']);
		self::assertArrayNotHasKey(key: 'cvdrIdentifier', array: $doc);
		self::assertSame(expected: 'Verordening op de inzameling', actual: $doc['officialTitle']);
		self::assertSame(expected: ['Gemeentewet art. 149'], actual: $doc['statutoryBasis']);
		self::assertSame(expected: 2, actual: $doc['currentVersionNumber']);
		self::assertSame(expected: 'reg-1', actual: $doc['migratedFromObject']);

	}//end testARegulationBecomesAGoverningDocument()

	/**
	 * A version points at the document its regulation became.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
	 */
	public function testAVersionPointsAtItsCopiedDocument(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			regulations: [['id' => 'reg-1', 'citationTitle' => 'Een', 'status' => 'in-effect']],
			versions: [
				['id' => 'ver-1', 'regulation' => 'reg-1', 'versionNumber' => 1, 'status' => 'replaced'],
				['id' => 'ver-2', 'regulation' => 'reg-1', 'versionNumber' => 2, 'status' => 'in-force'],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$docId = $service->savedFor('governing-document')[0]['id'] ?? '';
		self::assertNotSame(expected: '', actual: $docId);

		$versions = $service->savedFor('governing-document-versie');
		self::assertCount(expectedCount: 2, haystack: $versions);
		foreach ($versions as $version) {
			// `regulation` is renamed to `document`, and it names the NEW
			// document rather than the retired regulation.
			self::assertSame(expected: $docId, actual: $version['document']);
			self::assertArrayNotHasKey(key: 'regulation', array: $version);
		}

	}//end testAVersionPointsAtItsCopiedDocument()

	/**
	 * A seeded version finds its parent even though it names it by slug.
	 *
	 * 🔑 THE TWO SPELLINGS ARE BOTH REAL. A row created through the app names
	 * its parent by uuid; a SEEDED row names it by slug, because OpenRegister's
	 * importer stores a reference exactly as the file wrote it. Covering only
	 * one leaves the other silently orphaning every version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
	 */
	public function testASeededVersionFindsItsParentBySlug(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			regulations: [['id' => 'uuid-of-afval', 'citationTitle' => 'Afvalstoffenverordening']],
			// The seed wrote the parent's SLUG, not its id.
			versions: [['id' => 'ver-1', 'regulation' => 'afval', 'versionNumber' => 1]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$versions = $service->savedFor('governing-document-versie');
		self::assertCount(expectedCount: 1, haystack: $versions);
		self::assertNotSame(expected: '', actual: (string)$versions[0]['document']);

	}//end testASeededVersionFindsItsParentBySlug()

	/**
	 * A version whose parent was not copied is skipped, not orphaned.
	 *
	 * 🔴 `document` IS REQUIRED. Writing the version anyway would either fail
	 * validation or bind it to nothing, and a version bound to nothing is worse
	 * than one still readable under its original schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
	 */
	public function testAnOrphanVersionIsNotWritten(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			regulations: [],
			versions: [['id' => 'ver-1', 'regulation' => 'reg-gone', 'versionNumber' => 1]],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('governing-document-versie'));

	}//end testAnOrphanVersionIsNotWritten()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			regulations: [['id' => 'reg-1', 'citationTitle' => 'Al gedaan']],
			existingDocuments: [['id' => 'gd-1', 'migratedFromObject' => 'reg-1']],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('governing-document'));

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
	 * @param array<int,array<string,mixed>> $regulations       Legacy regulations.
	 * @param array<int,array<string,mixed>> $versions          Legacy versions.
	 * @param array<int,array<string,mixed>> $existingDocuments Documents already copied.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $regulations = [],
		array $versions = [],
		array $existingDocuments = [],
	): object {
		return new class($regulations, $versions, $existingDocuments) {
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
			 * @param array<int,array<string,mixed>> $regulations       Legacy regulations.
			 * @param array<int,array<string,mixed>> $versions          Legacy versions.
			 * @param array<int,array<string,mixed>> $existingDocuments Documents already copied.
			 *
			 * @return void
			 */
			public function __construct(
				private array $regulations,
				private array $versions,
				private array $existingDocuments,
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
				foreach ($this->saves as $index => $save) {
					if ($save[0] === $schema) {
						$out[] = ($save[1] + ['id' => $schema . '-' . ($index + 1)]);
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
				// A slug lookup goes through `@self`, for any schema: every `$ref`
				// property declares `format: uuid`, so a seeded slug must be
				// resolved before it is written.
				$slug = (string)($filters['filters']['@self']['slug'] ?? '');
				if ($slug !== '') {
					return [['id' => 'uuid-of-' . $slug]];
				}

				$saved = [];
				foreach ($this->saves as $index => $save) {
					if ($save[0] === $this->currentSchema) {
						$saved[] = ($save[1] + ['id' => $this->currentSchema . '-' . ($index + 1)]);
					}
				}

				return match ($this->currentSchema) {
					'regeling' => $this->regulations,
					'regeling-versie' => $this->versions,
					'governing-document' => array_merge($this->existingDocuments, $saved),
					'governing-document-versie' => $saved,
					default => [],
				};

			}//end findAll()

			/**
			 * Record a save, and hand back an object carrying an id.
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
