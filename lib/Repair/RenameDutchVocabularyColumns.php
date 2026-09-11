<?php

/**
 * Decidiq RenameDutchVocabularyColumns Repair Step
 *
 * Moves stored data from the Dutch column names to the English ones the
 * decidesk register now declares.
 *
 * WHY THIS IS NEEDED AT ALL. OpenRegister does not store an object as a JSON
 * blob keyed by property name — each schema property is a real, snake_cased
 * COLUMN in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync, MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. Its only DROP path removes a camelCase duplicate whose
 * snake_case twin already exists.
 *
 * So renaming `publicatiedatum` to `publicationDate` in the register, on its
 * own, leaves the data in `publicatiedatum` while every read looks at
 * `publication_date` and finds null. No error, no data loss, and invisible to
 * every gate and test, because the suites assert against fixtures rather than
 * migrated rows. 61 live objects across 20 statutory schemas were measured on
 * the reference install, so this is not a theoretical case here.
 *
 * WHY IT IS SCOPED BY REGISTER, NOT BY COLUMN NAME. Several of these words are
 * not unique to decidiq — procest also stores `onderwerp`, for one. A repair
 * step that scanned every shard table for a matching column would migrate
 * another app's data as a side effect. It therefore resolves the decidesk
 * register BY SLUG at runtime and only touches shard tables belonging to it.
 *
 * Scoping by register rather than by an enumerated list of schema titles is
 * also more complete: decidiq declares 36 schemas across fifteen register
 * fragments, and a hand-maintained title list would silently miss whichever
 * ones were added after it was written.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so the step is
 *     reversible and a re-run is a no-op;
 *   - nothing is deleted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Decidiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename decidiq's Dutch vocabulary columns to their English equivalents.
 *
 * @spec openspec/specs/agenda-publication/spec.md
 */
class RenameDutchVocabularyColumns implements IRepairStep {
	/**
	 * The register slug whose shard tables are in scope.
	 *
	 * FROZEN at the pre-rename spelling, and deliberately NOT Application::APP_ID.
	 * This is the OpenRegister REGISTER SLUG, not the app id: OpenRegister matches
	 * registers by slug, and the shard tables already on disk are named from it.
	 * Renaming it here would resolve no register, this step would report "no shard
	 * tables on this install" and do nothing — silently — while the data it was
	 * meant to migrate sat untouched under the old slug.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'decidiq';

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `publicationDate` as
	 * `publication_date`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * `toelichting` maps to `notes`, NOT `description`: the two co-occur in
	 * four schemas fleet-wide (including decidiq's own Geschenk), so they are
	 * distinct concepts and collapsing them would produce a duplicate key.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'publicatiedatum' => 'publication_date',
		'depublicatiedatum' => 'depublication_date',
		'onderwerp' => 'subject',
		'toelichting' => 'notes',
		'omschrijving' => 'description',
		'fractie' => 'political_group',

		// Fleet vocabulary batch: the Dutch->English rename applied to the
		// decidesk registers. Same rules as the entries above — snake_case,
		// no rename chains, and an ambiguous pair is refused rather than merged.
		'aanlever_deadline' => 'delivery_deadline',
		'achterbanraadpleging' => 'constituency_consultation',
		'adviesaanvraag' => 'advice_request',
		'afdoenings_bewijs' => 'settlement_evidence',
		'afdoenings_toelichting' => 'settlement_notes',
		'afgedane_toezegging' => 'settled_commitment',
		'afwijzings_reden' => 'rejection_reason',
		'agendapunt' => 'agenda_item',
		'aktedatum' => 'deed_date',
		'antwoord' => 'answer',
		'antwoord_samenvatting' => 'answer_summary',
		'beantwoord_door' => 'answered_by',
		'beantwoord_op' => 'answered_on',
		'behandeling_datum' => 'handling_date',
		'behandeling_verslag' => 'handling_minutes',
		'bekrachtigingsbesluit' => 'ratification_decision',
		'besluit' => 'decision',
		'besluit_date' => 'decision_date',
		'besluit_outcome' => 'decision_outcome',
		'besluit_text' => 'decision_text',
		'besluitvormend_orgaan' => 'decision_making_body',
		'bestuurder' => 'director',
		'bestuursorgaan' => 'governing_body',
		'betreft_jaar' => 'concerns_year',
		'beëdigings_datum' => 'swearing_in_date',
		'beëdigings_vergadering' => 'swearing_in_meeting',
		'bijlagen' => 'attachments',
		'board_koppeling' => 'board_integration',
		'boekjaar' => 'financial_year',
		'bron_schriftelijke_vraag' => 'source_written_question',
		'commissie_datum' => 'committee_date',
		'current_versienummer' => 'current_version_number',
		'cyclus_stap' => 'cyclus_step',
		'delegataris_functie' => 'delegate_role',
		'delegataris_persoon' => 'delegate_person',
		'einde_datum' => 'end_date',
		'einde_reden' => 'end_reason',
		'einde_termijn_datum' => 'end_term_date',
		'exit_bevestigd_door' => 'exit_confirmed_by',
		'exit_bevestigd_op' => 'exit_confirmed_on',
		'externe_naam' => 'externe_name',
		'functie' => 'role',
		'gegenereerd_door' => 'generated_by',
		'gegenereerd_op' => 'generated_on',
		'geldig_tot' => 'valid_to',
		'geldig_vanaf' => 'valid_from',
		'geschatte_waarde' => 'estimated_value',
		'geschenk_drempelbedrag' => 'gift_threshold_amount',
		'geschenken_openbaar' => 'gifts_public',
		'gesteld_door' => 'set_by',
		'gesteld_op' => 'set_on',
		'gever' => 'giver',
		'grantor_koppeling' => 'grantor_integration',
		'herbenoembaar' => 'reappointable',
		'holder_koppeling' => 'holder_integration',
		'indiener' => 'submitter',
		'indieningstermijn_uren' => 'submission_period_hours',
		'ingediend_datum' => 'submitted_date',
		'ingetrokken_door' => 'withdrawn_by',
		'integriteits_notificatie_groep' => 'integrity_notification_group',
		'interpellatie_steun_drempel_type' => 'interpellation_support_threshold_type',
		'interpellatie_steun_drempel_waarde' => 'interpellation_support_threshold_value',
		'max_aansluitende_termijnen' => 'max_consecutive_periods',
		'meeting_koppeling' => 'meeting_integration',
		'motivering' => 'rationale',
		'naam' => 'name',
		'nevenfunctie_disclosure_default' => 'ancillary_position_disclosure_default',
		'ondermandaat_toegestaan' => 'sub_mandate_permitted',
		'ontvangen_op' => 'received_on',
		'opheffingsbesluit' => 'dissolution_decision',
		'opschorting_tot' => 'suspension_to',
		'organisatie' => 'organisation',
		'origin_motie' => 'origin_motion',
		'origin_toezegging' => 'origin_commitment',
		'overlegvergadering' => 'consultation_meeting',
		'persoon' => 'person',
		'persoon_naam' => 'person_name',
		'quorum_met' => 'quorum_with',
		'raadsbesluit_datum' => 'council_resolution_date',
		'reden' => 'reason',
		'reden_vervallen' => 'reason_expired',
		'referentie' => 'reference',
		'related_dossier' => 'related_file',
		'rooster' => 'schedule',
		'samenvatting' => 'summary',
		'technische_vragen_eind' => 'technical_questions_end',
		'technische_vragen_start' => 'technical_questions_start',
		'termijn_duur_maanden' => 'term_duration_months',
		'termijn_nummer' => 'term_number',
		'toetredings_datum' => 'accession_date',
		'uittredings_datum' => 'exit_date',
		'uren_indicatie' => 'hours_indication',
		'vastgesteld_door' => 'determined_by',
		'vaststellend_orgaan' => 'determining_body',
		'verantwoording_date' => 'accountability_date',
		'verantwoording_text' => 'accountability_text',
		'verschuif_historie' => 'shift_history',
		'versie' => 'version',
		'versienummer' => 'version_number',
		'vervaldatum' => 'expiry_date',
		'vervolg_schriftelijke_vraag' => 'follow_up_written_question',
		'vervolg_toezegging' => 'follow_up_commitment',
		'verwerking' => 'processing',
		'verwerkings_toelichting' => 'processing_notes',
		'verzoek_nummer' => 'request_number',
		'verzoeker' => 'requester',
		'voordragende_partij' => 'nominating_party',
		'vraag' => 'question',
		'vraag_nummer' => 'question_number',
		'vragenuur_agenda_item' => 'question_hour_agenda_item',
		'wettelijke_grondslag' => 'statutory_basis',
		'advies_date' => 'advice_date',
		'advies_document' => 'advice_document',
		'akte_artikel' => 'deed_article',
		'artikel' => 'article',
		'behandelende_commissie' => 'handling_committee',
		'bekrachtiging_agenda_item' => 'ratification_agenda_item',
		'bekrachtiging_date' => 'ratification_date',
		'bekrachtiging_deadline' => 'ratification_deadline',
		'bezoldigd' => 'remunerated',
		'breukdelen_denominator' => 'fraction_denominator',
		'citeertitel' => 'citation_title',
		'current_inwerkingtreding' => 'current_effective_date',
		'delegans_omschrijving' => 'delegans_description',
		'financieel_plafond' => 'financial_ceiling',
		'inwerkingtreding' => 'effective_date',
		'modelreglement' => 'model_regulation',
		'naar' => 'to_period',
		'namens' => 'on_behalf_of',
		'notaris' => 'notary',
		'officiele_titel' => 'official_title',
		'opheffing_conditions' => 'lifting_conditions',
		'opheffing_date' => 'lifting_date',
		'parent_toedeling' => 'parent_allocation',
		'portfolio-holder' => 'portfolio_holder',
		'arrangement' => 'regulation',
		'reglement_source' => 'regulation_source',
		'requires_bekrachtiging' => 'requires_ratification',
		'splitsingsakte_document' => 'deed_of_division_document',
		'standpunt' => 'position',
		'strekking' => 'tenor',
		'verplicht' => 'mandatory',
		'volgorde' => 'sort_order',
		'vragen' => 'questions',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 * @param SqlIdentifierQuoter $quoter Identifier quoting for the active database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly SqlIdentifierQuoter $quoter = new SqlIdentifierQuoter(),
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agenda-publication/spec.md
	 */
	public function getName(): string {
		return 'Move decidesk data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every decidesk shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-publication/spec.md
	 */
	public function run(IOutput $output): void {
		$tables = $this->decideskShardTables();
		if ($tables === []) {
			$output->info('RenameDutchVocabularyColumns: no decidesk shard tables on this install; nothing to do.');
			return;
		}

		$renamed = 0;
		$copied = 0;

		foreach ($tables as $table) {
			$columns = $this->columnsOf(table: $table);
			$qTable = $this->quote(identifier: $table);

			foreach (self::COLUMN_MAP as $old => $new) {
				if (in_array($old, $columns, true) === false) {
					// Already migrated, or this schema never had the property.
					continue;
				}

				$qOld = $this->quote(identifier: $old);
				$qNew = $this->quote(identifier: $new);

				if (in_array($new, $columns, true) === false) {
					$sql = 'ALTER TABLE ' . $qTable . ' RENAME COLUMN ' . $qOld . ' TO ' . $qNew;
					if ($this->exec(sql: $sql) === true) {
						$renamed++;
					}

					continue;
				}

				// The mapper already added an empty English column: back-fill and
				// leave the Dutch one, so this stays reversible.
				$sql = 'UPDATE ' . $qTable . ' SET ' . $qNew . ' = ' . $qOld
					. ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL';
				if ($this->exec(sql: $sql) === true) {
					$copied++;
				}
			}//end foreach
		}//end foreach

		$output->info(
			'RenameDutchVocabularyColumns: ' . $renamed . ' column(s) renamed, '
			. $copied . ' column(s) back-filled, across ' . count($tables) . ' decidesk shard table(s).'
		);

	}//end run()

	/**
	 * Resolve the shard tables belonging to the decidesk register.
	 *
	 * The register id is looked up by slug rather than hardcoded: it differs
	 * per install (18 on the reference instance).
	 *
	 * @return array<int, string>
	 */
	private function decideskShardTables(): array {
		try {
			$registerId = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug = ?',
				[self::REGISTER_SLUG]
			)->fetchOne();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchVocabularyColumns: could not resolve the decidesk register; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		if ($registerId === false || $registerId === null) {
			return [];
		}

		// Table discovery goes through information_schema, NOT IDBConnection.
		// OCP\IDBConnection exposes neither getSchema() nor getPrefix(); both
		// exist only on the concrete OC\DB\Connection. Calling them is a runtime
		// fatal that `php -l` and phpcs both report as clean — only phpstan
		// catches it. Pattern follows openregister's own RegisterService: anchor
		// on the `openregister_table_` MARKER, never on a computed prefix.
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$result = $stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchVocabularyColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$marker = 'openregister_table_' . ((int)$registerId) . '_';

		$tables = [];
		while (($row = $result->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($this->isShardOfRegister(table: $name, marker: $marker) === true) {
				$tables[] = $name;
			}
		}

		$result->closeCursor();

		return $tables;
	}//end decideskShardTables()

	/**
	 * Whether a table is a shard of the decidesk register.
	 *
	 * @param string $table Table name from information_schema.
	 * @param string $marker `openregister_table_<registerId>_`.
	 *
	 * @return bool
	 */
	private function isShardOfRegister(string $table, string $marker): bool {
		$offset = strpos($table, $marker);
		if ($offset === false) {
			return false;
		}

		// Everything after the marker must be the numeric schema id, so a
		// derived table (…_18_85_backup) or a non-shard (…_18_audit) is left
		// alone. Note this is NOT what stops register 18 matching register
		// 180's tables — the marker already ends in '_', so `…_table_18_` is
		// not a substring of `…_table_180_85` in the first place.
		return ctype_digit(substr($table, ($offset + strlen($marker)))) === true;
	}//end isShardOfRegister()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		// Queried from information_schema — IDBConnection has no getSchema().
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$result = $stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchVocabularyColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$columns = [];
		while (($row = $result->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['column_name'] ?? '');
			if ($name !== '') {
				$columns[] = $name;
			}
		}

		$result->closeCursor();

		return $columns;
	}//end columnsOf()

	/**
	 * Execute one DDL/DML statement, logging and swallowing failure.
	 *
	 * A failure must not abort the repair run: the remaining tables are
	 * independent, and an un-migrated column is still readable.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchVocabularyColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->quoter->quote(provider: $this->db->getDatabaseProvider(), identifier: $identifier);
	}//end quote()
}//end class
