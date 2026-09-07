<?php

/**
 * Unit tests for MigrateLegacyTemplatesToDecisionTemplate repair step.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Migration;

use OCA\Decidiq\Migration\MigrateLegacyTemplatesToDecisionTemplate;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the legacy-template-to-DecisionTemplate migration repair step.
 *
 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
 */
class MigrateLegacyTemplatesToDecisionTemplateTest extends TestCase {

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock IOutput.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * The repair step under test.
	 *
	 * @var MigrateLegacyTemplatesToDecisionTemplate
	 */
	private MigrateLegacyTemplatesToDecisionTemplate $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigrateLegacyTemplatesToDecisionTemplate(
			settingsService: $this->settingsService,
			container: $this->container,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * The step name is descriptive.
	 *
	 * @return void
	 */
	public function testGetNameReturnsDescription(): void {
		self::assertStringContainsString(
			needle: 'DecisionTemplate',
			haystack: $this->migration->getName()
		);

	}//end testGetNameReturnsDescription()

	/**
	 * When OpenRegister is unavailable the migration skips entirely.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->settingsService->expects($this->once())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(false);

		$this->container->expects($this->never())->method(constraint: 'get');
		$this->output->expects($this->atLeastOnce())->method(constraint: 'warning');

		$this->migration->run(output: $this->output);

	}//end testRunSkipsWhenOpenRegisterUnavailable()

	/**
	 * A live process-template object is migrated into a decision-template
	 * object carrying every field forward, unchanged, plus migratedFrom
	 * provenance and an empty checklist (migration.md step 3).
	 *
	 * @return void
	 */
	public function testRunMigratesProcessTemplateFieldsVerbatim(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$stateMachine = [
			'states' => [['name' => 'draft'], ['name' => 'decided']],
			'transitions' => [['name' => 'decide', 'from' => 'draft', 'to' => 'decided']],
		];
		$votingRule = ['voteThreshold' => 'simple-majority', 'abstentionHandling' => 'exclude', 'tieBreakRule' => 'rejected'];

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				[
					'id' => 'pt-1',
					'name' => 'Association ALV',
					'description' => 'Standard process',
					'context' => 'association',
					'builtIn' => true,
					'initialState' => 'draft',
					'stateMachine' => $stateMachine,
					'votingRule' => $votingRule,
					'quorumRequired' => true,
					'quorumRule' => '50%+1',
					'allowDecideWithoutVote' => false,
				],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		$saved = $objectService->saved[0];
		self::assertSame(expected: $stateMachine, actual: $saved['stateMachine']);
		self::assertSame(expected: $votingRule, actual: $saved['votingRule']);
		self::assertSame(expected: [], actual: $saved['checklist']);
		self::assertSame(
			expected: ['sourceSchema' => 'process-template', 'sourceUuid' => 'pt-1'],
			actual: $saved['migratedFrom']
		);
		self::assertArrayNotHasKey(key: 'decisionType', array: $saved);
		self::assertArrayNotHasKey(key: 'urgencyPolicy', array: $saved);

	}//end testRunMigratesProcessTemplateFieldsVerbatim()

	/**
	 * A live vve-decision-template object is migrated with the field mapping
	 * from migration.md step 4: decisionCategory -> templateCategory,
	 * defaultVoteThreshold -> votingRule.voteThreshold,
	 * defaultQuorumFraction -> quorumRule, context fixed to association,
	 * decisionType fixed to resolution.
	 *
	 * @return void
	 */
	public function testRunMapsVveDecisionTemplateFields(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [],
			vveDecisionTemplates: [
				[
					'id' => 'vve-1',
					'name' => 'Machtiging bestuur onderhoud boven drempel',
					'decisionCategory' => 'authorisation-above-threshold',
					'proposedText' => 'De vergadering machtigt het bestuur...',
					'builtIn' => true,
					'defaultVoteThreshold' => 'qualified-majority-two-thirds',
					'defaultQuorumFraction' => '2/3',
					'regulationSource' => 'MR 2017 art. 56 lid 5',
				],
			],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		$saved = $objectService->saved[0];
		self::assertSame(expected: 'association', actual: $saved['context']);
		self::assertSame(expected: 'resolution', actual: $saved['decisionType']);
		self::assertSame(expected: 'authorisation-above-threshold', actual: $saved['templateCategory']);
		self::assertSame(
			expected: 'qualified-majority-two-thirds',
			actual: $saved['votingRule']['voteThreshold']
		);
		self::assertSame(expected: '2/3', actual: $saved['quorumRule']);
		self::assertSame(
			expected: ['sourceSchema' => 'vve-decision-template', 'sourceUuid' => 'vve-1'],
			actual: $saved['migratedFrom']
		);

	}//end testRunMapsVveDecisionTemplateFields()

	/**
	 * An object already carrying a matching migratedFrom marker is skipped —
	 * a re-run of the migration creates no duplicates (idempotency).
	 *
	 * @return void
	 */
	public function testRunSkipsAlreadyMigratedObject(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [
				[
					'id' => 'dt-1',
					'name' => 'Association ALV',
					'migratedFrom' => ['sourceSchema' => 'process-template', 'sourceUuid' => 'pt-1'],
				],
			],
			processTemplates: [
				['id' => 'pt-1', 'name' => 'Association ALV', 'context' => 'association'],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $objectService->saved);

	}//end testRunSkipsAlreadyMigratedObject()

	/**
	 * A SEEDED template of the same name is already the migrated equivalent, so
	 * the legacy row must not be migrated on top of it.
	 *
	 * 🔴 THIS IS WHAT DUPLICATED EVERY BUILT-IN TEMPLATE.
	 *
	 * `68-unified-decision-templates.json` seeds the thirteen built-ins,
	 * describing them as ports of these very rows, and supersession
	 * deliberately KEPT the legacy rows. A seed carries no `migratedFrom`, so
	 * the uuid-keyed idempotency index never matched it and the migration
	 * created a second copy of each.
	 *
	 * Measured on a live instance once the unified schema finally had a page to
	 * be seen through: 28 decision-templates, 13 of them duplicates — each
	 * built-in once with its seeded slug and once with none.
	 *
	 * @return void
	 */
	public function testRunSkipsALegacyRowAlreadySeededUnderTheSameName(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			// The SEEDED built-in: no `migratedFrom`, so the uuid index cannot
			// see it. Only the name can.
			decisionTemplates: [
				['id' => 'dt-seeded', 'name' => 'Association ALV', 'context' => 'association'],
			],
			processTemplates: [
				['id' => 'pt-1', 'name' => 'Association ALV', 'context' => 'association'],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $objectService->saved);

	}//end testRunSkipsALegacyRowAlreadySeededUnderTheSameName()

	/**
	 * Two legacy rows sharing one name produce ONE template, not two.
	 *
	 * A process-template and a vve-decision-template can carry the same name;
	 * without recording each newly created row the second would still
	 * duplicate the first inside a single pass.
	 *
	 * @return void
	 */
	public function testRunCreatesOneTemplateWhenTwoLegacyRowsShareAName(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				['id' => 'pt-1', 'name' => 'Decharge bestuur', 'context' => 'association'],
			],
			vveDecisionTemplates: [
				['id' => 'vve-1', 'name' => 'Decharge bestuur', 'decisionCategory' => 'decharge'],
			],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);

	}//end testRunCreatesOneTemplateWhenTwoLegacyRowsShareAName()

	/**
	 * A legacy row whose name is NOT already present is still migrated: the
	 * name check must not become a blanket stand-down.
	 *
	 * @return void
	 */
	public function testRunStillMigratesALegacyRowNothingHasSeeded(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [
				['id' => 'dt-seeded', 'name' => 'Association ALV', 'context' => 'association'],
			],
			processTemplates: [
				['id' => 'pt-9', 'name' => 'A template nobody seeded', 'context' => 'operations'],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);

	}//end testRunStillMigratesALegacyRowNothingHasSeeded()

	/**
	 * The migration never deletes or edits a source process-template or
	 * vve-decision-template object — it is purely additive.
	 *
	 * @return void
	 */
	public function testRunNeverDeletesSourceObjects(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				['id' => 'pt-1', 'name' => 'Association ALV', 'context' => 'association'],
			],
			vveDecisionTemplates: [
				['id' => 'vve-1', 'name' => 'Decharge bestuur', 'decisionCategory' => 'discharge'],
			],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertSame(expected: 0, actual: $objectService->deleteCalls);
		self::assertCount(expectedCount: 2, haystack: $objectService->saved);

	}//end testRunNeverDeletesSourceObjects()

	/**
	 * When the OpenRegister ObjectService cannot be resolved from the
	 * container (e.g. OpenRegister mid-install), the migration warns and
	 * exits without attempting to read or write any object.
	 *
	 * @return void
	 */
	public function testRunWarnsWhenObjectServiceCannotBeResolved(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$this->container->expects($this->once())
			->method(constraint: 'get')
			->willThrowException(new \RuntimeException('Service not found.'));

		$this->output->expects($this->atLeastOnce())->method(constraint: 'warning');
		$this->logger->expects($this->atLeastOnce())->method(constraint: 'warning');

		$this->migration->run(output: $this->output);

	}//end testRunWarnsWhenObjectServiceCannotBeResolved()

	/**
	 * When the decision-template schema/seeds have not been imported yet,
	 * findAll() for the idempotency index throws. buildMigratedIndex()
	 * treats that as "nothing migrated yet" and the run proceeds to migrate
	 * every legacy object normally, rather than aborting.
	 *
	 * @return void
	 */
	public function testRunTreatsUnreadableTargetIndexAsEmptyAndMigratesAnyway(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				['id' => 'pt-1', 'name' => 'Association ALV', 'context' => 'association'],
			],
			vveDecisionTemplates: [],
			throwFindAllForSchemas: ['decision-template'],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertSame(expected: 'pt-1', actual: $objectService->saved[0]['migratedFrom']['sourceUuid']);

	}//end testRunTreatsUnreadableTargetIndexAsEmptyAndMigratesAnyway()

	/**
	 * Malformed rows read back from the decision-template schema — one that
	 * cannot be normalised into an array at all, one whose migratedFrom is
	 * not an array, one missing sourceSchema, and one with an empty
	 * sourceUuid — must never be indexed as "already migrated". A legacy
	 * process-template row whose id is referenced only by the malformed
	 * entries is still migrated, proving none of them created a false
	 * idempotency match (and that none of them is fatal).
	 *
	 * @return void
	 */
	public function testBuildMigratedIndexIgnoresMalformedExistingRows(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		// An object whose jsonSerialize() does not return an array — toArray()
		// must fall back to null rather than treating the string as a payload.
		$badJsonSerializeEntity = new class() {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'not-an-array';
			}//end jsonSerialize()
		};

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [
				// Neither an array nor an object exposing jsonSerialize()/getObject().
				42,
				$badJsonSerializeEntity,
				['id' => 'dt-2', 'migratedFrom' => 'not-an-array'],
				['id' => 'dt-3', 'migratedFrom' => ['sourceUuid' => 'pt-3']],
				['id' => 'dt-4', 'migratedFrom' => ['sourceSchema' => 'process-template', 'sourceUuid' => '']],
			],
			processTemplates: [
				['id' => 'pt-3', 'name' => 'Still migrated — no malformed row indexed it'],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertSame(expected: 'pt-3', actual: $objectService->saved[0]['migratedFrom']['sourceUuid']);

	}//end testBuildMigratedIndexIgnoresMalformedExistingRows()

	/**
	 * When findAll() throws for one legacy source schema (e.g. that schema
	 * was never instantiated on this instance), that schema contributes
	 * nothing and the other legacy schema is still migrated in full — the
	 * failure is scoped to migrateSchema()'s own try/catch, not fatal to the
	 * whole run.
	 *
	 * @return void
	 */
	public function testRunSkipsSourceSchemaWhenFindAllThrowsWithoutAffectingTheOtherSchema(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$infoMessages = [];
		$this->output->method('info')->willReturnCallback(
			function (string $message) use (&$infoMessages): void {
				$infoMessages[] = $message;
			}
		);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [],
			vveDecisionTemplates: [
				['id' => 'vve-1', 'name' => 'Decharge bestuur', 'decisionCategory' => 'discharge'],
			],
			throwFindAllForSchemas: ['process-template'],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertSame(expected: 'vve-1', actual: $objectService->saved[0]['migratedFrom']['sourceUuid']);
		self::assertSame(expected: 2, actual: count($infoMessages));
		self::assertStringContainsString(needle: 'process-template', haystack: $infoMessages[0]);

	}//end testRunSkipsSourceSchemaWhenFindAllThrowsWithoutAffectingTheOtherSchema()

	/**
	 * A source row that cannot be normalised into an array at all, and a
	 * source row normalised fine but carrying neither 'id' nor 'uuid', are
	 * both skipped without aborting the loop — the next identifiable row is
	 * still migrated.
	 *
	 * @return void
	 */
	public function testRunSkipsSourceRowsThatCannotBeNormalisedOrIdentified(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				// Neither an array nor an object exposing jsonSerialize()/getObject().
				42,
				// Normalisable, but nothing to key it by.
				['name' => 'No identifier at all', 'context' => 'association'],
				['id' => 'pt-valid', 'name' => 'Identifiable row', 'context' => 'association'],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertSame(expected: 'pt-valid', actual: $objectService->saved[0]['migratedFrom']['sourceUuid']);

	}//end testRunSkipsSourceRowsThatCannotBeNormalisedOrIdentified()

	/**
	 * When saveObject() throws for one row (e.g. a schema validation
	 * rejection on OpenRegister's side), the loop does not abort — it counts
	 * the row as skipped, warns, and still migrates the next row.
	 *
	 * @return void
	 */
	public function testRunContinuesToNextRowAfterOneSaveFails(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$warnings = [];
		$this->output->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				['id' => 'pt-fail', 'name' => 'Will fail to save', 'context' => 'association'],
				['id' => 'pt-ok', 'name' => 'Will save fine', 'context' => 'association'],
			],
			vveDecisionTemplates: [],
			failSaveForSourceUuids: ['pt-fail'],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertSame(expected: 'pt-ok', actual: $objectService->saved[0]['migratedFrom']['sourceUuid']);
		self::assertTrue(condition: str_contains(implode('|', $warnings), 'pt-fail'));

	}//end testRunContinuesToNextRowAfterOneSaveFails()

	/**
	 * A process-template source row carrying urgencyPolicy has it copied
	 * forward onto the decision-template payload (migration.md step 3).
	 *
	 * @return void
	 */
	public function testRunMapProcessTemplateCarriesUrgencyPolicyWhenPresent(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$urgencyPolicy = ['allowUrgent' => true, 'urgentQuorumRule' => 'majority-present'];

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				[
					'id' => 'pt-urgent',
					'name' => 'Urgent process',
					'context' => 'association',
					'urgencyPolicy' => $urgencyPolicy,
				],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertSame(expected: $urgencyPolicy, actual: $objectService->saved[0]['urgencyPolicy']);

	}//end testRunMapProcessTemplateCarriesUrgencyPolicyWhenPresent()

	/**
	 * A vve-decision-template source row with neither defaultVoteThreshold
	 * nor defaultQuorumFraction produces a decision-template payload with no
	 * votingRule/quorumRule keys at all — the mapper does not invent them.
	 *
	 * @return void
	 */
	public function testRunMapVveDecisionTemplateOmitsVotingRuleAndQuorumRuleWhenSourceFieldsAbsent(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [],
			vveDecisionTemplates: [
				[
					'id' => 'vve-no-threshold',
					'name' => 'No threshold or quorum on the source row',
					'decisionCategory' => 'discharge',
				],
			],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertArrayNotHasKey(key: 'votingRule', array: $objectService->saved[0]);
		self::assertArrayNotHasKey(key: 'quorumRule', array: $objectService->saved[0]);

	}//end testRunMapVveDecisionTemplateOmitsVotingRuleAndQuorumRuleWhenSourceFieldsAbsent()

	/**
	 * A source row shaped like the real OpenRegister ObjectEntity — exposing
	 * only jsonSerialize(), no getObject() — is normalised and migrated via
	 * toArray()'s primary branch, not just the raw-array fallback the other
	 * tests exercise.
	 *
	 * @return void
	 */
	public function testRunNormalisesSourceRowsExposingOnlyJsonSerialize(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				$this->jsonSerializableEntity(data: [
					'id' => 'pt-json',
					'name' => 'Via jsonSerialize',
					'context' => 'association',
				]),
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertSame(expected: 'Via jsonSerialize', actual: $objectService->saved[0]['name']);
		self::assertSame(expected: 'pt-json', actual: $objectService->saved[0]['migratedFrom']['sourceUuid']);

	}//end testRunNormalisesSourceRowsExposingOnlyJsonSerialize()

	/**
	 * A source row exposing only getObject() (no jsonSerialize() at all) is
	 * normalised and migrated via toArray()'s fallback branch.
	 *
	 * @return void
	 */
	public function testRunNormalisesSourceRowsExposingOnlyGetObject(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				$this->getObjectOnlyEntity(data: [
					'id' => 'pt-legacy',
					'name' => 'Via getObject',
					'context' => 'association',
				]),
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		self::assertSame(expected: 'Via getObject', actual: $objectService->saved[0]['name']);
		self::assertSame(expected: 'pt-legacy', actual: $objectService->saved[0]['migratedFrom']['sourceUuid']);

	}//end testRunNormalisesSourceRowsExposingOnlyGetObject()

	/**
	 * Wrap a plain array as an object exposing only jsonSerialize() — the
	 * real OpenRegister ObjectEntity shape (toArray()'s primary branch).
	 *
	 * @param array<string,mixed> $data The object payload.
	 *
	 * @return object
	 */
	private function jsonSerializableEntity(array $data): object {
		return new class($data) {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $data The object payload.
			 */
			public function __construct(
				private readonly array $data,
			) {
			}//end __construct()

			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};

	}//end jsonSerializableEntity()

	/**
	 * Wrap a plain array as an object exposing only getObject() — no
	 * jsonSerialize() at all (toArray()'s fallback branch).
	 *
	 * @param array<string,mixed> $data The object payload.
	 *
	 * @return object
	 */
	private function getObjectOnlyEntity(array $data): object {
		return new class($data) {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $data The object payload.
			 */
			public function __construct(
				private readonly array $data,
			) {
			}//end __construct()

			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()
		};

	}//end getObjectOnlyEntity()

	/**
	 * Build a stub ObjectService that records saves/deletes and returns the
	 * configured objects per schema from findAll(), keyed by whichever
	 * schema setSchema() was last called with.
	 *
	 * @param array<int,array<string,mixed>> $decisionTemplates Existing decision-template rows.
	 * @param array<int,array<string,mixed>> $processTemplates Live process-template rows.
	 * @param array<int,array<string,mixed>> $vveDecisionTemplates Live vve-decision-template rows.
	 * @param array<int,string> $throwFindAllForSchemas Schema slugs whose findAll() call throws.
	 * @param array<int,string> $failSaveForSourceUuids migratedFrom.sourceUuid values whose saveObject() call throws.
	 *
	 * @return object
	 */
	private function makeRecordingObjectService(
		array $decisionTemplates,
		array $processTemplates,
		array $vveDecisionTemplates,
		array $throwFindAllForSchemas = [],
		array $failSaveForSourceUuids = [],
	): object {
		return new class($decisionTemplates, $processTemplates, $vveDecisionTemplates, $throwFindAllForSchemas, $failSaveForSourceUuids, ) {
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
			 * Currently selected schema (set via setSchema()).
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $decisionTemplates Existing decision-template rows.
			 * @param array<int,array<string,mixed>> $processTemplates Live process-template rows.
			 * @param array<int,array<string,mixed>> $vveDecisionTemplates Live vve-decision-template rows.
			 * @param array<int,string> $throwFindAllForSchemas Schema slugs whose findAll() call throws.
			 * @param array<int,string> $failSaveForSourceUuids migratedFrom.sourceUuid values whose saveObject() call throws.
			 */
			public function __construct(
				private readonly array $decisionTemplates,
				private readonly array $processTemplates,
				private readonly array $vveDecisionTemplates,
				private readonly array $throwFindAllForSchemas = [],
				private readonly array $failSaveForSourceUuids = [],
			) {
			}//end __construct()

			/**
			 * Stub setRegister.
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			/**
			 * Run the callable, as OpenRegister does under a system identity.
			 *
			 * The fake needs this because the production migration is WRAPPED in
			 * it: an `occ upgrade` has no session, so without a system identity
			 * OpenRegister refuses every create as 'Anonymous'. A fake missing the
			 * method does not merely fail — it would let the wrapper be removed
			 * and the suite stay green while every real upgrade migrated nothing.
			 *
			 * @param callable $operation The operation to run.
			 *
			 * @return mixed The operation's result.
			 */
			public function runAsSystem(callable $operation) {
				return $operation();
			}

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Stub setSchema — records which schema is active for the next findAll().
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the configured rows for the currently selected schema, or
			 * throw when that schema is configured to fail (migration.md's
			 * "schema/objects never instantiated" no-op path).
			 *
			 * @param array<string,mixed> $config Query config.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				if (in_array($this->currentSchema, $this->throwFindAllForSchemas, true) === true) {
					throw new \RuntimeException('findAll failed for ' . $this->currentSchema);
				}

				return match ($this->currentSchema) {
					'decision-template' => $this->decisionTemplates,
					'process-template' => $this->processTemplates,
					'vve-decision-template' => $this->vveDecisionTemplates,
					default => [],
				};
			}//end findAll()

			/**
			 * Capture a save, or throw when the payload's provenance uuid is
			 * configured to fail (per-row save-failure path).
			 *
			 * @param array<string,mixed> $object Object to save.
			 * @param string|null $register Register slug.
			 * @param string|null $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				$sourceUuid = ($object['migratedFrom']['sourceUuid'] ?? null);
				if ($sourceUuid !== null && in_array($sourceUuid, $this->failSaveForSourceUuids, true) === true) {
					throw new \RuntimeException('saveObject failed for ' . $sourceUuid);
				}

				$this->saved[] = $object;
				return $object;
			}//end saveObject()

			/**
			 * Capture a delete (should never be called by this migration).
			 *
			 * @param string $uuid Object UUID.
			 * @param string|null $register Register slug.
			 * @param string|null $schema Schema slug.
			 *
			 * @return bool
			 */
			public function deleteObject(string $uuid, ?string $register = null, ?string $schema = null): bool {
				$this->deleteCalls++;
				return true;
			}//end deleteObject()
		};

	}//end makeRecordingObjectService()
}//end class
