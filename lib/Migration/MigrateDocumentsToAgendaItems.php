<?php
/**
 * Decidiq MigrateDocumentsToAgendaItems.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * documents-as-agenda-items change: copies `ingekomen-stuk`,
 * `raadsinformatiebrief` and `technische-vraag` rows onto the generic
 * `agenda-item`, each carrying its distinctive fields in `typeFields`.
 *
 * 🔴 LETTERS BEFORE QUESTIONS. A technical question's parent is a letter, so the
 * letters have to exist and be indexable before the questions that hang off them
 * are written.
 *
 * 🔴 PURELY ADDITIVE. Source rows are never edited or deleted, and every source
 * schema keeps its definition (`active:false`, `hardDelete:false`).
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies routed documents onto generic agenda items.
 *
 * @spec openspec/changes/documents-as-agenda-items/specs/documents-as-agenda-items/spec.md
 */
class MigrateDocumentsToAgendaItems implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The generic target schema slug.
	 *
	 * @var string
	 */
	private const TARGET = 'agenda-item';

	/**
	 * The key under `typeFields` recording which source row an item came from.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * The three sources, IN DEPENDENCY ORDER.
	 *
	 * 🔴 A TECHNICAL QUESTION'S PARENT IS A LETTER, so letters come before
	 * questions and the question's `rib` is retargeted at the letter's COPY.
	 * `parentField` names the property holding the parent, and `parentSchema` the
	 * schema it points at, so a seeded slug can be resolved before it is matched.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const SOURCES = [
		'ingekomen-stuk' => [
			'title' => 'title',
			'itemType' => 'informational',
			'typeName' => 'Incoming document',
			'bodyField' => 'directedTo',
			'parentField' => 'listAgendaItem',
			'parentSchema' => 'agenda-item',
			'carry' => [
				'sender',
				'senderType',
				'receivedAt',
				'category',
				'summary',
				'routingAdvice',
				'lifecycle',
				'targetAgendaItem',
			],
		],
		'raadsinformatiebrief' => [
			'title' => 'subject',
			'itemType' => 'informational',
			'typeName' => 'Information letter',
			'bodyField' => 'directedTo',
			'parentField' => 'agendaItem',
			'parentSchema' => 'agenda-item',
			'carry' => [
				'number',
				'portfolioHolder',
				'category',
				'sentAt',
				'letterDocument',
				'attachments',
				'settledCommitment',
				'relatedFile',
				'relatedDecision',
				'relatedMotion',
				'lifecycle',
				'publicationDate',
				'depublicationDate',
			],
		],
		'technische-vraag' => [
			'title' => 'question',
			'itemType' => 'discussion',
			'typeName' => 'Technical question',
			'bodyField' => null,
			'parentField' => 'rib',
			'parentSchema' => 'raadsinformatiebrief',
			'carry' => [
				'question',
				'setBy',
				'politicalGroup',
				'setOn',
				'answer',
				'answeredBy',
				'answeredOn',
				'lifecycle',
				'publicationDate',
				'depublicationDate',
			],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface     $container       Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface        $logger          Records what was migrated.
	 * @param AgendaItemTypeResolver $types           Resolves the configurable kind each row becomes.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly AgendaItemTypeResolver $types,
	) {
	}//end __construct()

	/**
	 * The logger the shared legacy-row reads report through.
	 *
	 * @return LoggerInterface The logger.
	 *
	 * @spec exclude Trait accessor; exposes an already-injected dependency.
	 */
	protected function migrationLogger(): LoggerInterface {
		return $this->logger;

	}//end migrationLogger()

	/**
	 * Repair-step label.
	 *
	 * @return string The label.
	 *
	 * @spec exclude Trivial repair-step label accessor.
	 */
	public function getName(): string {
		return 'Copy Decidiq routed documents onto agenda items';

	}//end getName()

	/**
	 * Run the copy.
	 *
	 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-as-agenda-items/specs/documents-as-agenda-items/spec.md#requirement-existing-documents-are-carried-across
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->info('OpenRegister unavailable — nothing to migrate.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('Could not resolve OpenRegister ObjectService: ' . $e->getMessage());
			return;
		}

		// 🔴 RUN AS SYSTEM. A repair step has no session, so OpenRegister sees
		// the actor as 'Anonymous' and refuses `create`, and this step reports
		// that as a warning, which does not fail an upgrade.
		$objectService->runAsSystem(
			function () use ($objectService, $output): void {
				$this->migrateAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Copy each source in dependency order.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-as-agenda-items/specs/documents-as-agenda-items/spec.md#requirement-existing-documents-are-carried-across
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$existing  = $this->originIndex(objectService: $objectService);
		$copiedIds = [];
		$copied    = 0;
		$position  = 0;

		foreach (self::SOURCES as $schema => $mapping) {
			foreach ($this->readRows(objectService: $objectService, schema: $schema, limit: 10000) as $row) {
				$position++;
				$origin = $this->identifierOf(object: $row);
				if ($origin === '' || isset($existing[$origin]) === true) {
					continue;
				}

				$typeId = $this->types->resolve(
					objectService: $objectService,
					name: $mapping['typeName'],
					bodyReference: $this->bodyReferenceFor(row: $row, mapping: $mapping)
				);
				if ($typeId === '') {
					continue;
				}

				try {
					$objectService->setRegister(self::REGISTER);
					$objectService->setSchema(self::TARGET);
					$saved = $objectService->saveObject(
						register: self::REGISTER,
						schema: self::TARGET,
						object: $this->coerceToTarget(
							objectService: $objectService,
							properties: $this->declaredProperties(slug: self::TARGET),
							// `type` comes from the resolved type id and
							// `parentItem` from what this run already copied.
							alreadyResolved: ['type', 'parentItem'],
							payload: $this->mapItem(
								objectService: $objectService,
								row: $row,
								mapping: $mapping,
								typeId: $typeId,
								origin: $origin,
								order: $position,
								copiedIds: $copiedIds
							),
						),
					);
					$existing[$origin]  = true;
					$copiedIds[$origin] = $this->identifierOf(object: $this->toArray(entity: $saved));
					$copied++;
				} catch (Throwable $e) {
					$output->warning('Failed to migrate a routed document: ' . $e->getMessage());
					$this->logger->warning(
						'Decidiq: routed-document migration failed for one row',
						['error' => $e->getMessage(), 'schema' => $schema, 'origin' => $origin]
					);
				}//end try
			}//end foreach
		}//end foreach

		$output->info('Decidiq routed-document migration complete: ' . $copied . ' agenda item(s).');

	}//end migrateAll()

	/**
	 * The body reference a row's kind should be scoped to.
	 *
	 * A technical question names no body of its own: it belongs to whichever body
	 * the letter was sent to, and scoping its kind to nothing is better than
	 * inventing one.
	 *
	 * @param array<string,mixed> $row     The legacy row.
	 * @param array<string,mixed> $mapping The source's mapping entry.
	 *
	 * @return string The reference, or ''.
	 */
	private function bodyReferenceFor(array $row, array $mapping): string {
		$field = ($mapping['bodyField'] ?? null);
		if (is_string($field) === false) {
			return '';
		}

		return (string)($row[$field] ?? '');

	}//end bodyReferenceFor()

	/**
	 * Agenda items already migrated, keyed by the source they came from.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Keyed by source identifier.
	 */
	private function originIndex(object $objectService): array {
		$index = [];

		foreach ($this->readRows(objectService: $objectService, schema: self::TARGET, limit: 10000) as $item) {
			$fields = ($item['typeFields'] ?? null);
			if (is_array($fields) === false) {
				continue;
			}

			$origin = trim((string)($fields[self::ORIGIN_KEY] ?? ''));
			if ($origin !== '') {
				$index[$origin] = true;
			}
		}

		return $index;

	}//end originIndex()

	/**
	 * Map one document onto a generic agenda item.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param array<string,mixed>  $row           The legacy row.
	 * @param array<string,mixed>  $mapping       The source's mapping entry.
	 * @param string               $typeId        The resolved agenda-item type.
	 * @param string               $origin        The source identifier.
	 * @param int                  $order         The order to use when the row names none.
	 * @param array<string,string> $copiedIds     Source identifier to new identifier.
	 *
	 * @return array<string,mixed> The generic payload.
	 */
	private function mapItem(
		object $objectService,
		array $row,
		array $mapping,
		string $typeId,
		string $origin,
		int $order,
		array $copiedIds,
	): array {
		$title = trim((string)($row[(string)$mapping['title']] ?? ''));
		if ($title === '') {
			$title = 'Untitled';
		}

		$payload = [
			// `title` is required, and a question can be a paragraph, so it is
			// TRUNCATED for the title while the full text stays in typeFields.
			'title' => mb_substr($title, 0, 250),
			'itemType' => (string)$mapping['itemType'],
			'orderNumber' => $order,
			'type' => $typeId,
		];

		$parent = $this->parentFor(
			objectService: $objectService,
			row: $row,
			mapping: $mapping,
			copiedIds: $copiedIds
		);
		if ($parent !== '') {
			$payload['parentItem'] = $parent;
		}

		$fields = [self::ORIGIN_KEY => $origin];
		foreach ((array)$mapping['carry'] as $key) {
			$value = ($row[$key] ?? null);
			// A NULL IS NOT AN ABSENT VALUE TO THE VALIDATOR, and an empty string
			// records nothing worth keeping.
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$fields[$key] = $value;
		}

		$payload['typeFields'] = $fields;

		return $payload;

	}//end mapItem()

	/**
	 * The agenda item a copied document hangs under.
	 *
	 * 🔴 A QUESTION MUST FOLLOW ITS LETTER. Copied verbatim, `rib` would name the
	 * RETIRED letter while the question lived on the new schema, so the sub-item
	 * relationship this change exists to express would point at the wrong side of
	 * the migration.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param array<string,mixed>  $row           The legacy row.
	 * @param array<string,mixed>  $mapping       The source's mapping entry.
	 * @param array<string,string> $copiedIds     Source identifier to new identifier.
	 *
	 * @return string The parent identifier, or ''.
	 */
	private function parentFor(object $objectService, array $row, array $mapping, array $copiedIds): string {
		$reference = trim((string)($row[(string)$mapping['parentField']] ?? ''));
		if ($reference === '') {
			return '';
		}

		if (isset($copiedIds[$reference]) === true) {
			return (string)$copiedIds[$reference];
		}

		$resolved = $this->resolveReference(
			objectService: $objectService,
			schema: (string)$mapping['parentSchema'],
			reference: $reference
		);

		return (string)($copiedIds[$resolved] ?? $resolved);

	}//end parentFor()
}//end class
