<?php

/**
 * The decision-state read seam's contract.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Event\DecisionStateRequestedEvent;
use OCA\Decidiq\Listener\DecisionStateRequestedListener;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCA\Decidiq\Service\DecisionTypeRegistry;
use OCA\Decidiq\Service\DelegatedDecisionDefaults;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The whole seam, wired as it ships: the REAL listener over the REAL
 * authorization guard and the REAL envelope derivation, with only OpenRegister
 * faked.
 *
 * 🔴 THE FAKE REFUSES WHAT LIVE OPENREGISTER REFUSES. decidiq#1107 is the
 * measurement behind that sentence: five store fakes accepted a top-level `id`
 * filter that live OpenRegister matches NOTHING for, and three production call
 * sites shipped dead behind them — including the announcer that was supposed to
 * tell dossiq a route had concluded. So this fake resolves a Decision ONLY by
 * uuid through `find()`, ONLY under the register and schema the production code
 * names, and returns no rows for a top-level id filter. A fake that accepted
 * more than that would pass whatever the caller happened to write.
 *
 * @covers \OCA\Decidiq\Listener\DecisionStateRequestedListener
 * @uses   \OCA\Decidiq\Event\DecisionStateRequestedEvent
 * @uses   \OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard
 * @uses   \OCA\Decidiq\Service\DecisionIntegrationService
 *
 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
 */
class DecisionStateRequestedListenerTest extends TestCase {

	/**
	 * The Decision rows the fake store holds, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $decisions = [];

	/**
	 * Whether the fake store is reachable at all.
	 *
	 * @var bool
	 */
	private bool $storeReachable = true;

	/**
	 * The listener under test, wired over the real guard and real service.
	 *
	 * @var DecisionStateRequestedListener
	 */
	private DecisionStateRequestedListener $listener;

	/**
	 * Build the seam exactly as the container does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->decisions = [];
		$this->storeReachable = true;

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id !== 'OCA\\OpenRegister\\Service\\ObjectService') {
					throw new RuntimeException('Service not found: ' . $id);
				}

				if ($this->storeReachable === false) {
					throw new RuntimeException('OpenRegister is not available');
				}

				return $this->objectService();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$this->listener = new DecisionStateRequestedListener(
			integrationService: new DecisionIntegrationService(
				container: $container,
				logger: $logger,
				auditLog: $this->createMock(AuditLogService::class),
				decisionDefaults: $this->createMock(DelegatedDecisionDefaults::class),
				typeRegistry: $this->createMock(DecisionTypeRegistry::class),
			),
			authorizationGuard: new DecisionIntegrationAuthorizationGuard(
				container: $container,
				logger: $logger,
			),
			logger: $logger,
		);

	}//end setUp()

	/**
	 * A store that answers the way live OpenRegister answers.
	 *
	 * `find()` resolves by uuid and ONLY under `decidiq`/`decision`; anything
	 * else is a miss. `findAll()` serves the signature-stage lookup and returns
	 * NO rows for a top-level id/uuid filter, because identity lives in `@self`
	 * and live OpenRegister matches nothing for one (decidiq#1107).
	 *
	 * @return ObjectService&MockObject The store.
	 */
	private function objectService(): ObjectService&MockObject {
		$service = $this->createMock(ObjectService::class);

		$service->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
			): ?ObjectEntity {
				if ((string)$register !== 'decidiq' || (string)$schema !== 'decision') {
					return null;
				}

				$row = ($this->decisions[(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);

		$service->method('findAll')->willReturnCallback(
			static function (array $config = []): array {
				$filters = (array)($config['filters'] ?? []);
				if (isset($config['id']) === true || isset($filters['id']) === true || isset($filters['uuid']) === true) {
					// Live OpenRegister matches nothing for a top-level id
					// filter — identity lives in `@self`.
					return [];
				}

				// No signature stages in these fixtures.
				return [];
			}
		);

		return $service;
	}//end objectService()

	/**
	 * Put one Decision in the store.
	 *
	 * @param string $uuid The Decision id.
	 * @param array<string, mixed> $row The stored properties.
	 *
	 * @return void
	 */
	private function store(string $uuid, array $row): void {
		$this->decisions[$uuid] = $row;
	}//end store()

	/**
	 * Ask the seam.
	 *
	 * @param string $decisionId The Decision to report on.
	 * @param string $actorId The identity the read is scoped to.
	 *
	 * @return DecisionStateRequestedEvent The answered event.
	 */
	private function ask(string $decisionId, string $actorId): DecisionStateRequestedEvent {
		$event = new DecisionStateRequestedEvent(
			sourceApp: 'procest',
			decisionId: $decisionId,
			actorId: $actorId,
		);

		$this->listener->handle($event);

		return $event;
	}//end ask()

	/**
	 * An outstanding Decision reports `pending` — the answer that tells a
	 * consumer to keep waiting rather than to give up.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAnOutstandingDecisionReportsPending(): void {
		$this->store(
			'dec-1',
			['lifecycle' => 'proposed', 'decisionType' => 'advice', '@self' => ['owner' => 'behandelaar']]
		);

		$event = $this->ask('dec-1', 'behandelaar');

		self::assertTrue($event->isHandled());
		self::assertTrue($event->isPermitted());
		self::assertTrue($event->isFound());
		self::assertSame('pending', $event->getStatus());
		self::assertNull(($event->getEnvelope()['decidedAt'] ?? null), 'Nothing has been decided yet.');
	}//end testAnOutstandingDecisionReportsPending()

	/**
	 * A concluded Decision reports the outcome decidiq derived — the SAME
	 * status vocabulary the conclusion event carries, so a consumer that missed
	 * the announcement reads the identical answer here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAConcludedDecisionReportsItsOutcome(): void {
		$this->store(
			'dec-2',
			[
				'lifecycle' => 'decided',
				'outcome' => 'adopted',
				'decisionType' => 'advice',
				'decisionDate' => '2026-09-03T11:28:00+00:00',
				'externalReference' => 'case-7',
				'@self' => ['owner' => 'behandelaar'],
			]
		);

		$event = $this->ask('dec-2', 'behandelaar');

		self::assertTrue($event->isHandled());
		self::assertTrue($event->isFound());
		self::assertSame('approved', $event->getStatus());
		self::assertSame('2026-09-03T11:28:00+00:00', ($event->getEnvelope()['decidedAt'] ?? null));
		self::assertSame('case-7', ($event->getEnvelope()['externalReference'] ?? null));
	}//end testAConcludedDecisionReportsItsOutcome()

	/**
	 * A rejected Decision is decided, not withdrawn: a consumer routes on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testARejectedDecisionReportsRejected(): void {
		$this->store(
			'dec-3',
			['lifecycle' => 'decided', 'outcome' => 'rejected', '@self' => ['owner' => 'behandelaar']]
		);

		self::assertSame('rejected', $this->ask('dec-3', 'behandelaar')->getStatus());
	}//end testARejectedDecisionReportsRejected()

	/**
	 * A withdrawn Decision is terminal WITHOUT an answer, and says so
	 * distinctly. Reporting it as `rejected` would let a consumer carry on as
	 * though somebody had decided against the thing; nobody decided anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAWithdrawnDecisionIsReportedAsWithdrawnNotRejected(): void {
		$this->store('dec-4', ['lifecycle' => 'withdrawn', '@self' => ['owner' => 'behandelaar']]);

		$event = $this->ask('dec-4', 'behandelaar');

		self::assertTrue($event->isFound());
		self::assertSame('withdrawn', $event->getStatus());
	}//end testAWithdrawnDecisionIsReportedAsWithdrawnNotRejected()

	/**
	 * A Decision that is not there is ANSWERED as not there — handled, with no
	 * envelope. A consumer must be able to tell this from "I could not look",
	 * because only one of the two is worth waiting through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAVanishedDecisionIsHandledAndNotFound(): void {
		$event = $this->ask('dec-gone', 'behandelaar');

		self::assertTrue($event->isHandled(), 'A miss is an answer, not a failure to answer.');
		self::assertTrue($event->isPermitted(), 'A miss must not be dressed up as a refusal.');
		self::assertFalse($event->isFound());
		self::assertNull($event->getEnvelope());
	}//end testAVanishedDecisionIsHandledAndNotFound()

	/**
	 * 🔴 THE SEAM DOES NOT LEAK. A caller who neither raised the Decision nor
	 * finds it published is refused, and is told nothing about it — no
	 * envelope, no status, and no `found` flag either.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testACallerWhoDidNotRaiseTheDecisionIsRefused(): void {
		$this->store(
			'dec-5',
			[
				'lifecycle' => 'decided',
				'outcome' => 'adopted',
				'isPublished' => 'internal',
				'@self' => ['owner' => 'somebody-else'],
			]
		);

		$event = $this->ask('dec-5', 'mallory');

		self::assertTrue($event->isHandled());
		self::assertFalse($event->isPermitted());
		self::assertFalse($event->isFound(), 'A refusal must not confirm the Decision exists.');
		self::assertNull($event->getEnvelope());
		self::assertNull($event->getStatus());
	}//end testACallerWhoDidNotRaiseTheDecisionIsRefused()

	/**
	 * The published arm of REQ-DCDH-101 travels with the seam, because the seam
	 * consults the rule rather than restating it: a published Decision is a
	 * public governance record and any caller may read its outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAPublishedDecisionIsReadableByAnyCaller(): void {
		$this->store(
			'dec-6',
			[
				'lifecycle' => 'enacted',
				'outcome' => 'adopted',
				'isPublished' => 'public',
				'@self' => ['owner' => 'somebody-else'],
			]
		);

		$event = $this->ask('dec-6', 'mallory');

		self::assertTrue($event->isPermitted());
		self::assertSame('approved', $event->getStatus());
	}//end testAPublishedDecisionIsReadableByAnyCaller()

	/**
	 * 🔴 AN ANONYMOUS OR SYSTEM CALLER IS REFUSED, NEVER ELEVATED. The bus
	 * carries no session, so an event that names no actor names nobody — and
	 * the one place a nameless caller could plausibly be read as "the system"
	 * is exactly the place it must not be.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAReadWithNoActorIsRefusedRatherThanElevated(): void {
		$this->store(
			'dec-7',
			['lifecycle' => 'decided', 'outcome' => 'adopted', '@self' => ['owner' => 'behandelaar']]
		);

		$event = $this->ask('dec-7', '');

		self::assertTrue($event->isHandled(), 'A bad ask is answered, so nobody polls forever on it.');
		self::assertFalse($event->isPermitted());
		self::assertNull($event->getEnvelope());
	}//end testAReadWithNoActorIsRefusedRatherThanElevated()

	/**
	 * A read with no decision id is refused the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAReadWithNoDecisionIdIsRefused(): void {
		$event = $this->ask('  ', 'behandelaar');

		self::assertTrue($event->isHandled());
		self::assertFalse($event->isPermitted());
	}//end testAReadWithNoDecisionIdIsRefused()

	/**
	 * 🔴 AN UNREACHABLE STORE IS NOT A REFUSAL AND NOT A MISS. It leaves the
	 * event UNHANDLED, which is the only answer that tells a consumer to come
	 * back. Folding it onto "not permitted" — which is what the boolean guard
	 * does for an HTTP caller, correctly — would fail a waiting run on an
	 * authorization error it never had.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAnUnreachableStoreLeavesTheEventUnhandled(): void {
		$this->store(
			'dec-8',
			['lifecycle' => 'decided', 'outcome' => 'adopted', '@self' => ['owner' => 'behandelaar']]
		);

		$this->storeReachable = false;

		$event = $this->ask('dec-8', 'behandelaar');

		self::assertFalse($event->isHandled(), 'Unhandled is what "ask me again" looks like.');
		self::assertFalse($event->isPermitted());
		self::assertFalse($event->isFound());
		self::assertNull($event->getEnvelope());
	}//end testAnUnreachableStoreLeavesTheEventUnhandled()

	/**
	 * The listener ignores anything that is not its event, and never throws
	 * into the dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-state-read-seam/specs/decidesk-decision-events/spec.md
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$other = new \OCP\EventDispatcher\Event();

		$this->listener->handle($other);

		self::assertTrue(true, 'Handling an unrelated event must not raise.');
	}//end testAnUnrelatedEventIsIgnored()
}//end class
