<?php
/**
 * Decidiq MigrateConsultationsToOneSchema.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * one-consultation-schema change: copies three pairs of schemas onto the
 * generic `consultation` and `consultation-response`.
 *
 * 🔴 PURELY ADDITIVE. The source rows are never edited and never deleted, and
 * every source schema keeps its definition (`active:false`, `hardDelete:false`).
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
 * Copies advice requests, view rounds and member polls onto one schema.
 *
 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md
 */
class MigrateConsultationsToOneSchema implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The generic target for the ask.
	 *
	 * @var string
	 */
	private const TARGET = 'governance-consultation';

	/**
	 * The generic target for the answer.
	 *
	 * @var string
	 */
	private const RESPONSE_TARGET = 'governance-consultation-response';

	/**
	 * The key recording which source row a copied record came from.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * The three asks, and how each names what the generic schema needs.
	 *
	 * `audienceType` is fixed per source because it is a property of the source
	 * SCHEMA, not of any row: an advice request always addressed a body, a
	 * member poll never did.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	/**
	 * Lifecycle values one source spells differently from the generic schema.
	 *
	 * 🔴 A CARRIED ENUM IS NOT A CARRIED STRING. `advice-request` tracked
	 * `sent / in-progress / advice-issued / accounted-for / completed /
	 * not-issued`; `governance-consultation` accepts `draft / open /
	 * processing / closed / processed / withdrawn / not-issued`. Carrying the
	 * value verbatim made OpenRegister refuse the whole row with "should be one
	 * of ... but is 'advice-issued'", reported through `$output->warning()`,
	 * which does not fail an upgrade. Measured on a live `occ upgrade`: three
	 * rows, three different values, all refused.
	 *
	 * `member-consultation` needs no entry: every value it used is already one
	 * the generic schema accepts.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const LIFECYCLE = [
		'advice-request' => [
			'sent' => 'open',
			'in-progress' => 'processing',
			'advice-issued' => 'processed',
			'accounted-for' => 'closed',
			'completed' => 'closed',
		],
	];

	private const ASKS = [
		'advice-request' => [
			'subject' => 'subject',
			'body' => 'requestingBody',
			'audienceBody' => 'advisoryBody',
			'audienceType' => 'bodies',
			'deadline' => 'requestedByDate',
			'binding' => true,
			'carry' => [
				'question',
				'lifecycle',
				'relatedDecision',
				'agendaItem',
				'accountabilityText',
				'accountabilityDate',
				'publicationDate',
				'depublicationDate',
			],
		],
		'zienswijzeronde' => [
			'subject' => 'title',
			'body' => 'sharedBody',
			'audienceBody' => null,
			'audienceType' => 'bodies',
			'deadline' => 'deadline',
			'binding' => false,
			'carry' => ['subjectType', 'subjectDescription', 'cyclusStep', 'decision'],
		],
		'member-consultation' => [
			'subject' => 'question',
			'body' => 'audienceBody',
			'audienceBody' => 'audienceBody',
			'audienceType' => null,
			'deadline' => 'closesAt',
			'binding' => false,
			'carry' => [
				'description',
				'responseType',
				'choiceOptions',
				'audienceParty',
				'audienceGroup',
				'agendaItem',
				'decision',
				'opensAt',
				'anonymousResponses',
				'lifecycle',
				'results',
			],
		],
	];

	/**
	 * The three answers, and which ask each hangs off.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const ANSWERS = [
		'advies' => [
			'parent' => 'adviceRequest',
			'parentSchema' => 'advice-request',
			'position' => 'tenor',
			'text' => 'summary',
			'respondentPerson' => 'recordedBy',
			'respondentBody' => null,
			'document' => 'adviceDocument',
			'carry' => ['publicationDate', 'depublicationDate'],
			'submittedAt' => 'adviceDate',
			'status' => 'submitted',
		],
		'zienswijze' => [
			'parent' => 'ronde',
			'parentSchema' => 'zienswijzeronde',
			'position' => 'position',
			'text' => 'text',
			'respondentPerson' => null,
			'respondentBody' => 'participant',
			'document' => null,
			'carry' => ['deadline', 'processing', 'processingNotes', 'status'],
			'submittedAt' => 'submittedDate',
			'status' => null,
		],
		'member-consultation-response' => [
			'parent' => 'consultation',
			'parentSchema' => 'member-consultation',
			'position' => null,
			'text' => 'openText',
			'respondentPerson' => null,
			'respondentBody' => null,
			'document' => null,
			'carry' => ['respondentId', 'choices'],
			'submittedAt' => 'submittedAt',
			'status' => 'submitted',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface $container       Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface    $logger          Records what was migrated.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
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
		return 'Copy Decidiq advice requests, view rounds and member polls onto one consultation schema';

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
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-existing-consultations-are-carried-across
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
	 * Copy the asks first, then the answers that hang off them.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-existing-consultations-are-carried-across
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$asks    = $this->copyAsks(objectService: $objectService, output: $output);
		$answers = $this->copyAnswers(objectService: $objectService, output: $output, asks: $asks);

		$output->info(
			'Decidiq consultation fold complete: ' . count($asks) . ' consultation(s), ' . $answers . ' response(s).'
		);

	}//end migrateAll()

	/**
	 * Copy every ask onto the generic consultation.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return array<string,string> Source identifier to new consultation identifier.
	 *
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-existing-consultations-are-carried-across
	 */
	private function copyAsks(object $objectService, IOutput $output): array {
		$existing = $this->originIndex(objectService: $objectService, schema: self::TARGET);

		foreach (self::ASKS as $schema => $mapping) {
			foreach ($this->readRows(objectService: $objectService, schema: $schema, limit: 10000) as $source) {
				$origin = $this->identifierOf(object: $source);
				if ($origin === '' || isset($existing[$origin]) === true) {
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
							// These two are resolved by mapAsk() itself; a second pass would
							// resolve an already-resolved id again.
							alreadyResolved: ['askingBody', 'audienceBody'],
							payload: $this->mapAsk(
								objectService: $objectService,
								source: $source,
								mapping: $mapping,
								origin: $origin,
								sourceSlug: $schema
							),
						),
					);
					$existing[$origin] = $this->identifierOf(object: $this->toArray(entity: $saved));
				} catch (Throwable $e) {
					$output->warning('Failed to migrate a consultation: ' . $e->getMessage());
					$this->logger->warning(
						'Decidiq: consultation migration failed for one row',
						['error' => $e->getMessage(), 'schema' => $schema, 'origin' => $origin]
					);
				}//end try
			}//end foreach
		}//end foreach

		return $existing;

	}//end copyAsks()

	/**
	 * Copy every answer, bound to the consultation its ask became.
	 *
	 * An answer whose ask could not be copied is SKIPPED: `consultation` is
	 * required, and an answer bound to nothing is worse than one still readable
	 * under its original schema.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param IOutput              $output        Progress reporting.
	 * @param array<string,string> $asks          Source identifier to new consultation identifier.
	 *
	 * @return int How many answers were copied.
	 *
	 * @spec openspec/changes/one-consultation-schema/specs/one-consultation-schema/spec.md#requirement-existing-consultations-are-carried-across
	 */
	private function copyAnswers(object $objectService, IOutput $output, array $asks): int {
		$existing = $this->originIndex(objectService: $objectService, schema: self::RESPONSE_TARGET);
		$copied   = 0;

		foreach (self::ANSWERS as $schema => $mapping) {
			foreach ($this->readRows(objectService: $objectService, schema: $schema, limit: 10000) as $source) {
				$origin = $this->identifierOf(object: $source);
				if ($origin === '' || isset($existing[$origin]) === true) {
					continue;
				}

				$parent = $this->parentFor(
					objectService: $objectService,
					source: $source,
					mapping: $mapping,
					asks: $asks
				);
				if ($parent === '') {
					continue;
				}

				try {
					$objectService->setRegister(self::REGISTER);
					$objectService->setSchema(self::RESPONSE_TARGET);
					$objectService->saveObject(
						register: self::REGISTER,
						schema: self::RESPONSE_TARGET,
						object: $this->coerceToTarget(
							objectService: $objectService,
							properties: $this->declaredProperties(slug: self::RESPONSE_TARGET),
							// `consultation` is the id of the ask this run just copied.
							alreadyResolved: ['consultation'],
							payload: $this->mapAnswer(
								source: $source,
								mapping: $mapping,
								origin: $origin,
								consultation: $parent
							),
						),
					);
					$existing[$origin] = $parent;
					$copied++;
				} catch (Throwable $e) {
					$output->warning('Failed to migrate a consultation response: ' . $e->getMessage());
				}
			}//end foreach
		}//end foreach

		return $copied;

	}//end copyAnswers()

	/**
	 * The new consultation identifier an answer belongs to.
	 *
	 * 🔴 BOTH SPELLINGS, BECAUSE THE ROW MAY HOLD EITHER. A row created through
	 * the app names its parent by uuid; a SEEDED row names it by slug, because
	 * the importer stores a reference exactly as the file wrote it.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param array<string,mixed>  $source        The legacy answer row.
	 * @param array<string,mixed>  $mapping       The answer's mapping entry.
	 * @param array<string,string> $asks          Source identifier to new consultation identifier.
	 *
	 * @return string The identifier, or '' when the parent is unknown.
	 */
	private function parentFor(object $objectService, array $source, array $mapping, array $asks): string {
		$reference = trim((string)($source[(string)$mapping['parent']] ?? ''));
		if ($reference === '') {
			return '';
		}

		if (isset($asks[$reference]) === true) {
			return (string)$asks[$reference];
		}

		$legacy = $this->resolveReference(
			objectService: $objectService,
			schema: (string)$mapping['parentSchema'],
			reference: $reference
		);

		return (string)($asks[$legacy] ?? '');

	}//end parentFor()

	/**
	 * Records already copied, keyed by the source they came from.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The target schema.
	 *
	 * @return array<string,string> Source identifier to target identifier.
	 */
	private function originIndex(object $objectService, string $schema): array {
		$index = [];

		foreach ($this->readRows(objectService: $objectService, schema: $schema, limit: 10000) as $object) {
			$origin = trim((string)($object[self::ORIGIN_KEY] ?? ''));
			if ($origin !== '') {
				$index[$origin] = $this->identifierOf(object: $object);
			}
		}

		return $index;

	}//end originIndex()

	/**
	 * Map one ask onto the generic consultation shape.
	 *
	 * @param object              $objectService The OR ObjectService.
	 * @param array<string,mixed> $source        The legacy row.
	 * @param array<string,mixed> $mapping       The source's mapping entry.
	 * @param string              $origin        The source identifier.
	 * @param string              $sourceSlug    The schema this row came from,
	 *                                           which decides whose lifecycle
	 *                                           vocabulary to translate.
	 *
	 * @return array<string,mixed> The generic payload.
	 */
	private function mapAsk(
		object $objectService,
		array $source,
		array $mapping,
		string $origin,
		string $sourceSlug
	): array {
		$payload = [
			'subject' => (string)($source[(string)$mapping['subject']] ?? 'Untitled'),
			'lifecycle' => 'draft',
			'binding' => (bool)$mapping['binding'],
			self::ORIGIN_KEY => $origin,
		];

		$body = $this->resolveReference(
			objectService: $objectService,
			schema: 'governance-body',
			reference: (string)($source[(string)$mapping['body']] ?? '')
		);
		if ($body !== '') {
			$payload['askingBody'] = $body;
		}

		// A source with no fixed audience carried one of its own, in its own
		// spelling: `politicalGroup` and `nc-group` become `political-group` and
		// `user-group`.
		$audienceType = ($mapping['audienceType'] ?? null);
		if (is_string($audienceType) === false) {
			$audienceType = $this->audienceFor(value: (string)($source['audienceType'] ?? ''));
		}

		$payload['audienceType'] = $audienceType;

		$audienceBody = ($mapping['audienceBody'] ?? null);
		if (is_string($audienceBody) === true) {
			$resolved = $this->resolveReference(
				objectService: $objectService,
				schema: 'governance-body',
				reference: (string)($source[$audienceBody] ?? '')
			);
			if ($resolved !== '') {
				$payload['audienceBody'] = $resolved;
			}
		}

		$deadline = trim((string)($source[(string)$mapping['deadline']] ?? ''));
		if ($deadline !== '') {
			$payload['deadline'] = $deadline;
		}

		$carried = $this->carried(source: $source, keys: (array)$mapping['carry']);
		$lifecycle = (string)($carried['lifecycle'] ?? '');
		if ($lifecycle !== '' && isset(self::LIFECYCLE[$sourceSlug][$lifecycle]) === true) {
			$carried['lifecycle'] = self::LIFECYCLE[$sourceSlug][$lifecycle];
		}

		return array_merge($payload, $carried);

	}//end mapAsk()

	/**
	 * The generic audience value for a member poll's own spelling.
	 *
	 * @param string $value The legacy value.
	 *
	 * @return string The generic value.
	 */
	private function audienceFor(string $value): string {
		return match ($value) {
			'politicalGroup' => 'political-group',
			'nc-group' => 'user-group',
			'body-members' => 'body-members',
			default => 'bodies',
		};

	}//end audienceFor()

	/**
	 * Map one answer onto the generic response shape.
	 *
	 * @param array<string,mixed> $source       The legacy row.
	 * @param array<string,mixed> $mapping      The answer's mapping entry.
	 * @param string              $origin       The source identifier.
	 * @param string              $consultation The new consultation identifier.
	 *
	 * @return array<string,mixed> The generic payload.
	 */
	private function mapAnswer(array $source, array $mapping, string $origin, string $consultation): array {
		$payload = [
			'consultation' => $consultation,
			'status' => (string)($mapping['status'] ?? $source['status'] ?? 'submitted'),
			self::ORIGIN_KEY => $origin,
		];

		foreach (['position', 'text', 'respondentPerson', 'respondentBody', 'document', 'submittedAt'] as $target) {
			$key = ($mapping[$target] ?? null);
			if (is_string($key) === false) {
				continue;
			}

			$value = ($source[$key] ?? null);
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			// `no-advice` and `no-view` said the same thing under two names.
			if ($target === 'position' && in_array($value, ['no-advice', 'no-view'], true) === true) {
				$value = 'none';
			}

			$payload[$this->responseKeyFor(target: $target)] = $value;
		}

		return array_merge($payload, $this->carried(source: $source, keys: (array)$mapping['carry']));

	}//end mapAnswer()

	/**
	 * The generic property one mapped answer field is written to.
	 *
	 * @param string $target The mapping key.
	 *
	 * @return string The property name.
	 */
	private function responseKeyFor(string $target): string {
		if ($target === 'document') {
			return 'responseDocument';
		}

		return $target;

	}//end responseKeyFor()

	/**
	 * The values a row carries across under their own names.
	 *
	 * @param array<string,mixed> $source The legacy row.
	 * @param array<int,string>   $keys   The keys to carry.
	 *
	 * @return array<string,mixed> The carried values.
	 */
	private function carried(array $source, array $keys): array {
		$carried = [];

		foreach ($keys as $key) {
			$value = ($source[$key] ?? null);
			// A NULL IS NOT AN ABSENT VALUE TO THE VALIDATOR, and an empty string
			// records nothing worth keeping.
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			// Two legacy names differ from the generic one, and both mean the
			// decision the record relates to.
			$target = match ($key) {
				'decision' => 'relatedDecision',
				'cyclusStep' => 'cycleStep',
				default => $key,
			};

			$carried[$target] = $value;
		}

		return $carried;

	}//end carried()
}//end class
