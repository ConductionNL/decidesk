<?php

/**
 * Decidiq mandate directory.
 *
 * Answers one question for the approval-route engine: may this actor sign a
 * stage on behalf of the person the stage names, under the mandate reference
 * they presented? The question was previously answered by nobody — dossiq's
 * ParafeerStepGuard accepted any non-empty mandate string and left the registry
 * check to "the future MandaatService", and decidiq recorded onBehalfOf and
 * mandate verbatim without reading either. A mandate check that exists only as
 * a recorded field is an open door: any authenticated user could sign someone
 * else's stage by typing a mandate reference.
 *
 * THE CHECK IS SCOPED TO WHAT THIS REGISTER CAN KNOW. A mandate reference that
 * resolves to a local `bevoegdheidstoedeling` row is judged on that row: it
 * must be effective, within its validity window, and name the acting delegate.
 * A reference that resolves to nothing is treated as an EXTERNAL mandate — the
 * producing app (dossiq's mandateringsbesluit register, for one) holds rows
 * this app cannot read without the cross-register reach ADR-022 forbids — and
 * is recorded verbatim, exactly as the approval-action schema always intended.
 * What is never accepted: a LOCAL row that is withdrawn, lapsed, out of window
 * or issued to somebody else. Resolvable-and-wrong is a refusal, not a shrug.
 *
 * REQ-DMR-006 BOUNDARY. delegatie-mandaatregister rules that the register is
 * assistive and SHALL NOT gate any *Decision* creation, transition or
 * enactment. An approval-route stage action is not a Decision lifecycle
 * transition — it is a sign-off by a named person, and verifying that a
 * delegate really holds the mandate they wave is the one thing a sign-off
 * chain exists to do. The prohibition and this check do not overlap.
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
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Judges a presented mandate reference against the local toedeling register.
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class MandateDirectory {

	/**
	 * Schema slug of the local mandate register.
	 */
	private const SCHEMA_TOEDELING = 'bevoegdheidstoedeling';

	/**
	 * The one status under which a toedeling grants anything.
	 */
	private const STATUS_EFFECTIVE = 'effective';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads the bevoegdheidstoedeling rows.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * Refuse a delegate whose LOCAL mandate does not actually authorise them.
	 *
	 * @param string $mandate The mandate reference the delegate presented.
	 * @param string $actor The acting delegate's Nextcloud UID.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the reference resolves locally and the row refuses.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function assertMayActUnder(string $mandate, string $actor): void {
		$row = $this->resolve(mandate: $mandate);
		if ($row === null) {
			// External reference: the producing app holds the row. Recorded
			// verbatim on the action; nothing local to judge it against.
			return;
		}

		if ((string)($row['status'] ?? '') !== self::STATUS_EFFECTIVE) {
			throw new RuntimeException('This mandate is not effective, so nobody may sign under it.');
		}

		$this->assertWithinWindow(row: $row);

		$delegate = trim((string)($row['delegatePerson'] ?? ''));
		if ($delegate !== '' && $delegate !== $actor) {
			throw new RuntimeException('This mandate names a different delegate.');
		}
	}//end assertMayActUnder()

	/**
	 * Refuse a mandate outside its own validity window.
	 *
	 * An unparseable date REFUSES rather than passes: a validity window that
	 * cannot be read is not evidence of validity (OWASP A01:2021).
	 *
	 * @param array<string, mixed> $row The toedeling row.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When today falls outside the window.
	 */
	private function assertWithinWindow(array $row): void {
		$now = new DateTimeImmutable();

		$from = trim((string)($row['validFrom'] ?? ''));
		if ($from !== '' && $this->parse(date: $from) > $now) {
			throw new RuntimeException('This mandate is not yet in force.');
		}

		$to = trim((string)($row['validTo'] ?? ''));
		if ($to !== '' && $this->parse(date: $to) < $now) {
			throw new RuntimeException('This mandate has expired.');
		}
	}//end assertWithinWindow()

	/**
	 * Parse a stored date, refusing the unreadable.
	 *
	 * @param string $date The stored date string.
	 *
	 * @return DateTimeImmutable The parsed date.
	 *
	 * @throws RuntimeException When the date cannot be parsed.
	 */
	private function parse(string $date): DateTimeImmutable {
		try {
			return new DateTimeImmutable($date);
		} catch (Throwable) {
			throw new RuntimeException('This mandate carries an unreadable validity date, so it cannot authorise anyone.');
		}
	}//end parse()

	/**
	 * The local toedeling row a reference names, or null when it names none.
	 *
	 * A store failure resolves to null deliberately: an unreachable register
	 * must not turn every external mandate reference into a refusal, and the
	 * rows this register DOES hold are re-read on the next action.
	 *
	 * Resolved with find(), NOT findAll() with a top-level 'id' filter: that
	 * filter runs against the object's own JSON properties, where no `id`
	 * lives, so it matched NOTHING — every LOCAL mandate reference resolved
	 * to null and passed as if it were an external one, which silently
	 * skipped the effective/window/delegate checks (dossiq#1686's class).
	 *
	 * @param string $mandate The mandate reference.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function resolve(string $mandate): ?array {
		$mandate = trim($mandate);
		if ($mandate === '') {
			return null;
		}

		try {
			return $this->store->find(schema: self::SCHEMA_TOEDELING, uuid: $mandate);
		} catch (Throwable) {
			return null;
		}
	}//end resolve()

}//end class
