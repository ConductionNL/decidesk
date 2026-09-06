<?php
/**
 * Decidiq ReadsLegacyRows.
 *
 * The four things every supersession migration in this app needs and had
 * written out for itself: read a superseded schema's rows, normalise an
 * OpenRegister entity to an array, and resolve a governance-body reference that
 * a seed wrote as a slug where the target schema wants a uuid.
 *
 * 🔴 EXTRACTED, NOT INVENTED. These bodies were byte-identical in
 * MigrateVveToBodyConfiguration, MigrateKascommissieToAuditStatement and
 * MigrateQuestionsToAgendaItems — including the two comments that record what
 * they cost to get right. Three copies meant three places to fix the next time
 * OpenRegister changes how a slug resolves, and the copies had already started
 * to drift in their signatures.
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
 * Shared reads for the migrations that copy a superseded schema's rows.
 *
 * The using class must declare a `REGISTER` constant naming the register these
 * schemas belong to, and expose a logger through {@see self::migrationLogger()}.
 *
 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
 */
trait ReadsLegacyRows {
	/**
	 * The logger the shared reads report through.
	 *
	 * Declared rather than assumed: a trait cannot require a promoted
	 * constructor property, and reaching straight into `$this->logger` would
	 * bind this trait to one spelling of a field it does not own.
	 *
	 * @return LoggerInterface The logger.
	 */
	abstract protected function migrationLogger(): LoggerInterface;

	/**
	 * Every row of one schema, as arrays.
	 *
	 * A schema that does not exist is not an error here: a supersession
	 * migration runs on installs that never had the legacy schema, and on those
	 * "nothing to migrate" is the correct and expected outcome.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The schema slug to read.
	 * @param int    $limit         How many rows to read at most.
	 *
	 * @return array<int, array<string,mixed>> The rows.
	 */
	protected function readRows(object $objectService, string $schema, int $limit = 1000): array {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema($schema);
			$found = $objectService->findAll(['limit' => $limit]);
		} catch (Throwable $e) {
			$this->migrationLogger()->info(
				'Decidiq: no rows for a superseded schema',
				['schema' => $schema, 'error' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		foreach (($found ?? []) as $entity) {
			$object = $this->toArray(entity: $entity);
			if ($object !== null) {
				$rows[] = $object;
			}
		}

		return $rows;

	}//end readRows()

	/**
	 * The identifier to use for a source row's governance body.
	 *
	 * 🔴 THE LEGACY ROWS HOLD A SLUG WHERE THE SCHEMA WANTS A UUID. A seed
	 * writes `governanceBody: vve-parkstaete`, and OpenRegister resolves slug
	 * references at IMPORT time — but a direct saveObject() validates strictly,
	 * so copying the value across fails with "Property 'governanceBody' should
	 * match format 'uuid' but 'vve-parkstaete' does not". Measured on a live
	 * instance.
	 *
	 * An unresolvable slug is returned AS IS rather than blanked: the save then
	 * fails loudly for that one row instead of silently writing a record bound
	 * to nothing.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $reference     The body reference as the source holds it.
	 *
	 * @return string The resolved identifier, or '' when the source named none.
	 */
	protected function resolveBody(object $objectService, string $reference): string {
		return $this->resolveReference(
			objectService: $objectService,
			schema: 'governance-body',
			reference: $reference
		);

	}//end resolveBody()

	/**
	 * The identifier to use for a reference to any schema.
	 *
	 * 🔴 EVERY `$ref` PROPERTY IN THIS REGISTER DECLARES `format: uuid`, AND A
	 * SEED STORES THE SLUG IT WROTE. Measured on a live instance: seeded agenda
	 * items carry `meeting: "raadsvergadering-2025-01-15"`. So a migration that
	 * copies a reference across verbatim hands a slug to a property that
	 * validates as a uuid, `saveObject()` rejects the whole row, and the step
	 * reports it as a warning — which does not fail an upgrade. The migration
	 * then says "0 migrated, N skipped" and nothing anyone reads says why.
	 *
	 * An unresolvable slug is returned AS IS rather than blanked: the save then
	 * fails loudly for that one row instead of silently writing a record bound
	 * to nothing.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The schema the reference points at.
	 * @param string $reference     The reference as the source holds it.
	 *
	 * @return string The resolved identifier, or '' when the source named none.
	 */
	/**
	 * The properties one shipped schema declares, keyed by property name.
	 *
	 * Reads the same files the app ships and merges them the way
	 * SettingsService::mergeRegisterFragments does: the base register first,
	 * then every `register.d/*.json` in sorted order, each overlaying the base
	 * by schema key. That order is not cosmetic — ADR-037 resolves a same-key
	 * schema by letting the LAST fragment win, so a different order is a
	 * different schema.
	 *
	 * Returns an empty array when the slug is not found, and the caller then
	 * coerces nothing rather than guessing.
	 *
	 * @param string $slug The target schema slug.
	 *
	 * @return array<string,mixed> The declared properties.
	 */
	protected function declaredProperties(string $slug): array {
		static $cache = [];

		if (isset($cache[$slug]) === true) {
			return $cache[$slug];
		}

		$cache[$slug] = [];
		foreach ($this->mergedSchemaDefinitions() as $definition) {
			if (($definition['slug'] ?? null) === $slug) {
				$cache[$slug] = ($definition['properties'] ?? []);
				break;
			}
		}

		return $cache[$slug];

	}//end declaredProperties()

	/**
	 * Every shipped schema definition, merged the way the app merges them.
	 *
	 * The base register first, then every `register.d/*.json` in sorted order,
	 * each overlaying by schema key. That order is not cosmetic: ADR-037
	 * resolves a same-key schema by letting the LAST fragment win, so a
	 * different order is a different schema.
	 *
	 * @return array<string,array<string,mixed>> Definitions keyed by schema key.
	 */
	private function mergedSchemaDefinitions(): array {
		static $merged = null;

		if ($merged !== null) {
			return $merged;
		}

		$settingsDir = __DIR__ . '/../Settings';
		$fragments   = glob($settingsDir . '/register.d/*.json');
		if ($fragments === false) {
			$fragments = [];
		}

		sort($fragments);
		$merged = [];
		foreach (array_merge([$settingsDir . '/decidesk_register.json'], $fragments) as $file) {
			foreach ($this->schemasOfFile(file: $file) as $key => $definition) {
				$merged[$key] = $this->deepMerge(base: ($merged[$key] ?? []), overlay: $definition);
			}
		}

		return $merged;

	}//end mergedSchemaDefinitions()

	/**
	 * Merge one schema definition onto another, by key, recursively.
	 *
	 * 🔴 SHALLOW IS THE WRONG MERGE, AND IT READS AS AN EMPTY SCHEMA. ADR-037
	 * fragments overlay a schema by key, and several of them carry only a
	 * `slug` or a single changed property. `array_merge()` replaces `properties`
	 * wholesale, so the merged `Consultation` came back with ONE property
	 * instead of its full set, every format declaration vanished, and
	 * coerceToTarget() silently coerced nothing.
	 *
	 * SettingsService::deepMergeConfig() is what the app actually imports with.
	 * This mirrors the part of it a schema definition needs: associative arrays
	 * recurse, everything else overwrites.
	 *
	 * @param array<string,mixed> $base    The accumulated definition.
	 * @param array<string,mixed> $overlay The fragment's definition.
	 *
	 * @return array<string,mixed> The merged definition.
	 */
	private function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$isAssoc = (is_array($value) === true
				&& $value !== []
				&& array_keys($value) !== range(0, (count($value) - 1)));

			if ($isAssoc === true && is_array(($base[$key] ?? null)) === true) {
				$base[$key] = $this->deepMerge(base: $base[$key], overlay: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;

	}//end deepMerge()

	/**
	 * The schema definitions one shipped file declares.
	 *
	 * A file that cannot be read or parsed yields nothing and says so in the
	 * log: a migration that guesses at a schema is worse than one that coerces
	 * nothing.
	 *
	 * @param string $file Absolute path to a register JSON file.
	 *
	 * @return array<string,array<string,mixed>> Definitions keyed by schema key.
	 */
	private function schemasOfFile(string $file): array {
		if (is_readable($file) === false) {
			return [];
		}

		$raw = file_get_contents($file);
		if ($raw === false) {
			return [];
		}

		try {
			$decoded = json_decode(json: $raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->migrationLogger()->warning(
				'Decidiq: could not read a register file while resolving a migration target',
				['file' => basename($file), 'exception' => $e->getMessage()]
			);
			return [];
		}

		$schemas = ($decoded['components']['schemas'] ?? []);

		return array_filter($schemas, static fn ($definition): bool => is_array($definition));

	}//end schemasOfFile()

	/**
	 * Coerce a payload to the shapes the TARGET schema declares.
	 *
	 * 🔴 WHY THIS EXISTS RATHER THAN A LONGER `REFERENCES` MAP. Every migration
	 * in this app carries a hand-written map of which properties are references.
	 * Measured across the five supersession migrations on 2026-09-05, that map
	 * was missing an entry in SEVEN source/target pairs, and each omission has
	 * the same consequence: a seeded slug is copied verbatim into a property the
	 * target declares as `format: uuid`, OpenRegister refuses the whole record,
	 * and the migration reports it through `$output->warning()` — which does not
	 * fail an upgrade, so nobody sees it.
	 *
	 * The target schema already says which properties are references and what
	 * they point at: `format: uuid` plus `$ref: <slug>`. Reading that is one
	 * source of truth instead of a second copy that drifts.
	 *
	 * Also repairs `format: date-time` properties holding a bare `YYYY-MM-DD`,
	 * which is the other shape the same upgrade reported.
	 *
	 * 🔴 IT MUST NOT RESOLVE TWICE. The caller has already resolved the keys in
	 * its own `REFERENCES` map, and a second pass over those turned
	 * `uuid-of-gemeenteraad` into `uuid-of-uuid-of-gemeenteraad`. In production
	 * the first pass yields a real uuid and {@see self::resolveReference()}
	 * returns it untouched, so the damage was invisible there: the unit tests'
	 * fake ids are what exposed it. `$alreadyResolved` names those keys, so
	 * this pass only ever sees values nobody has touched.
	 *
	 * @param object              $objectService   The OR ObjectService.
	 * @param array<string,mixed> $properties      The target schema's declared properties.
	 * @param array<string,mixed> $payload         The payload about to be saved.
	 * @param array<int,string>   $alreadyResolved Payload keys the caller resolved itself.
	 *
	 * @return array<string,mixed> The payload, with references resolved.
	 */
	protected function coerceToTarget(
		object $objectService,
		array $properties,
		array $payload,
		array $alreadyResolved = []
	): array {
		foreach ($payload as $key => $value) {
			if (in_array($key, $alreadyResolved, true) === true || is_string($value) === false) {
				continue;
			}

			$declared = ($properties[$key] ?? null);
			if (is_array($declared) === false || $value === '') {
				continue;
			}

			$payload[$key] = $this->coerceValue(
				objectService: $objectService,
				declared: $declared,
				value: $value
			);
		}

		return $payload;

	}//end coerceToTarget()

	/**
	 * One value, coerced to the shape one declared property asks for.
	 *
	 * @param object              $objectService The OR ObjectService.
	 * @param array<string,mixed> $declared      The property declaration.
	 * @param string              $value         The value as the source holds it.
	 *
	 * @return string The value to store.
	 */
	private function coerceValue(object $objectService, array $declared, string $value): string {
		$format = ($declared['format'] ?? '');

		if ($format === 'uuid') {
			// `$ref` names the schema the value points at. Without it there is
			// nothing to resolve against, so the value is left alone rather
			// than guessed at.
			$target = trim((string)($declared['$ref'] ?? ''));
			if ($target === '') {
				return $value;
			}

			return $this->resolveReference(
				objectService: $objectService,
				schema: strtolower($target),
				reference: $value
			);
		}

		// A date where a date-time is declared. The seeds carry `2026-02-16`
		// for a `deadline`, and OpenRegister refuses the record rather than
		// widening it.
		if ($format === 'date-time' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value . 'T00:00:00+00:00';
		}

		return $value;

	}//end coerceValue()

	/**
	 * The identifier to use for a reference to any schema.
	 *
	 * 🔴 EVERY `$ref` PROPERTY IN THIS REGISTER DECLARES `format: uuid`, AND A
	 * SEED STORES THE SLUG IT WROTE. Measured on a live instance: seeded agenda
	 * items carry `meeting: "raadsvergadering-2025-01-15"`. So a migration that
	 * copies a reference across verbatim hands a slug to a property that
	 * validates as a uuid, `saveObject()` rejects the whole row, and the step
	 * reports it as a warning — which does not fail an upgrade. The migration
	 * then says "0 migrated, N skipped" and nothing anyone reads says why.
	 *
	 * An unresolvable slug is returned AS IS rather than blanked: the save then
	 * fails loudly for that one row instead of silently writing a record bound
	 * to nothing.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The schema the reference points at.
	 * @param string $reference     The reference as the source holds it.
	 *
	 * @return string The resolved identifier, or '' when the source named none.
	 */
	protected function resolveReference(object $objectService, string $schema, string $reference): string {
		$reference = trim($reference);
		if ($reference === '' || preg_match('/^[0-9a-f-]{36}$/i', $reference) === 1) {
			return $reference;
		}

		return ($this->uuidForSlug(objectService: $objectService, schema: $schema, slug: $reference) ?? $reference);

	}//end resolveReference()

	/**
	 * The UUID a slug names in one schema, or null when it cannot be resolved.
	 *
	 * 🔑 THE SLUG LIVES IN `@self`, NOT IN THE OBJECT BODY. A seeded `slug:` key
	 * is an import-time identifier OpenRegister keeps as metadata, not a stored
	 * property, so filtering `['slug' => …]` matches nothing.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The schema to look the slug up in.
	 * @param string $slug          The slug.
	 *
	 * @return string|null The UUID, or null.
	 */
	protected function uuidForSlug(object $objectService, string $schema, string $slug): ?string {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema($schema);
			$rows = $objectService->findAll(['filters' => ['@self' => ['slug' => $slug]], 'limit' => 1]);
		} catch (Throwable $e) {
			$this->migrationLogger()->warning(
				'Decidiq: could not resolve a slug during a supersession migration',
				['schema' => $schema, 'slug' => $slug, 'error' => $e->getMessage()]
			);
			return null;
		}

		foreach (($rows ?? []) as $row) {
			$body = $this->toArray(entity: $row);
			if ($body === null) {
				continue;
			}

			$uuid = (string)($body['id'] ?? $body['uuid'] ?? '');
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;

	}//end uuidForSlug()

	/**
	 * Normalise an OpenRegister entity to an array.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return array<string,mixed>|null The array, or null when unusable.
	 */
	protected function toArray(mixed $entity): ?array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$serialised = $entity->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return null;

	}//end toArray()

	/**
	 * The identifier OpenRegister gave a saved or read object.
	 *
	 * @param array<string,mixed>|null $object The object.
	 *
	 * @return string The identifier, or ''.
	 */
	protected function identifierOf(?array $object): string {
		if ($object === null) {
			return '';
		}

		return trim((string)($object['id'] ?? $object['uuid'] ?? ''));

	}//end identifierOf()
}//end trait
