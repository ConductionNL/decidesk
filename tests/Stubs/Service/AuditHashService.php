<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\AuditHashService.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399)
 * ----------------------------------------
 * Stands in for the real service only when the OpenRegister app is not
 * installed. Abstract on purpose: decidiq only mocks it. Signature copied
 * VERBATIM (names included — decidiq calls it with a named argument) from
 * ConductionNL/openregister@origin/development, lib/Service/AuditHashService.php:
 *
 *   public function verifyChain(?int $from = null, ?int $to = null): array   (line 1117)
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub for AuditHashService; declares only the member decidiq touches, in
 * exact signature parity with production.
 */
abstract class AuditHashService {

	/**
	 * Verify the platform audit hash chain over an optional id range.
	 *
	 * @param int|null $from Start entry ID (inclusive), null for genesis
	 * @param int|null $to End entry ID (inclusive), null for end
	 *
	 * @return array{valid: bool, entriesVerified: int, brokenAt: int|null, skippedNullHashes: int, purgedTombstones: int, range?: array{from: int, to: int}}
	 */
	abstract public function verifyChain(?int $from = null, ?int $to = null): array;
}//end class
