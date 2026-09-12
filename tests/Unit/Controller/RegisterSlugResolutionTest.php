<?php

/**
 * Decidiq RegisterSlugResolutionTest
 *
 * The ownership check reads the slug this instance actually carries.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\VotingBehaviourController;
use OCA\Decidiq\Service\VotingBehaviourService;
use OCA\Decidiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Migrated and unmigrated instances, told apart, on an authorization path.
 *
 * ## What was wrong
 *
 * `ownsParticipant()` read the participant with `'decidesk'` as `find()`'s
 * FOURTH POSITIONAL argument, which is the register. Decidiq's own
 * `Repair\MigrateRegisterSlug` renames that register to `decidiq` per instance,
 * so on any instance that had run it the register did not exist, the lookup
 * raised `DoesNotExistException` exactly like an unknown id, the method answered
 * false, and `getStats()` returned 403 to every non-admin asking for their OWN
 * statistics.
 *
 * Two things kept it invisible. `getStats()` falls back to
 * `IGroupManager::isAdmin()`, so it kept working for the people most likely to
 * test it. And the pin is positional, so no `register:` label appears on the
 * line and the static guard's patterns cannot see it.
 *
 * ## Watched failing
 *
 * With `find(..., $registerSlug, ...)` reverted to `find(..., 'decidesk', ...)`,
 * four assertions reddened and they were the right four:
 *
 *  - `testAMigratedInstanceLetsAnOwnerReadTheirOwnStats`, expected 200, got 403.
 *    This is the defect itself: an owner refused their own statistics.
 *  - `testTheOwnershipReadUsesTheResolvedSlug`, expected `decidiq`, got
 *    `decidesk`.
 *  - `RegisterSlugPinTest`, naming the file, line 218 and the canonical slug.
 *  - `VotingBehaviourControllerTest::testGetStatsReturns200ForOwnStats`, whose
 *    expectation had copied the implementation and so agreed with the pin.
 *
 * `testAnUnmigratedInstanceLetsAnOwnerReadTheirOwnStats` stayed GREEN under that
 * mutation, because on an unmigrated instance `decidesk` happens to be the right
 * answer. Only the migrated case can catch this, and every test this repository
 * had was, in effect, the unmigrated one.
 *
 * The first attempt at this file could not catch it either. Its `find()` double
 * returned the participant for any register it was handed, so under the mutation
 * the migrated case still answered 200 and only the slug assertion failed. The
 * double was corrected to raise `DoesNotExistException` for a register the
 * instance does not carry, which is what OpenRegister does, and the mutation was
 * then re-run. A test that cannot fail reports the same green as one that
 * passed.
 */
class RegisterSlugResolutionTest extends TestCase {

	/**
	 * The participant under test.
	 *
	 * @var string
	 */
	private const PARTICIPANT_ID = 'participant-1';

	/**
	 * The owning user.
	 *
	 * @var string
	 */
	private const UID = 'alice';

	/**
	 * The register slug the ownership read was made with.
	 *
	 * @var string|null
	 */
	private ?string $readRegister = null;

	/**
	 * A migrated instance lets an owner read their own statistics.
	 *
	 * This is the case that catches the defect, and the case nobody had.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceLetsAnOwnerReadTheirOwnStats(): void {
		$response = $this->getStats(presentSlugs: ['decidiq']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAMigratedInstanceLetsAnOwnerReadTheirOwnStats()

	/**
	 * An unmigrated instance does too.
	 *
	 * Passes both before and after the fix, and is here to prove the resolution
	 * did not simply swap one literal for another, which would have moved the
	 * breakage to the other half of the estate rather than removing it.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceLetsAnOwnerReadTheirOwnStats(): void {
		$response = $this->getStats(presentSlugs: ['decidesk']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnUnmigratedInstanceLetsAnOwnerReadTheirOwnStats()

	/**
	 * The read is made with the slug the instance carries, not a literal.
	 *
	 * @return void
	 */
	public function testTheOwnershipReadUsesTheResolvedSlug(): void {
		$this->getStats(presentSlugs: ['decidiq']);
		$this->assertSame('decidiq', $this->readRegister);

		$this->readRegister = null;

		$this->getStats(presentSlugs: ['decidesk']);
		$this->assertSame('decidesk', $this->readRegister);
	}//end testTheOwnershipReadUsesTheResolvedSlug()

	/**
	 * An absent register is reported as unavailable, never as forbidden.
	 *
	 * The distinction is the point. A 403 says "this is not yours", which is a
	 * statement about the caller and is false. The register not being on the
	 * instance is a statement about the instance, and an operator can act on it.
	 *
	 * @return void
	 */
	public function testAnAbsentRegisterIsNotReportedAsForbidden(): void {
		$response = $this->getStats(presentSlugs: []);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertNotSame(
			Http::STATUS_FORBIDDEN,
			$response->getStatus(),
			'An absent register must not be recoloured as an authorization failure.'
		);
		$this->assertNull($this->readRegister, 'No read may be attempted with an unresolved register.');
	}//end testAnAbsentRegisterIsNotReportedAsForbidden()

	/**
	 * Call getStats() as the participant's owner against a given instance state.
	 *
	 * The user is deliberately NOT an admin. `getStats()` falls back to
	 * `isAdmin()`, so an admin double would pass every case here whether or not
	 * the register resolved, which is precisely how this defect stayed hidden in
	 * the first place.
	 *
	 * @param list<string> $presentSlugs The register slugs this instance carries.
	 *
	 * @return JSONResponse The response.
	 */
	private function getStats(array $presentSlugs): JSONResponse {
		$participant = $this->createMock(ObjectEntityInterface::class);
		$participant->method('jsonSerialize')->willReturn(['nextcloudUserId' => self::UID]);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (...$args) use ($participant, $presentSlugs) {
				// `register` is find()'s fourth positional parameter.
				$this->readRegister = ($args[3] ?? null);

				// The double HONOURS the register, and it has to. A double that
				// hands the participant back whatever register it was asked for
				// cannot tell a correct read from a read against a register this
				// instance does not have, so every test built on it passes with
				// the pin in place. Measured: with the callback ignoring the
				// register, reinstating the literal left the migrated-instance
				// case GREEN and only the slug assertion caught it.
				//
				// OpenRegister raises `DoesNotExistException` for a register it
				// cannot find, exactly as it does for an unknown id, which is
				// what makes the two indistinguishable to the caller.
				if (in_array($this->readRegister, $presentSlugs, true) === false) {
					throw new DoesNotExistException('No register ' . var_export($this->readRegister, true));
				}

				return $participant;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		$behaviourService = $this->createMock(VotingBehaviourService::class);
		$behaviourService->method('getStats')->willReturn(['participantId' => self::PARTICIPANT_ID]);

		$controller = new VotingBehaviourController(
			request: $this->createMock(IRequest::class),
			behaviourService: $behaviourService,
			userSession: $userSession,
			groupManager: $groupManager,
			objectService: $objectService,
			slugResolver: new FakeSlugResolver($presentSlugs),
		);

		return $controller->getStats(
			participantId: self::PARTICIPANT_ID,
			governanceBodyId: 'body-1'
		);
	}//end getStats()
}//end class
