<?php

/**
 * Decidiq ALV Minutes Service
 *
 * Service for generating ALV (Algemene Ledenvergadering) minutes templates
 * and distributing them to members.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Exception;
use OCA\Decidiq\Exception\MissingObjectException;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that generates ALV-specific Dutch minutes templates.
 *
 * Generates minutes for Algemene Ledenvergadering (general assemblies) with
 * quorum statements, member rolls, and formal resolution language. Handles
 * distribution of approved minutes to active members via notifications.
 *
 * OpenRegister lookups are delegated to MinutesContextResolver and notification
 * delivery to ParticipantNotifier; what stays here is the ALV domain rules —
 * what counts as an ALV, what quorum means, and when minutes may be
 * distributed.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
 */
class ALVMinutesService {
	/**
	 * ALV Dutch minutes template.
	 *
	 * @var string
	 */
	private const ALV_TEMPLATE = <<<'TEMPLATE'
{title}

Datum: {date}
Locatie: {location}

Aanwezigen:
{presentCount} van de {totalCount} leden
Status quorum: {quorumStatus}

Agendapunten:
{agendaItems}

Resoluties en Stemming:
{resolutions}

Rondvraag en Afsluiting:
{aob}

Notulen opgesteld door: {secretary}
Notulen goedgekeurd door: {chair}
TEMPLATE;

	/**
	 * Lifecycle states from which approved minutes may be distributed.
	 *
	 * @var array<int,string>
	 */
	private const DISTRIBUTABLE_LIFECYCLES = [
		'approved',
		'signed',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param MinutesContextResolver $context Resolves Minutes/Meeting/Participant context
	 * @param ParticipantNotifier $notifier Delivers notifications to participants
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
	 */
	public function __construct(
		private LoggerInterface $logger,
		private MinutesContextResolver $context,
		private ParticipantNotifier $notifier,
	) {
	}//end __construct()

	/**
	 * Generate an ALV draft based on the Minutes and linked Meeting.
	 *
	 * Fetches the Minutes and linked Meeting, validates that meetingType
	 * contains 'alv', fetches AgendaItems and active Participants of the
	 * linked GovernanceBody, and renders the ALV Dutch template.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return array{
	 *   content: string,
	 *   recipientCount: int
	 * } Generated content and recipient count
	 *
	 * @throws MissingObjectException If Minutes or Meeting not found
	 * @throws Exception If meeting is not ALV type
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	public function generateALVDraft(string $minutesId): array {
		try {
			$minutes = $this->context->requireMinutes(minutesId: $minutesId);
			$meetingId = $this->context->linkedMeetingId(minutes: $minutes);

			if ($meetingId === null) {
				throw new Exception('No Meeting linked to Minutes');
			}

			$meeting = $this->context->requireMeeting(meetingId: $meetingId);
			$this->assertAlvMeeting(meeting: $meeting);

			$participants = $this->context->activeParticipants(
				bodyId: $this->context->governanceBodyId(meeting: $meeting)
			);

			$memberCount = count($participants);
			$presentCount = $this->presentCount(memberCount: $memberCount, meeting: $meeting);

			$content = $this->renderAlvTemplate(
				minutes: $minutes,
				meeting: $meeting,
				agendaItems: $this->context->agendaItems(meetingId: $meetingId),
				presentCount: $presentCount,
				memberCount: $memberCount
			);

			$this->logger->info("ALV draft generated for minutes $minutesId");

			return [
				'content' => $content,
				'recipientCount' => $memberCount,
			];
		} catch (Exception $e) {
			$this->logger->error('ALVMinutesService::generateALVDraft failed: ' . $e->getMessage());
			throw $e;
		}//end try

	}//end generateALVDraft()

	/**
	 * Distribute approved minutes to all active members.
	 *
	 * Fetches the Minutes (must be in approved or signed state), fetches
	 * active Participants of the linked GovernanceBody, and sends Nextcloud
	 * notifications to each with minutes title and deep link.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return int The count of notifications sent
	 *
	 * @throws MissingObjectException If Minutes not found
	 * @throws Exception If lifecycle is not approved or signed
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	public function distribute(string $minutesId): int {
		try {
			$minutes = $this->context->requireMinutes(minutesId: $minutesId);
			$this->assertDistributable(minutes: $minutes);

			$participants = $this->context->activeParticipants(
				bodyId: $this->context->governanceBodyIdForMinutes(minutes: $minutes)
			);

			$sentCount = $this->notifier->notifyAll(
				participants: $participants,
				title: 'Notulen gepubliceerd: ' . ($minutes['title'] ?? 'Untitled'),
				message: 'De notulen zijn nu beschikbaar.',
				deepLink: "/minutes/$minutesId"
			);

			$this->logger->info("ALV minutes distributed to $sentCount participants");

			return $sentCount;
		} catch (Exception $e) {
			$this->logger->error('ALVMinutesService::distribute failed: ' . $e->getMessage());
			throw $e;
		}//end try

	}//end distribute()

	/**
	 * Assert that a Meeting is an ALV.
	 *
	 * @param array<string,mixed> $meeting The Meeting data
	 *
	 * @return void
	 *
	 * @throws Exception When the meeting is not an ALV (HTTP 422)
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	private function assertAlvMeeting(array $meeting): void {
		$meetingType = strtolower((string)($meeting['meetingType'] ?? ''));

		if (strpos($meetingType, 'alv') === false
			&& strpos($meetingType, 'algemene-ledenvergadering') === false
		) {
			throw new Exception("Meeting is not an ALV (type: $meetingType)", 422);
		}

	}//end assertAlvMeeting()

	/**
	 * Assert that Minutes have reached a lifecycle state that may be distributed.
	 *
	 * @param array<string,mixed> $minutes The Minutes data
	 *
	 * @return void
	 *
	 * @throws Exception When the lifecycle is not approved or signed (HTTP 403)
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	private function assertDistributable(array $minutes): void {
		$lifecycle = ($minutes['lifecycle'] ?? null);

		if (in_array($lifecycle, self::DISTRIBUTABLE_LIFECYCLES, true) === false) {
			throw new Exception(
				"Minutes must be approved or signed before distribution (current: $lifecycle)",
				403
			);
		}

	}//end assertDistributable()

	/**
	 * Determine how many members count as present.
	 *
	 * Without a quorum requirement every active member counts as present; with
	 * one, the present count is capped at the requirement.
	 *
	 * @param int $memberCount The active member count
	 * @param array<string,mixed> $meeting The Meeting data
	 *
	 * @return int The present count
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	private function presentCount(int $memberCount, array $meeting): int {
		if (empty($meeting['quorumRequired']) === true) {
			return $memberCount;
		}

		return (int)min($memberCount, $meeting['quorumRequired']);
	}//end presentCount()

	/**
	 * Render the ALV Dutch template.
	 *
	 * @param array<string,mixed> $minutes The Minutes data
	 * @param array<string,mixed> $meeting The Meeting data
	 * @param array<int,array<string,mixed>> $agendaItems The agenda items
	 * @param int $presentCount The present member count
	 * @param int $memberCount The total member count
	 *
	 * @return string The rendered ALV minutes text
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
	 */
	private function renderAlvTemplate(
		array $minutes,
		array $meeting,
		array $agendaItems,
		int $presentCount,
		int $memberCount,
	): string {
		$agendaText = '';
		foreach ($agendaItems as $item) {
			$agendaText .= sprintf(
				"- %s: %s\n",
				$item['orderNumber'] ?? '',
				$item['title'] ?? 'Untitled'
			);
		}

		$quorumStatus = "Quorum niet bereikt ($presentCount/$memberCount leden)";
		if ($presentCount >= ($meeting['quorumRequired'] ?? 0)) {
			$quorumStatus = "Quorum bereikt ($presentCount/$memberCount leden)";
		}

		$searchKeys = [
			'{title}',
			'{date}',
			'{location}',
			'{presentCount}',
			'{totalCount}',
			'{quorumStatus}',
			'{agendaItems}',
			'{resolutions}',
			'{secretary}',
			'{chair}',
			'{aob}',
		];

		// Every replacement is cast to string. `$minutes` / `$meeting` are
		// decoded object payloads, so their values are `mixed` — str_replace
		// declares `array<string>|string` and PHP would coerce silently
		// (an array replacement raises "Array to string conversion" and
		// substitutes the literal "Array").
		return str_replace(
			$searchKeys,
			[
				(string)($minutes['title'] ?? 'Algemene Ledenvergadering'),
				(string)($meeting['scheduledDate'] ?? date('d-m-Y')),
				(string)($meeting['location'] ?? ''),
				(string)$presentCount,
				(string)$memberCount,
				$quorumStatus,
				trim($agendaText),
				'[Resoluties met stemming in aparte tabel]',
				'[Secretaris naam]',
				'[Voorzitter naam]',
				'[Rondvraag: geen bijzonderheden / gesloten om ...]',
			],
			self::ALV_TEMPLATE
		);

	}//end renderAlvTemplate()
}//end class
