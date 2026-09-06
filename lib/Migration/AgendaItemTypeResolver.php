<?php
/**
 * Decidiq AgendaItemTypeResolver.
 *
 * Finds, or creates, the configurable AgendaItemType a migrated row belongs to.
 *
 * 🔑 IDENTITY IS (owningBody, name). A type is per body because the schema says
 * so: two councils in one instance each get their own kinds, and neither sees
 * the other's submission window or support threshold.
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

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the agenda-item type a migrated question becomes.
 *
 * Separate from the migration that uses it because "which configurable kind is
 * this, and does it exist yet" is a different job from "copy these rows", and
 * it is the half worth testing on its own.
 *
 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
 */
class AgendaItemTypeResolver {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The schema holding the configurable kinds.
	 *
	 * @var string
	 */
	private const TYPE_SCHEMA = 'agenda-item-type';

	/**
	 * Types already resolved this run, keyed by body and name.
	 *
	 * Without this a hundred questions from one council would each look up, and
	 * then each create, their own copy of the same kind.
	 *
	 * @var array<string, string>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Records what could not be resolved.
	 *
	 * @return void
	 */
	public function __construct(private readonly LoggerInterface $logger) {
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
	 * The type identifier for a kind owned by a body, creating it when absent.
	 *
	 * An upgrade of an existing install has the legacy rows but no types at all,
	 * so refusing to create one would migrate nothing on exactly the installs
	 * this migration exists for.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $name          The kind's name.
	 * @param string $bodyReference The owning body, as the source row holds it.
	 *
	 * @return string The type identifier, or '' when it could not be resolved.
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	public function resolve(object $objectService, string $name, string $bodyReference): string {
		$body = $this->resolveBody(objectService: $objectService, reference: $bodyReference);
		$key  = ($body . '|' . $name);

		if (isset($this->cache[$key]) === true) {
			return $this->cache[$key];
		}

		$found = $this->find(objectService: $objectService, name: $name, body: $body);
		if ($found !== '') {
			$this->cache[$key] = $found;
			return $found;
		}

		$created = $this->create(objectService: $objectService, name: $name, body: $body);
		if ($created !== '') {
			$this->cache[$key] = $created;
		}

		return $created;

	}//end resolve()

	/**
	 * An existing type with this name for this body, or ''.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $name          The type name.
	 * @param string $body          The resolved owning body, or ''.
	 *
	 * @return string The identifier, or ''.
	 *
	 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md#requirement-existing-questions-are-carried-across
	 */
	public function find(object $objectService, string $name, string $body): string {
		foreach ($this->readRows(objectService: $objectService, schema: self::TYPE_SCHEMA) as $object) {
			if ((string)($object['name'] ?? '') !== $name) {
				continue;
			}

			if (trim((string)($object['owningBody'] ?? '')) !== $body) {
				continue;
			}

			$uuid = $this->identifierOf(object: $object);
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return '';

	}//end find()

	/**
	 * Create a type for a body that has none.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $name          The type name.
	 * @param string $body          The resolved owning body, or ''.
	 *
	 * @return string The identifier, or '' when the save failed.
	 */
	private function create(object $objectService, string $name, string $body): string {
		$payload = ['name' => $name, 'isDraft' => false, 'active' => true];

		// An empty owningBody is left UNSET rather than written as '': the
		// property is a uuid reference, and the validator rejects an empty
		// string where it accepts an absent key.
		if ($body !== '') {
			$payload['owningBody'] = $body;
		}

		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema(self::TYPE_SCHEMA);
			$created = $objectService->saveObject(
				register: self::REGISTER,
				schema: self::TYPE_SCHEMA,
				object: $payload,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not create an agenda-item type during a migration',
				['name' => $name, 'body' => $body, 'error' => $e->getMessage()]
			);
			return '';
		}

		return $this->identifierOf(object: $this->toArray(entity: $created));

	}//end create()
}//end class
