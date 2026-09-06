<?php

/**
 * Decidiq register persistence.
 *
 * The OpenRegister reads and writes a decidiq service needs, kept out of the
 * services themselves so each holds only its rules and not the shape of the
 * storage layer underneath.
 *
 * Extracted from ApprovalRouteService as ApprovalRouteStore and generalised
 * when GovernanceBodyCommandService became its second consumer. The alternative
 * was a second store with the same three methods, which is the "second store
 * that drifts" hazard in miniature.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * Reads and writes decidiq's OpenRegister objects.
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */
class RegisterObjectStore {
	/**
	 * The register every schema here lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * TYPED INJECTION, not a container lookup. ADR-083 rule 1: a dependency
	 * fetched by string from the container is declared nowhere a reader or a
	 * gate can see it. `ObjectServiceInterface` is aliased to OpenRegister's
	 * ObjectService in Application::register() — an alias, so it resolves only
	 * when something asks for it, and an instance without OpenRegister still
	 * boots and fails at the route that needed the data.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Write an object.
	 *
	 * With a uuid this is a FULL REPLACE, not a merge: OpenRegister validates
	 * `$object` against the whole schema (a missing required property is a
	 * 400) and drops every stored field the payload omits. A caller holding a
	 * partial payload wants {@see self::patch()} instead.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The COMPLETE object.
	 * @param string|null $uuid The uuid when updating.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	public function save(string $schema, array $object, ?string $uuid = null): array {
		$stored = $this->objectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: $schema,
			uuid: $uuid,
		);

		return $this->normalise(row: $stored);
	}//end save()

	/**
	 * Merge a partial payload onto an existing object.
	 *
	 * `save()` with a uuid is a FULL REPLACE: OpenRegister validates the payload
	 * against the whole schema, so a partial payload 400s on every required
	 * property it omits — and would silently erase the omitted fields on a
	 * schema without required properties. This delegates to OpenRegister's
	 * `patchObject()`, the sanctioned read-merge-save path: a key absent from
	 * the payload is preserved, an explicit null clears the stored value, and
	 * the merged result still passes schema validation, the audit trail and
	 * event dispatch.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $data The partial data to merge.
	 * @param string $uuid The object to patch.
	 *
	 * @return array<string, mixed> The patched object.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	public function patch(string $schema, array $data, string $uuid): array {
		$stored = $this->objectService->patchObject(
			objectId: $uuid,
			data: $data,
			register: self::REGISTER,
			schema: $schema,
		);

		return $this->normalise(row: $stored);
	}//end patch()

	/**
	 * Read ONE object by its uuid, or null when it resolves to nothing.
	 *
	 * THE resolving form for "give me object X". A top-level `id` (or `uuid`)
	 * key in a findAll() filter array is NOT: OpenRegister applies filters to
	 * the object's own JSON properties, and an object's identity lives in
	 * `@self`, so such a filter matches NOTHING — silently, which is how
	 * decidiq's conclusion announcer resolved every route's provenance to
	 * empty and skipped every cross-app announcement. Same defect class as
	 * dossiq#1686.
	 *
	 * Runs as the acting user, so OR's register RBAC and multitenancy decide:
	 * an object the caller may not reach comes back null, exactly like one
	 * that does not exist.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid The object's uuid.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function find(string $schema, string $uuid): ?array {
		if (trim($uuid) === '') {
			return null;
		}

		$entity = $this->objectService->find(
			id: $uuid,
			register: self::REGISTER,
			schema: $schema,
		);
		if ($entity === null) {
			return null;
		}

		return $this->normalise(row: $entity);
	}//end find()

	/**
	 * Read objects.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	public function findAll(string $schema, array $filters): array {
		$results = $this->objectService->findAll(
			[
				'filters' => (['register' => self::REGISTER, 'schema' => $schema] + $filters),
			]
		);
		// No is_array() guard: ObjectServiceInterface::findAll() is typed `: array`,
		// so the check was unreachable — phpstan proved it. It was defensible
		// against the untyped container lookup this class used to do; against the
		// typed injection it is dead code pretending to be caution.
		$rows = [];
		foreach ($results as $row) {
			$rows[] = $this->normalise(row: $row);
		}

		return $rows;
	}//end findAll()

	/**
	 * Collapse OpenRegister's array-or-entity shape.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The array form.
	 */
	private function normalise(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		return (array)$row;
	}//end normalise()

}//end class
