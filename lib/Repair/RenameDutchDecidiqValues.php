<?php

/**
 * Translates the Dutch ENUM VALUES stored in this app's shard tables.
 *
 * The schema edit changes the DECLARATION; every row already written still
 * holds the Dutch string, and a filter on the new value then returns NULL
 * rather than an error — so the feature reports "nothing found" instead of
 * failing. This rewrites the stored rows.
 *
 * Every database call goes through ValueMigrationGateway, a three-method port.
 * decidiq's unit environment has no doctrine/dbal, so IDBConnection cannot be
 * doubled here at all — depending on that connection directly would make this
 * step untestable by construction. Behind the port it is driven by a small fake.
 *
 * Scoped by COLUMN, never by the value alone. The same string means different
 * things on different columns, and a migration matching on the string would
 * corrupt every column that shares it.
 *
 * NOT migrated, deliberately: `oriType` (`Besluit`/`Vergadering`/`Verslag`).
 * That is the ORI standard's vocabulary and decidiq's own OriSerializer
 * consumes it — a mapping is configuration, so the standard's terms stay in
 * the standard's language.
 *
 * The Dutch LEGAL terms this migration replaces are not lost: each is written
 * into l10n/nl.json against its English key, so a Dutch-rendered UI still shows
 * `Splitsingsakte`, `Statuten`, `Verordening` and the rest.
 *
 * Idempotent: an already-migrated row matches no WHERE clause.
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

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Migrates stored Dutch enum values to their English spelling.
 *
 * @spec exclude Data migration for the Dutch-to-English vocabulary change;
 *  no canonical spec covers it and it carries no business rule of its own.
 */
class RenameDutchDecidiqValues implements IRepairStep {

	/**
	 * Property name => old value => new value.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const VALUE_MAP = [
		'ancillaryPositionDisclosureDefault' => [
			'intern' => 'internal',
			'openbaar' => 'public',
		],
		'beëdigingsType' => [
			'belofte' => 'affirmation',
			'eed' => 'oath',
		],
		'category' => [
			'brief-inwoner' => 'letter-resident',
			'brief-organisatie' => 'letter-organisation',
			'collegestuk' => 'executive-board-paper',
			'gemeentewet' => 'municipalities-act',
			'overig' => 'other',
			'petitie' => 'petition',
			'uitnodiging' => 'invitation',
			'woo-absoluut' => 'woo-absolute',
			'woo-relatief' => 'woo-relative',
		],
		'countersignStatus' => [
			'getekend' => 'signed',
			'geweigerd' => 'refused',
			'ongetekend' => 'unsigned',
		],
		'signatureStatus' => [
			'getekend' => 'signed',
			'geweigerd' => 'refused',
			'ongetekend' => 'unsigned',
		],
		'revocationSignatureStatus' => [
			'getekend' => 'signed',
			'geweigerd' => 'refused',
			'ongetekend' => 'unsigned',
		],
		'decision' => [
			'aanvaard' => 'accepted',
			'geweigerd' => 'refused',
			'overgedragen' => 'transferred',
		],
		'decisionCategory' => [
			'decharge' => 'discharge',
			'jaarrekening' => 'annual-accounts',
			'machtiging-boven-drempel' => 'authorisation-above-threshold',
			'mjop-vaststelling' => 'mjop-adoption',
			'overige' => 'other',
			'reservefonds-dotatie' => 'reserve-fund-contribution',
			'wijziging-huishoudelijk-reglement' => 'amendment-internal-regulations',
		],
		'decisionOutcome' => [
			'afwijkend-van-advies' => 'contrary-to-advice',
			'conform-advies' => 'in-line-with-advice',
			'conform-instemming' => 'in-line-with-consent',
			'niet-doorgezet' => 'not-pursued',
		],
		'endReason' => [
			'einde-raadslidmaatschap' => 'end-of-council-membership',
			'einde-termijn' => 'end-of-term',
			'ontslag-op-eigen-verzoek' => 'resignation',
			'overlijden' => 'death',
			'verhuizing' => 'relocation',
		],
		'expectedType' => [
			'begrotingsstuk' => 'budget-document',
			'raadsinformatiebrief' => 'council-information-letter',
			'raadsvoorstel' => 'council-proposal',
			'themabijeenkomst' => 'theme-session',
		],
		'imposedBy' => [
			'college' => 'executive-board',
		],
		'ownerType' => [
			'college' => 'executive-board',
			'griffie' => 'council-registry',
			'portefeuillehouder' => 'portfolio-holder',
		],
		'interpellationSupportThresholdType' => [
			'aantal' => 'count',
			'breukdeel' => 'fraction',
		],
		'objectType' => [
			'besluitenlijst' => 'decision-list',
			'motie' => 'motion',
			'raadsinformatiebrief' => 'council-information-letter',
			'regeling' => 'arrangement',
			'toezegging' => 'commitment',
		],
		'position' => [
			'geen-zienswijze' => 'no-view',
			'negatief' => 'negative',
			'positief' => 'positive',
			'positief-met-kanttekeningen' => 'positive-with-reservations',
		],
		'tenor' => [
			'geen-advies' => 'no-advice',
			'negatief' => 'negative',
			'positief' => 'positive',
			'positief-met-kanttekeningen' => 'positive-with-reservations',
		],
		'processing' => [
			'gedeeltelijk-overgenomen' => 'partly-adopted',
			'niet-overgenomen' => 'not-adopted',
			'overgenomen' => 'adopted',
		],
		'rappelStatus' => [
			'geen' => 'none',
			'rappel-3-maanden' => 'reminder-3-months',
			'rappel-6-maanden' => 'reminder-6-months',
		],
		'responseOutcome' => [
			'advies-met-voorwaarden' => 'advice-with-conditions',
			'instemming-geweigerd' => 'consent-refused',
			'instemming-verleend' => 'consent-granted',
			'negatief-advies' => 'negative-advice',
			'positief-advies' => 'positive-advice',
		],
		'routingAdvice' => [
			'betrekken-bij-agendapunt' => 'include-with-agenda-item',
			'in-handen-college-ter-afdoening' => 'referred-to-executive-board-for-disposal',
			'in-handen-college-ter-voorbereiding' => 'referred-to-executive-board-for-preparation',
			'voor-kennisgeving-aannemen' => 'noted-for-information',
		],
		'senderType' => [
			'natuurlijk-persoon' => 'natural-person',
		],
		// 🔴 `stepType` IS NOT HERE, AND MUST NOT COME BACK. It held ten Dutch
		// pairs and could never have rewritten one of them: this step rewrites
		// by DATABASE COLUMN, `DbValueMigrationGateway::columnsOf()` reads
		// `information_schema.columns`, and `plannedRewrites()` only plans a
		// rewrite for a value-map key that IS a column. `stepType` lives inside
		// the `steps` array of an object payload, so the block planned nothing,
		// rewrote nothing, and reported success — done work that had never been
		// done. The pairs now live in Migration\RenameStepTypes, which reads the
		// objects. Anything nested belongs there, not here.
		'subjectType' => [
			'begrotingswijziging' => 'budget-amendment',
			'jaarrekening' => 'annual-accounts',
			'kadernota' => 'framework-memorandum',
			'ontwerpbegroting' => 'draft-budget',
			'overig' => 'other',
			'toetreding-uittreding' => 'accession-withdrawal',
			'wijziging-regeling' => 'amendment-of-arrangement',
		],
		'trigger' => [
			'individueel' => 'individual',
			'nieuw-lid' => 'new-member',
			'raadswisseling-batch' => 'council-turnover-batch',
			'tussentijdse-opvolging' => 'interim-succession',
		],
		'type' => [
			'beleidsregel' => 'policy-rule',
			'delegatie' => 'delegation',
			'directiestatuut' => 'management-charter',
			'geschenk' => 'gift',
			'huishoudelijk-reglement' => 'internal-regulations',
			'instemmingsverzoek' => 'consent-request',
			'machtiging' => 'authorisation',
			'mandaat' => 'mandate',
			'nadere-regel' => 'detailed-rule',
			'orgaan' => 'body',
			'reglement' => 'regulations',
			'reglement-van-orde' => 'rules-of-procedure',
			'splitsingsakte' => 'deed-of-division',
			'statuten' => 'articles-of-association',
			'statuut-extern' => 'external-charter',
			'uitnodiging' => 'invitation',
			'verordening' => 'by-law',
			'volmacht' => 'power-of-attorney',
		],
		'verdict' => [
			'afkeurend' => 'adverse',
			'goedkeurend' => 'approving',
			'met-voorbehoud' => 'qualified',
		],
		'lifecycle' => [
			'aangehouden' => 'deferred',
			'advies-uitgebracht' => 'advice-issued',
			'afgedaan' => 'disposed',
			'afgerond' => 'completed',
			'afgewezen' => 'rejected',
			'beantwoord' => 'answered',
			'behandeld' => 'handled',
			'bekrachtigd' => 'ratified',
			'benoemd' => 'appointed',
			'besluit-ontvangen' => 'decision-received',
			'betrokken-bij-behandeling' => 'involved-in-handling',
			'beëindigd' => 'ended',
			'concept' => 'draft',
			'geagendeerd' => 'agendised',
			'gemeld' => 'reported',
			'gepland' => 'planned',
			'gerealiseerd' => 'realised',
			'geregistreerd' => 'registered',
			'gesloten' => 'closed',
			'gestart' => 'started',
			'gesteld' => 'put',
			'in-behandeling' => 'in-progress',
			'in-uitvoering' => 'in-execution',
			'ingediend' => 'submitted',
			'ingepland' => 'scheduled',
			'ingetrokken' => 'withdrawn',
			'intern' => 'internal',
			'niet-behandeld' => 'not-handled',
			'niet-benoemd' => 'not-appointed',
			'niet-uitgebracht' => 'not-issued',
			'ontvangen' => 'received',
			'openbaar' => 'public',
			'opgeheven' => 'dissolved',
			'opgelegd' => 'imposed',
			'routering-vastgesteld' => 'routing-determined',
			'toegelaten' => 'admitted',
			'vastgesteld' => 'adopted',
			'verantwoord' => 'accounted-for',
			'verschoven' => 'postponed',
			'vervallen' => 'lapsed',
			'verwerkt' => 'processed',
			'verzonden' => 'sent',
		],
		'status' => [
			'afgerond' => 'completed',
			'concept' => 'draft',
			'geldend' => 'in-force',
			'geopend' => 'opened',
			'gepland' => 'planned',
			'in-behandeling' => 'in-progress',
			'in-uitvoering' => 'in-execution',
			'in-voorbereiding' => 'in-preparation',
			'in-werking' => 'in-effect',
			'ingediend' => 'submitted',
			'ingetrokken' => 'withdrawn',
			'niet-ingediend' => 'not-submitted',
			'overgeslagen' => 'skipped',
			'stukken-ontvangen' => 'documents-received',
			'uitstaand' => 'outstanding',
			'van-kracht' => 'effective',
			'vastgesteld' => 'adopted',
			'vervallen' => 'lapsed',
			'vervangen' => 'replaced',
			'verwerkt' => 'processed',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ValueMigrationGateway $gateway Database operations.
	 * @param RenameDutchDecidiqValueDecisions $decisions Pure predicates.
	 */
	public function __construct(
		private readonly ValueMigrationGateway $gateway,
		private readonly RenameDutchDecidiqValueDecisions $decisions = new RenameDutchDecidiqValueDecisions(),
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec exclude Data migration for the Dutch-to-English vocabulary change;
	 *  no canonical spec covers it and it carries no business rule of its own.
	 */
	public function getName(): string {
		return 'Translate stored Dutch Decidiq enum values';
	}//end getName()

	/**
	 * Rewrite the stored values, one column at a time.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude Data migration for the Dutch-to-English vocabulary change;
	 *  no canonical spec covers it and it carries no business rule of its own.
	 */
	public function run(IOutput $output): void {
		$tables = $this->gateway->shardTables();
		if ($tables === []) {
			$output->info($this->decisions->nothingToDoMessage());
			return;
		}

		$updated = 0;
		foreach ($tables as $table) {
			$planned = $this->decisions->plannedRewrites(
				valueMap: self::VALUE_MAP,
				columns: $this->gateway->columnsOf(table: $table)
			);

			foreach ($planned as $job) {
				$updated += $this->gateway->rewrite(
					table: $table,
					column: $job['column'],
					old: $job['old'],
					new: $job['new']
				);
			}
		}

		$output->info($this->decisions->summaryMessage(updated: $updated));
	}//end run()
}//end class
