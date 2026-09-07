<?php

/**
 * Unit tests for MigrateConsultationsToOneSchema.
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

use OCA\Decidiq\Migration\MigrateConsultationsToOneSchema;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The fold of three ask/answer pairs onto one.
 *
 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md
 */
class MigrateConsultationsToOneSchemaTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateConsultationsToOneSchema $migration;

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

		$this->migration = new MigrateConsultationsToOneSchema(
			$this->settingsService,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * An advice request becomes a binding consultation addressed to a body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-existing-consultations-are-carried-across
	 */
	public function testAnAdviceRequestBecomesABindingConsultation(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			adviceRequests: [
				[
					'id' => 'ar-1',
					'subject' => 'Woonvisie 2027',
					'question' => 'Wat vindt u van de concept-woonvisie?',
					'requestingBody' => 'gemeenteraad',
					'advisoryBody' => 'adviesraad',
					'requestedByDate' => '2026-05-01',
					'lifecycle' => 'sent',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$asks = $service->savedFor('governance-consultation');
		self::assertCount(expectedCount: 1, haystack: $asks);

		$ask = $asks[0];
		self::assertSame(expected: 'Woonvisie 2027', actual: $ask['subject']);
		// 🔑 BINDING IS THE FIELD THAT REPLACES A SCHEMA BOUNDARY. A formal
		// advice request bound the asking body; a member poll did not, and the
		// retired schemas said so only in prose.
		self::assertTrue(condition: $ask['binding']);
		self::assertSame(expected: 'bodies', actual: $ask['audienceType']);
		// References are RESOLVED, not copied: the targets declare `format: uuid`
		// and a seeded row holds a slug.
		self::assertSame(expected: 'uuid-of-gemeenteraad', actual: $ask['askingBody']);
		self::assertSame(expected: 'uuid-of-adviesraad', actual: $ask['audienceBody']);
		self::assertSame(expected: 'ar-1', actual: $ask['migratedFromObject']);

	}//end testAnAdviceRequestBecomesABindingConsultation()

	/**
	 * A member poll becomes a non-binding consultation keeping its audience.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-binding-is-a-field-not-a-schema-boundary
	 */
	public function testAMemberPollKeepsItsAudienceAndIsNotBinding(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			memberConsultations: [
				[
					'id' => 'mc-1',
					'question' => 'Steunt u het voorstel?',
					'audienceType' => 'politicalGroup',
					'closesAt' => '2026-06-01T12:00:00Z',
					'lifecycle' => 'open',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$ask = $service->savedFor('governance-consultation')[0];
		self::assertFalse(condition: $ask['binding']);
		// The poll's own spelling becomes the generic one.
		self::assertSame(expected: 'political-group', actual: $ask['audienceType']);
		self::assertSame(expected: 'Steunt u het voorstel?', actual: $ask['subject']);
		self::assertSame(expected: 'open', actual: $ask['lifecycle']);

	}//end testAMemberPollKeepsItsAudienceAndIsNotBinding()

	/**
	 * An advice and a view become responses, and both ways of declining agree.
	 *
	 * 🔑 `no-advice` AND `no-view` SAID THE SAME THING. Two schemas spelled the
	 * same outcome differently, and keeping both would leave an operator
	 * filtering for one and missing the other.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-existing-consultations-are-carried-across
	 */
	public function testBothWaysOfDecliningBecomeOneValue(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			adviceRequests: [['id' => 'ar-1', 'subject' => 'Een', 'requestingBody' => 'raad']],
			rounds: [['id' => 'zr-1', 'title' => 'Twee', 'sharedBody' => 'raad', 'deadline' => '2026-05-01']],
			adviceGiven: [['id' => 'ad-1', 'adviceRequest' => 'ar-1', 'tenor' => 'no-advice', 'summary' => 'Geen advies.']],
			views: [['id' => 'zw-1', 'ronde' => 'zr-1', 'position' => 'no-view', 'text' => 'Geen zienswijze.', 'status' => 'submitted']],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$responses = $service->savedFor('governance-consultation-response');
		self::assertCount(expectedCount: 2, haystack: $responses);
		foreach ($responses as $response) {
			self::assertSame(expected: 'none', actual: $response['position']);
			self::assertNotSame(expected: '', actual: (string)$response['consultation']);
		}

	}//end testBothWaysOfDecliningBecomeOneValue()

	/**
	 * An answer whose ask was not copied is skipped, not orphaned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-existing-consultations-are-carried-across
	 */
	public function testAnOrphanAnswerIsNotWritten(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			views: [['id' => 'zw-1', 'ronde' => 'zr-gone', 'position' => 'positive']],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('governance-consultation-response'));

	}//end testAnOrphanAnswerIsNotWritten()

	/**
	 * A second run copies nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-existing-consultations-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			adviceRequests: [['id' => 'ar-1', 'subject' => 'Al gedaan']],
			existingAsks: [['id' => 'gc-1', 'migratedFromObject' => 'ar-1']],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('governance-consultation'));

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
	 * @param array<int,array<string,mixed>> $adviceRequests      Legacy advice requests.
	 * @param array<int,array<string,mixed>> $rounds              Legacy view rounds.
	 * @param array<int,array<string,mixed>> $memberConsultations Legacy member polls.
	 * @param array<int,array<string,mixed>> $adviceGiven         Legacy advices.
	 * @param array<int,array<string,mixed>> $views               Legacy views.
	 * @param array<int,array<string,mixed>> $pollResponses       Legacy poll responses.
	 * @param array<int,array<string,mixed>> $existingAsks        Consultations already copied.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $adviceRequests = [],
		array $rounds = [],
		array $memberConsultations = [],
		array $adviceGiven = [],
		array $views = [],
		array $pollResponses = [],
		array $existingAsks = [],
	): object {
		return new class(
			$adviceRequests,
			$rounds,
			$memberConsultations,
			$adviceGiven,
			$views,
			$pollResponses,
			$existingAsks
		) {
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
			 * @param array<int,array<string,mixed>> $adviceRequests      Legacy advice requests.
			 * @param array<int,array<string,mixed>> $rounds              Legacy view rounds.
			 * @param array<int,array<string,mixed>> $memberConsultations Legacy member polls.
			 * @param array<int,array<string,mixed>> $adviceGiven         Legacy advices.
			 * @param array<int,array<string,mixed>> $views               Legacy views.
			 * @param array<int,array<string,mixed>> $pollResponses       Legacy poll responses.
			 * @param array<int,array<string,mixed>> $existingAsks        Consultations already copied.
			 *
			 * @return void
			 */
			public function __construct(
				private array $adviceRequests,
				private array $rounds,
				private array $memberConsultations,
				private array $adviceGiven,
				private array $views,
				private array $pollResponses,
				private array $existingAsks,
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
				// declares `format: uuid`, so a seeded slug has to be resolved
				// before it is written or the save is rejected.
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
					'advice-request' => $this->adviceRequests,
					'zienswijzeronde' => $this->rounds,
					'member-consultation' => $this->memberConsultations,
					'advies' => $this->adviceGiven,
					'zienswijze' => $this->views,
					'member-consultation-response' => $this->pollResponses,
					'governance-consultation' => array_merge($this->existingAsks, $saved),
					'governance-consultation-response' => $saved,
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
