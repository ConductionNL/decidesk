<?php

/**
 * Decidiq Voting Behaviour Controller
 *
 * REST API endpoint for voting behaviour statistics.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
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

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use InvalidArgumentException;
use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\VotingBehaviourService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for voting behaviour API endpoint.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */
class VotingBehaviourController extends Controller {

	/**
	 * The canonical slug of the register participants live in.
	 *
	 * Canonical, and deliberately not what the read uses. This app's own
	 * `Repair\MigrateRegisterSlug` renames the register from `decidesk` to
	 * `decidiq` per instance, so both names are live across the estate and
	 * neither is safe written as a literal. Resolve it and read with the answer.
	 *
	 * @var string
	 */
	private const PARTICIPANT_REGISTER = 'decidiq';

	/**
	 * Constructor for VotingBehaviourController.
	 *
	 * @param IRequest $request The request object
	 * @param VotingBehaviourService $behaviourService The voting behaviour service
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager The group manager
	 * @param ObjectServiceInterface $objectService OpenRegister object service for participant lookup
	 * @param RegisterSlugResolverInterface $slugResolver Which slug the decidiq register answers to on THIS
	 *                                                    instance. Not nullable: the only fallback a null would
	 *                                                    leave is a literal, and both literals are wrong on half
	 *                                                    the estate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	public function __construct(
		IRequest $request,
		private readonly VotingBehaviourService $behaviourService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterSlugResolverInterface $slugResolver,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Get voting behaviour statistics for a participant.
	 *
	 * Requires authentication. Current user may only access own stats unless they
	 * hold chair, secretary, or admin role.
	 *
	 * @param string $participantId The participant UUID
	 * @param string $governanceBodyId The governance body UUID (required in query params)
	 *
	 * @return JSONResponse The statistics array or error
	 *
	 * @throws \Throwable When OpenRegister fails for a reason other than an
	 *                    unknown id (those are translated to 404/400 below)
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function getStats(string $participantId, string $governanceBodyId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();

		// Which slug the participant register carries HERE, resolved once for
		// the request. An absence is NOT "not your stats": answering 403 for a
		// register that is not on the instance is exactly the silent recolouring
		// `ownsParticipant()` narrows its catch to avoid, and it would deny every
		// non-admin their own statistics with no way to tell why.
		$registerSlug = $this->slugResolver->resolve(canonical: self::PARTICIPANT_REGISTER);

		// Both halves are deliberate. `isResolved()` is the contract's own way of
		// asking, and is what a reader should see. The explicit null check is for
		// the analyser: `isResolved()` is DEFINED as `slug !== null`, but psalm
		// cannot see through the method call, so without it `->slug` stays
		// `?string` where a `string` is required below.
		//
		// Same caveat as `VotingBehaviourService::registerSlug()`, which carries
		// the measurements: openregister#3582 would make the narrowing real, but
		// not for a copy taken before the branch, which is what the next line is.
		// Reorder before deleting the second condition.
		$slug = $registerSlug->slug;
		if ($registerSlug->isResolved() === false || $slug === null) {
			return new JSONResponse(
				[
					'message' => 'The participant register is not available on this instance. '
						. 'Run decidiq\'s register-slug repair step.',
				],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$canViewOther = $this->ownsParticipant(
			participantId: $participantId,
			uid: $uid,
			registerSlug: $slug
		) || $this->groupManager->isAdmin($uid);

		if ($canViewOther === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($governanceBodyId === '') {
			return new JSONResponse(['message' => 'governanceBodyId required'], Http::STATUS_BAD_REQUEST);
		}

		// The service reaches OpenRegister for rounds and votes, so the same
		// "unknown id throws" contract applies to the governance body. Translate
		// it to the status the caller is owed instead of letting it surface as
		// a 500; anything else still propagates.
		try {
			$stats = $this->behaviourService->getStats(
				participantId: $participantId,
				governanceBodyId: $governanceBodyId,
			);
		} catch (DoesNotExistException) {
			return new JSONResponse(
				['message' => 'Governance body or participant not found.'],
				Http::STATUS_NOT_FOUND
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($stats);
	}//end getStats()

	/**
	 * Whether a Nextcloud user owns the given participant record.
	 *
	 * `participantId` is an OpenRegister UUID and `$uid` is a Nextcloud user id
	 * — different namespaces that must never be compared directly. Ownership is
	 * resolved by loading the participant and reading its `nextcloudUserId`.
	 *
	 * OpenRegister's `find()` THROWS `DoesNotExistException` for an unknown id;
	 * it does not return null. The caller's old `!== null` branch was therefore
	 * unreachable for the case it was written to handle, and an unknown
	 * participantId escaped as an uncaught exception — a 500 on what is an
	 * ordinary "no such participant" request. (Same defect class as
	 * ParticipantResolver::resolveGovernanceBodyId, fixed in #425.)
	 *
	 * The catch is narrowed to `DoesNotExistException` so every other failure —
	 * a broken register, an OpenRegister outage — still propagates rather than
	 * being silently recoloured as "not your stats".
	 *
	 * An absent participant answers false, so the caller returns 403 rather
	 * than 404. That is deliberate and fail-CLOSED: a distinct 404 would let
	 * any authenticated user enumerate which participant UUIDs exist, and an id
	 * that does not exist cannot be "own" under any reading.
	 *
	 * ## Why the register slug is a parameter
	 *
	 * This read used to name `'decidesk'` as its fourth POSITIONAL argument,
	 * which is the register. The narrowed catch above did not protect against
	 * that: on an instance that had run this app's own register-slug repair step
	 * there is no register called `decidesk`, so the lookup raised
	 * `DoesNotExistException` like any unknown id, this method answered false,
	 * and every non-admin was refused their own statistics. The exact silent
	 * recolouring the paragraph above says it prevents, arriving through the one
	 * door it left open.
	 *
	 * It stayed invisible because `getStats()` falls back to
	 * `IGroupManager::isAdmin()`, so the endpoint kept working for the people
	 * most likely to test it.
	 *
	 * The slug is resolved once by the caller and passed in, so this method
	 * cannot be reached with an unresolved register.
	 *
	 * @param string $participantId The participant UUID
	 * @param string $uid The authenticated Nextcloud user id
	 * @param string $registerSlug The slug the participant register answers to here,
	 *                             already resolved by the caller
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 *
	 * @return bool True when the participant belongs to this user
	 */
	private function ownsParticipant(string $participantId, string $uid, string $registerSlug): bool {
		try {
			$participantEntity = $this->objectService->find($participantId, [], false, $registerSlug, 'participant');
		} catch (DoesNotExistException) {
			return false;
		}

		if ($participantEntity === null) {
			return false;
		}

		$participant = $participantEntity->jsonSerialize();
		$nextcloudUserId = ($participant['nextcloudUserId'] ?? ($participant['owner'] ?? null));

		return ($nextcloudUserId !== null && $nextcloudUserId === $uid);
	}//end ownsParticipant()
}//end class
