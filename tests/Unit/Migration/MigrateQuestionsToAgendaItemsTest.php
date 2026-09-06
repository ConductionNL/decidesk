<?php

/**
 * Unit tests for MigrateQuestionsToAgendaItems.
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

use OCA\Decidiq\Migration\AgendaItemTypeResolver;
use OCA\Decidiq\Migration\MigrateQuestionsToAgendaItems;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The copy from the three question schemas onto `agenda-item`.
 *
 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
 */
class MigrateQuestionsToAgendaItemsTest extends TestCase {

	private SettingsService $settingsService;

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private IOutput $output;

	private MigrateQuestionsToAgendaItems $migration;

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

		$this->migration = new MigrateQuestionsToAgendaItems(
			$this->settingsService,
			$this->container,
			$this->logger,
			new AgendaItemTypeResolver($this->logger),
		);

	}//end setUp()

	/**
	 * An oral question becomes an agenda item carrying its own fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	public function testAnOralQuestionBecomesATypedAgendaItem(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			questions: [
				[
					'id' => 'mv-1',
					'subject' => 'Opvangcapaciteit',
					'rationale' => 'Graag inzicht.',
					'questionNumber' => 'MV-2026-004',
					'politicalGroup' => 'fractie-a',
					'governanceBody' => 'raad',
					'targetMeeting' => 'meeting-1',
					'sortOrder' => 3,
					'lifecycle' => 'answered',
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$items = $service->savedFor('agenda-item');
		self::assertCount(expectedCount: 1, haystack: $items);

		$item = $items[0];
		self::assertSame(expected: 'Opvangcapaciteit', actual: $item['title']);
		// 🔴 RESOLVED, NOT COPIED. `agenda-item.meeting` declares `format: uuid`
		// and a seeded legacy row holds the slug, so copying it across would be
		// rejected by saveObject() and the row reported as a warning — which
		// does not fail an upgrade.
		self::assertSame(expected: 'uuid-of-meeting-1', actual: $item['meeting']);
		self::assertSame(expected: 'Graag inzicht.', actual: $item['description']);
		// The row's own order wins over the traversal position.
		self::assertSame(expected: 3, actual: $item['orderNumber']);
		self::assertNotSame(expected: '', actual: $item['type']);

		// 🔑 THE DISTINCTIVE FIELDS MOVE, THEY ARE NOT DROPPED. Collapsing the
		// schema is only non-destructive if what made it distinctive survives.
		self::assertSame(expected: 'MV-2026-004', actual: $item['typeFields']['questionNumber']);
		self::assertSame(expected: 'fractie-a', actual: $item['typeFields']['politicalGroup']);
		self::assertSame(expected: 'answered', actual: $item['typeFields']['lifecycle']);
		self::assertSame(expected: 'mv-1', actual: $item['typeFields']['migratedFromObject']);

	}//end testAnOralQuestionBecomesATypedAgendaItem()

	/**
	 * A second run copies nothing, because the origin marker is the identity.
	 *
	 * 🔴 IDEMPOTENT BY SOURCE ROW, NOT BY BODY. Unlike a body configuration, a
	 * body has MANY questions, so the body cannot be the identity here — only
	 * the source row can. Keying on the body would migrate one question and
	 * silently drop the rest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	public function testASecondRunCopiesNothing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			questions: [['id' => 'mv-1', 'subject' => 'Al gemigreerd', 'governanceBody' => 'raad']],
			existingItems: [
				['id' => 'ai-1', 'title' => 'Al gemigreerd', 'typeFields' => ['migratedFromObject' => 'mv-1']],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('agenda-item'));

	}//end testASecondRunCopiesNothing()

	/**
	 * Many questions from one body create one type, not one type each.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	public function testOneBodyGetsOneTypePerKind(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			questions: [
				['id' => 'mv-1', 'subject' => 'Een', 'governanceBody' => 'raad'],
				['id' => 'mv-2', 'subject' => 'Twee', 'governanceBody' => 'raad'],
				['id' => 'mv-3', 'subject' => 'Drie', 'governanceBody' => 'raad'],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 3, haystack: $service->savedFor('agenda-item'));
		self::assertCount(expectedCount: 1, haystack: $service->savedFor('agenda-item-type'));

	}//end testOneBodyGetsOneTypePerKind()

	/**
	 * A source row with no identifier is skipped rather than copied.
	 *
	 * Without a stable origin there is no idempotency key, so copying it would
	 * duplicate the row on every subsequent run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	public function testARowWithNoIdentifierIsSkipped(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			questions: [['subject' => 'Geen id', 'governanceBody' => 'raad']],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $service->savedFor('agenda-item'));

	}//end testARowWithNoIdentifierIsSkipped()

	/**
	 * The question-hour configuration lands on the types, not on a new schema.
	 *
	 * 🔑 THE THRESHOLD IS NOT UNIVERSAL. A submission window applies to every
	 * kind of question; a support threshold only applies to the kind that needs
	 * support before it is admitted. Writing the threshold onto both would tell
	 * an operator that an ordinary question needs a fifth of the body behind it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-the-question-hour-configuration-moves-onto-the-type
	 */
	public function testTheQuestionHourConfigurationMovesOntoTheTypes(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$service = $this->makeObjectService(
			questions: [['id' => 'mv-1', 'subject' => 'Een', 'governanceBody' => 'raad']],
			interpellations: [['id' => 'int-1', 'subject' => 'Twee', 'governanceBody' => 'raad']],
			configurations: [
				[
					'id' => 'cfg-1',
					'governanceBody' => 'raad',
					'submissionPeriodHours' => 24,
					'interpellationSupportThresholdType' => 'fraction',
					'interpellationSupportThresholdValue' => 0.2,
				],
			],
		);
		$this->container->method('get')->willReturn($service);

		$this->migration->run(output: $this->output);

		$patches = $service->patchedFor('agenda-item-type');
		self::assertCount(expectedCount: 2, haystack: $patches);

		$withThreshold = array_values(
			array_filter($patches, static fn (array $p): bool => isset($p['supportThresholdType']) === true)
		);
		$withoutThreshold = array_values(
			array_filter($patches, static fn (array $p): bool => isset($p['supportThresholdType']) === false)
		);

		self::assertCount(expectedCount: 1, haystack: $withThreshold);
		self::assertCount(expectedCount: 1, haystack: $withoutThreshold);

		self::assertSame(expected: 24, actual: $withThreshold[0]['submissionWindowHours']);
		self::assertSame(expected: 'fraction', actual: $withThreshold[0]['supportThresholdType']);
		self::assertSame(expected: 0.2, actual: $withThreshold[0]['supportThresholdValue']);

		// The oral-question type takes the window and nothing else.
		self::assertSame(expected: 24, actual: $withoutThreshold[0]['submissionWindowHours']);
		self::assertArrayNotHasKey(key: 'supportThresholdValue', array: $withoutThreshold[0]);

	}//end testTheQuestionHourConfigurationMovesOntoTheTypes()

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
	 * @param array<int,array<string,mixed>> $questions       Legacy oral questions.
	 * @param array<int,array<string,mixed>> $interpellations Legacy interpellation requests.
	 * @param array<int,array<string,mixed>> $configurations  Legacy question-hour configurations.
	 * @param array<int,array<string,mixed>> $existingItems   Agenda items already present.
	 *
	 * @return object The fake.
	 */
	private function makeObjectService(
		array $questions = [],
		array $interpellations = [],
		array $configurations = [],
		array $existingItems = [],
	): object {
		return new class($questions, $interpellations, $configurations, $existingItems) {
			/**
			 * The schema currently selected.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Types this fake has created, so findAll can return them again.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $createdTypes = [];

			/**
			 * Saves, as [schema, payload, uuid] triples.
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>,2:string|null}>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $questions       Legacy oral questions.
			 * @param array<int,array<string,mixed>> $interpellations Legacy interpellation requests.
			 * @param array<int,array<string,mixed>> $configurations  Legacy configurations.
			 * @param array<int,array<string,mixed>> $existingItems   Agenda items already present.
			 *
			 * @return void
			 */
			public function __construct(
				private array $questions,
				private array $interpellations,
				private array $configurations,
				private array $existingItems,
			) {
			}//end __construct()

			/**
			 * Payloads created for one schema.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return array<int,array<string,mixed>> The payloads.
			 */
			public function savedFor(string $schema): array {
				return array_values(
					array_map(
						static fn (array $s): array => $s[1],
						array_filter($this->saves, static fn (array $s): bool => $s[0] === $schema && $s[2] === null)
					)
				);

			}//end savedFor()

			/**
			 * Payloads written onto an EXISTING object of one schema.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return array<int,array<string,mixed>> The payloads.
			 */
			public function patchedFor(string $schema): array {
				return array_values(
					array_map(
						static fn (array $s): array => $s[1],
						array_filter($this->saves, static fn (array $s): bool => $s[0] === $schema && $s[2] !== null)
					)
				);

			}//end patchedFor()

			/**
			 * Run an operation as the system user.
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
			 * @param array<string,mixed> $filters Slug lookups for governance-body.
			 *
			 * @return array<int,array<string,mixed>> The rows.
			 */
			public function findAll(array $filters = []): array {
				// A slug lookup goes through `@self`, for ANY schema: every `$ref`
				// property in this register declares `format: uuid`, so a seeded
				// slug must be resolved before it is written or the save is
				// rejected and the row silently skipped.
				$slug = (string)($filters['filters']['@self']['slug'] ?? '');
				if ($slug !== '') {
					return [['id' => 'uuid-of-' . $slug]];
				}

				return match ($this->currentSchema) {
					'mondelinge-vraag' => $this->questions,
					'interpellatieverzoek' => $this->interpellations,
					'vragenuur-configuratie' => $this->configurations,
					'agenda-item' => $this->existingItems,
					'agenda-item-type' => $this->createdTypes,
					default => [],
				};

			}//end findAll()

			/**
			 * Record a save, and hand back an object carrying an id.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The payload.
			 * @param string|null         $uuid     The object updated, when patching.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(
				string $register,
				string $schema,
				array $object,
				?string $uuid = null,
			): array {
				$this->saves[] = [$schema, $object, $uuid];

				$saved = ($object + ['id' => ($uuid ?? ($schema . '-' . count($this->saves)))]);
				if ($schema === 'agenda-item-type' && $uuid === null) {
					$this->createdTypes[] = $saved;
				}

				return $saved;

			}//end saveObject()
		};

	}//end makeObjectService()
}//end class
