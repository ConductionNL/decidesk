<?php
/**
 * Decidiq MigrateRegulationsToGoverningDocuments.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * fold-regulations-into-governing-documents change: copies every `regeling` and
 * `regeling-versie` row onto the generic `governing-document` and
 * `governing-document-versie`.
 *
 * 🔴 PURELY ADDITIVE. The source rows are never edited and never deleted, and
 * both schemas keep their definitions (`active:false`, `hardDelete:false`), so
 * a rollback still finds its data.
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
 * Copies regulations onto the generic governing-document schemas.
 *
 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md
 */
class MigrateRegulationsToGoverningDocuments implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The key recording which source row a copied record came from.
	 *
	 * 🔑 THE IDEMPOTENCY KEY. Unlike a body configuration, an organisation has
	 * many regulations, so only the source row can be the identity.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

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
		return 'Copy Decidiq regulations onto the generic governing documents';

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
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
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
		// that as a warning, which does not fail an upgrade. The sibling
		// migrations carry the same comment for the same measured reason.
		$objectService->runAsSystem(
			function () use ($objectService, $output): void {
				$this->migrateAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Copy the documents first, then their versions.
	 *
	 * Order matters: a version references its document, so the documents have to
	 * exist and be indexable by origin before the versions can point at them.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$documents = $this->copyDocuments(objectService: $objectService, output: $output);
		$versions  = $this->copyVersions(objectService: $objectService, output: $output, documents: $documents);

		$output->info(
			'Decidiq regulation fold complete: ' . count($documents) . ' document(s), ' . $versions . ' version(s).'
		);

	}//end migrateAll()

	/**
	 * Copy every regulation onto a governing document.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return array<string,string> Source identifier to new document identifier.
	 *
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
	 */
	private function copyDocuments(object $objectService, IOutput $output): array {
		$existing = $this->originIndex(objectService: $objectService, schema: 'governing-document');

		foreach ($this->readRows(objectService: $objectService, schema: 'regeling', limit: 10000) as $source) {
			$origin = $this->identifierOf(object: $source);
			if ($origin === '' || isset($existing[$origin]) === true) {
				continue;
			}

			try {
				$objectService->setRegister(self::REGISTER);
				$objectService->setSchema('governing-document');
				$saved = $objectService->saveObject(
					register: self::REGISTER,
					schema: 'governing-document',
					object: $this->mapDocument(
						source: $source,
						origin: $origin,
						body: $this->resolveBody(
							objectService: $objectService,
							reference: (string)($source['determiningBody'] ?? '')
						)
					),
				);
				$existing[$origin] = $this->identifierOf(object: $this->toArray(entity: $saved));
			} catch (Throwable $e) {
				$output->warning('Failed to migrate a regulation: ' . $e->getMessage());
				$this->logger->warning(
					'Decidiq: governing-document migration failed for one row',
					['error' => $e->getMessage(), 'origin' => $origin]
				);
			}//end try
		}//end foreach

		return $existing;

	}//end copyDocuments()

	/**
	 * Copy every regulation version onto a governing-document version.
	 *
	 * A version whose parent could not be copied is SKIPPED rather than written
	 * without one: `document` is required, and a version bound to nothing is
	 * worse than a version that is still readable under its original schema.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param IOutput              $output        Progress reporting.
	 * @param array<string,string> $documents     Source identifier to new document identifier.
	 *
	 * @return int How many versions were copied.
	 *
	 * @spec openspec/changes/fold-regulations-into-governing-documents/specs/fold-regulations-into-governing-documents/spec.md#requirement-existing-regulations-are-carried-across
	 */
	private function copyVersions(object $objectService, IOutput $output, array $documents): int {
		$existing = $this->originIndex(objectService: $objectService, schema: 'governing-document-versie');
		$copied   = 0;

		foreach ($this->readRows(objectService: $objectService, schema: 'regeling-versie', limit: 10000) as $source) {
			$origin = $this->identifierOf(object: $source);
			if ($origin === '' || isset($existing[$origin]) === true) {
				continue;
			}

			$parent = $this->parentDocumentFor(
				objectService: $objectService,
				source: $source,
				documents: $documents
			);
			if ($parent === '') {
				continue;
			}

			try {
				$objectService->setRegister(self::REGISTER);
				$objectService->setSchema('governing-document-versie');
				$objectService->saveObject(
					register: self::REGISTER,
					schema: 'governing-document-versie',
					object: $this->mapVersion(source: $source, origin: $origin, document: $parent),
				);
				$existing[$origin] = $parent;
				$copied++;
			} catch (Throwable $e) {
				$output->warning('Failed to migrate a regulation version: ' . $e->getMessage());
			}
		}//end foreach

		return $copied;

	}//end copyVersions()

	/**
	 * The new document identifier a legacy version belongs to.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param array<string,mixed>  $source        The legacy version row.
	 * @param array<string,string> $documents     Source identifier to new document identifier.
	 *
	 * @return string The identifier, or '' when the parent is unknown.
	 */
	private function parentDocumentFor(object $objectService, array $source, array $documents): string {
		$reference = trim((string)($source['regulation'] ?? ''));
		if ($reference === '') {
			return '';
		}

		// 🔴 BOTH SPELLINGS, BECAUSE THE ROW MAY HOLD EITHER. A row created
		// through the app names its parent by uuid; a SEEDED row names it by
		// slug, because the importer stores a reference exactly as the file
		// wrote it. The index is keyed on the source row's own identifier, so
		// the raw reference matches the first case directly and the resolved one
		// matches the second. Trying only the resolved form would silently
		// orphan every version on an instance whose regulations came from the
		// app rather than a seed.
		if (isset($documents[$reference]) === true) {
			return (string)$documents[$reference];
		}

		$legacy = $this->resolveReference(
			objectService: $objectService,
			schema: 'regeling',
			reference: $reference
		);

		return (string)($documents[$legacy] ?? '');

	}//end parentDocumentFor()

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
	 * Map one regulation onto the generic document shape.
	 *
	 * @param array<string,mixed> $source The legacy row.
	 * @param string              $origin The source identifier.
	 * @param string              $body   The already-resolved governing body.
	 *
	 * @return array<string,mixed> The generic payload.
	 */
	private function mapDocument(array $source, string $origin, string $body): array {
		$payload = [
			'type' => (string)($source['type'] ?? 'other'),
			'citationTitle' => (string)($source['citationTitle'] ?? ''),
			'status' => (string)($source['status'] ?? 'in-preparation'),
			self::ORIGIN_KEY => $origin,
		];

		if ($body !== '') {
			$payload['governingBody'] = $body;
		}

		// `cvdrIdentifier` is the only field that changes name: it was called
		// after one national register, and the generic schema calls it what it
		// is.
		$renamed = ['cvdrIdentifier' => 'externalRegisterIdentifier'];
		foreach (['officialTitle', 'statutoryBasis', 'cvdrIdentifier', 'currentVersionNumber', 'currentEffectiveDate'] as $key) {
			$value = ($source[$key] ?? null);
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$payload[($renamed[$key] ?? $key)] = $value;
		}

		return $payload;

	}//end mapDocument()

	/**
	 * Map one regulation version onto the generic version shape.
	 *
	 * @param array<string,mixed> $source   The legacy row.
	 * @param string              $origin   The source identifier.
	 * @param string              $document The new parent document identifier.
	 *
	 * @return array<string,mixed> The generic payload.
	 */
	private function mapVersion(array $source, string $origin, string $document): array {
		$payload = [
			'document' => $document,
			'versionNumber' => (int)($source['versionNumber'] ?? 1),
			'status' => (string)($source['status'] ?? 'draft'),
			self::ORIGIN_KEY => $origin,
		];

		foreach (['determinedBy', 'effectiveDate', 'expiryDate', 'notes'] as $key) {
			$value = ($source[$key] ?? null);
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$payload[$key] = $value;
		}

		return $payload;

	}//end mapVersion()
}//end class
