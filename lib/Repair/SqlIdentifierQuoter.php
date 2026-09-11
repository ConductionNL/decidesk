<?php

/**
 * Quotes a table or column name for the database Nextcloud is running on.
 *
 * Replaces `IDBConnection::getDatabasePlatform()->quoteSingleIdentifier()`,
 * which is deprecated since Nextcloud 30 and hands back a Doctrine class that
 * is not part of the public API. `getDatabaseProvider()` is its replacement,
 * but it answers a platform NAME, not a quoter, so the quoting rule itself has
 * to live somewhere. It lives here, as a pure function, because decidiq's unit
 * environment cannot build an IDBConnection double (see
 * RenameDutchDecidiqValueDecisions) and the rule should still be tested.
 *
 * The rule is Doctrine's own `AbstractPlatform::quoteSingleIdentifier()`:
 * wrap in the platform's quote character and double any occurrence of it.
 * MySQL and MariaDB use a backtick; PostgreSQL, SQLite and Oracle use a double
 * quote. `getDatabaseProvider()` reports MariaDB as `mysql` unless it is asked
 * to be strict, and both are handled.
 *
 * @category  Repair
 * @package   OCA\Decidiq\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Decidiq\Repair;

use OCP\IDBConnection;

/**
 * Pure identifier quoting keyed on IDBConnection::getDatabaseProvider().
 *
 * @spec exclude Database plumbing for the repair steps' raw SQL; no canonical
 *  spec covers identifier quoting and it carries no business rule.
 */
class SqlIdentifierQuoter {

	/**
	 * Quote one identifier for the given database provider.
	 *
	 * @param string $provider   One of the IDBConnection::PLATFORM_* values.
	 * @param string $identifier Table or column name.
	 *
	 * @return string The quoted identifier.
	 *
	 * @spec exclude Database plumbing for the repair steps' raw SQL; no canonical
	 *  spec covers identifier quoting and it carries no business rule.
	 */
	public function quote(string $provider, string $identifier): string {
		$quote = '"';
		if ($provider === IDBConnection::PLATFORM_MYSQL || $provider === IDBConnection::PLATFORM_MARIADB) {
			$quote = '`';
		}

		return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
	}//end quote()
}//end class
