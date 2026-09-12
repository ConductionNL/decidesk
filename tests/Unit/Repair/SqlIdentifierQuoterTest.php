<?php

/**
 * Unit tests for SqlIdentifierQuoter.
 *
 * The expected strings are what Doctrine's own
 * `AbstractPlatform::quoteSingleIdentifier()` returns for each platform, which
 * is the call this class replaced. They were checked against the Doctrine
 * platforms Nextcloud ships (MySQL80, MariaDB, PostgreSQL, SQLite, Oracle).
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\SqlIdentifierQuoter;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Identifier quoting per database provider.
 *
 * @covers \OCA\Decidiq\Repair\SqlIdentifierQuoter
 */
class SqlIdentifierQuoterTest extends TestCase {

	/**
	 * Provider name, identifier, expected quoted form.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function cases(): array {
		return [
			'mysql uses backticks' => [IDBConnection::PLATFORM_MYSQL, 'oc_openregister_table_3_12', '`oc_openregister_table_3_12`'],
			'mariadb uses backticks' => [IDBConnection::PLATFORM_MARIADB, 'notes', '`notes`'],
			'postgres uses double quotes' => [IDBConnection::PLATFORM_POSTGRES, 'notes', '"notes"'],
			'sqlite uses double quotes' => [IDBConnection::PLATFORM_SQLITE, 'notes', '"notes"'],
			'oracle uses double quotes' => [IDBConnection::PLATFORM_ORACLE, 'notes', '"notes"'],
			'mysql doubles an embedded backtick' => [IDBConnection::PLATFORM_MYSQL, 'back`tick', '`back``tick`'],
			'postgres doubles an embedded quote' => [IDBConnection::PLATFORM_POSTGRES, 'we"ird', '"we""ird"'],
			'postgres leaves a backtick alone' => [IDBConnection::PLATFORM_POSTGRES, 'back`tick', '"back`tick"'],
		];

	}//end cases()

	/**
	 * Each provider gets Doctrine's quoting for it.
	 *
	 * @param string $provider   The IDBConnection::PLATFORM_* value.
	 * @param string $identifier The identifier to quote.
	 * @param string $expected   The quoted form.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testQuotesLikeDoctrine(string $provider, string $identifier, string $expected): void {
		self::assertSame($expected, (new SqlIdentifierQuoter())->quote(provider: $provider, identifier: $identifier));

	}//end testQuotesLikeDoctrine()

}//end class
