<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\AuditTrail.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399)
 * ----------------------------------------
 * Stands in for the real entity only when the OpenRegister app is not
 * installed. Matched against ConductionNL/openregister@origin/development,
 * lib/Db/AuditTrail.php:
 *
 *   class AuditTrail extends Entity implements JsonSerializable   (line 96)
 *
 * All getters/setters decidiq touches (getUuid/getAction/getChanged/getUser/
 * getCreated/getHash/getPreviousHash/getObjectUuid and their setters) are
 * `@method` docblock tags in production, served by Entity::__call over the
 * protected properties — so this stub declares ONLY the properties and lets
 * the same Entity::__call serve them. Declaring them as concrete methods here
 * would let PHPUnit configure mocks production explodes on (see the
 * ObjectEntity stub header).
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

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Stub for AuditTrail; properties mirror the production member set decidiq
 * reads, served through Entity::__call exactly as in production.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getAction()
 * @method void setAction(?string $action)
 * @method array|null getChanged()
 * @method void setChanged(?array $changed)
 * @method string|null getUser()
 * @method void setUser(?string $user)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method string|null getHash()
 * @method void setHash(?string $hash)
 * @method string|null getPreviousHash()
 * @method void setPreviousHash(?string $previousHash)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 */
class AuditTrail extends Entity implements JsonSerializable {

	/**
	 * Unique identifier for the audit trail entry.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * Namespaced action string (e.g. decidiq.audit.decision-transition).
	 *
	 * @var string|null
	 */
	protected ?string $action = null;

	/**
	 * Context payload persisted in the changed JSON column.
	 *
	 * @var array|null
	 */
	protected ?array $changed = null;

	/**
	 * Session user recorded on the row.
	 *
	 * @var string|null
	 */
	protected ?string $user = null;

	/**
	 * Row creation time.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Chain hash, set by the platform seal sweep.
	 *
	 * @var string|null
	 */
	protected ?string $hash = null;

	/**
	 * Previous chain hash.
	 *
	 * @var string|null
	 */
	protected ?string $previousHash = null;

	/**
	 * UUID of the object the entry is attached to.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * Serialize to a plain array, mirroring the production shape for the
	 * members this stub declares.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$created = null;
		if ($this->created !== null) {
			$created = $this->created->format('c');
		}

		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'action' => $this->action,
			'changed' => $this->changed,
			'user' => $this->user,
			'created' => $created,
			'hash' => $this->hash,
			'previousHash' => $this->previousHash,
			'objectUuid' => $this->objectUuid,
		];
	}//end jsonSerialize()
}//end class
