<?php

/**
 * The pure decisions behind RenameDutchDecidiqValues.
 *
 * Separated for a reason this app makes unavoidable: decidiq's unit
 * environment does not install doctrine/dbal, so `createMock(IDBConnection)`
 * cannot even be generated — it fails on `Doctrine\DBAL\ParameterType` while
 * building the double. The database half of a repair step is therefore
 * genuinely untestable here, which makes it worth keeping as small as possible
 * and putting every decision on this side of the line.
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

/**
 * Pure predicates for the stored-enum-value migration.
 *
 * @spec exclude Predicates of the Dutch-to-English vocabulary migration; no
 *  canonical spec covers it.
 */
class RenameDutchDecidiqValueDecisions {

	/**
	 * Convert a property name to the column MagicMapper materialised.
	 *
	 * Mirrors `MagicMapper::sanitizeColumnName()`, which applies ONLY the
	 * ([a-z0-9])([A-Z]) boundary — no acronym rule. A column spelled any other
	 * way matches nothing and the migration is a silent no-op, not an error.
	 *
	 * @param string $name Property name.
	 *
	 * @return string Column name.
	 *
	 * @spec exclude Predicate of the Dutch-to-English vocabulary migration; no
	 *  canonical spec covers it.
	 */
	public function columnFor(string $name): string {
		$column = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
		$column = strtolower((string)$column);
		$column = preg_replace('/[^a-z0-9_]/', '_', $column);
		$column = preg_replace('/_+/', '_', (string)$column);

		return rtrim((string)$column, '_');
	}//end columnFor()

	/**
	 * Work out which value rewrites a table actually needs.
	 *
	 * A property whose column the table does not have is skipped: shard tables
	 * are per-schema, so most carry only a few of the mapped columns, and an
	 * UPDATE against a missing column is an error rather than a no-op.
	 *
	 * @param array<string, array<string, string>> $valueMap Property => old => new.
	 * @param array<int, string> $columns Columns the table has.
	 *
	 * @return array<int, array{column: string, old: string, new: string}>
	 *
	 * @spec exclude Predicate of the Dutch-to-English vocabulary migration; no
	 *  canonical spec covers it.
	 */
	public function plannedRewrites(array $valueMap, array $columns): array {
		$planned = [];

		foreach ($valueMap as $property => $values) {
			$column = $this->columnFor(name: $property);
			if (in_array($column, $columns, true) === false) {
				continue;
			}

			foreach ($values as $old => $new) {
				$planned[] = [
					'column' => $column,
					'old' => (string)$old,
					'new' => $new,
				];
			}
		}

		return $planned;
	}//end plannedRewrites()

	/**
	 * Pull a single column out of information_schema rows.
	 *
	 * Defensive: a null cell yields an empty string rather than a TypeError
	 * inside a repair step, where an exception aborts the upgrade.
	 *
	 * @param array<int, array<string, mixed>> $rows Result rows.
	 * @param string $key Column to read.
	 *
	 * @return array<int, string>
	 *
	 * @spec exclude Predicate of the Dutch-to-English vocabulary migration; no
	 *  canonical spec covers it.
	 */
	public function column(array $rows, string $key): array {
		return array_map(static fn (array $row): string => (string)($row[$key] ?? ''), $rows);
	}//end column()

	/**
	 * The line the step reports when there is nothing to migrate.
	 *
	 * @return string
	 *
	 * @spec exclude Operator-facing text of the vocabulary migration; no
	 *  canonical spec covers it.
	 */
	public function nothingToDoMessage(): string {
		return 'RenameDutchDecidiqValues: no Decidiq shard tables on this install; nothing to do.';
	}//end nothingToDoMessage()

	/**
	 * The line the step reports after migrating.
	 *
	 * Here rather than inline because an operator reads this to decide whether
	 * the migration did anything, and a message is worth asserting.
	 *
	 * @param int $updated Rows rewritten.
	 *
	 * @return string
	 *
	 * @spec exclude Operator-facing text of the vocabulary migration; no
	 *  canonical spec covers it.
	 */
	public function summaryMessage(int $updated): string {
		return sprintf('RenameDutchDecidiqValues: %d row value(s) translated.', $updated);
	}//end summaryMessage()

	/**
	 * Map entries whose replacement differs from the source by CASE alone.
	 *
	 * Such an entry translates nothing. It rewrites the value's spelling while
	 * leaving the word Dutch, and because it still produces a diff it reads as
	 * a translation that was made. Worse, when the source is an identifier
	 * rather than a word the rewrite renames the identifier: the same defect in
	 * shillinq's draft map turned `ACMReport` into `aCMReport` and `WEEK` into
	 * `wEEK`, renaming an entity type and a period constant.
	 *
	 * Returned rather than thrown so the caller decides; the test asserts empty.
	 *
	 * @param array<string, array<string, string>> $valueMap Property => old => new.
	 *
	 * @return array<int, string> One "property: old -> new" line per offender.
	 *
	 * @spec exclude Self-check on the vocabulary migration's own map; no
	 *  canonical spec covers it.
	 */
	public function caseOnlyEntries(array $valueMap): array {
		$offenders = [];

		foreach ($valueMap as $property => $values) {
			foreach ($values as $old => $new) {
				$normalise = static fn (string $value): string
					=> strtolower((string)preg_replace('/[^a-z0-9]/i', '', $value));
				if ($normalise((string)$old) !== $normalise($new)) {
					continue;
				}

				$offenders[] = sprintf('%s: %s -> %s', (string)$property, (string)$old, $new);
			}
		}

		return $offenders;
	}//end caseOnlyEntries()
}//end class
