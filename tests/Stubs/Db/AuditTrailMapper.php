<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\AuditTrailMapper.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399)
 * ----------------------------------------
 * Stands in for the real mapper only when the OpenRegister app is not
 * installed. Abstract on purpose: decidiq never instantiates it in tests, it
 * only mocks it, and an abstract stub cannot drift into carrying behaviour.
 * The declared signatures are copied VERBATIM (names included — decidiq calls
 * them with named arguments) from
 * ConductionNL/openregister@origin/development, lib/Db/AuditTrailMapper.php:
 *
 *   public function createAuditTrailEntry(ObjectEntity $object, string $action, array $context = []): AuditTrail   (line 2065)
 *   public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = ['created' => 'DESC'], ?string $search = null): array   (line 265)
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for AuditTrailMapper; declares only the members decidiq touches, in
 * exact signature parity with production.
 */
abstract class AuditTrailMapper {

	/**
	 * Create a custom audit trail entry attached to an object.
	 *
	 * @param ObjectEntity $object The object the entry relates to
	 * @param string $action The namespaced action string
	 * @param array $context Additional context data (persisted in `changed`)
	 *
	 * @return AuditTrail The created audit trail entry
	 */
	abstract public function createAuditTrailEntry(
		ObjectEntity $object,
		string $action,
		array $context = [],
	): AuditTrail;

	/**
	 * Find audit trail rows matching the filters.
	 *
	 * @param int|null $limit Maximum rows
	 * @param int|null $offset Pagination offset
	 * @param array|null $filters Column equality filters (comma-separated values become IN)
	 * @param array|null $sort Column => direction map
	 * @param string|null $search LIKE search over the changed column
	 *
	 * @return array The matching AuditTrail entities
	 */
	abstract public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $sort = ['created' => 'DESC'],
		?string $search = null,
	): array;
}//end class
