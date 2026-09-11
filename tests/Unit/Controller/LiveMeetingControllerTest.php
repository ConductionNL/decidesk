<?php

/**
 * Wire-contract tests for the live-decision recording endpoint.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\LiveMeetingController;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\LiveDecisionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `POST /api/meetings/{meetingId}/live-decisions`.
 *
 * This is the endpoint the chair drives from the podium while a meeting is
 * running, so its five distinct wire outcomes all have to stay apart: 401
 * anonymous, 403 without a chair/secretary role ON THAT MEETING, 400 for an
 * incomplete decision, 409 when the meeting is not opened, 404 for an unknown
 * meeting.
 *
 * The 403 is the one worth writing down. The route is `#[NoAdminRequired]`, and
 * the authorization is per-MEETING, not per-instance: an ordinary member who is
 * chair of meeting A must still be refused on meeting B. A guard that checked
 * only "is this user a chair anywhere" would pass every status assertion below
 * except the one that pins the meeting id it was asked about.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
 */
class LiveMeetingControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock LiveDecisionService.
	 *
	 * @var LiveDecisionService&MockObject
	 */
	private LiveDecisionService&MockObject $liveDecisionService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock ParticipantResolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * The controller under test.
	 *
	 * @var LiveMeetingController
	 */
	private LiveMeetingController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->liveDecisionService = $this->createMock(LiveDecisionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);

		$this->controller = new LiveMeetingController(
			$this->request,
			$this->liveDecisionService,
			$this->userSession,
			$this->groupManager,
			$this->participantResolver,
		);

	}//end setUp()

	/**
	 * Sign a user into the mocked session.
	 *
	 * @param string $uid The Nextcloud uid.
	 * @param bool $isAdmin Whether the uid is an instance admin.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'voorzitter', bool $isAdmin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);

	}//end signIn()

	/**
	 * Stub a complete decision body on the request.
	 *
	 * @return void
	 */
	private function completeBody(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['title', null, 'Vaststelling begroting'],
				['text', null, 'De raad besluit de begroting vast te stellen.'],
				['outcome', null, 'adopted'],
				['legalBasis', null, 'Gemeentewet art. 189'],
			]
		);

	}//end completeBody()

	/**
	 * An anonymous caller gets 401 and nothing is recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function testRecordLiveDecisionWithoutSessionIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->liveDecisionService->expects($this->never())->method('recordDecision');

		$response = $this->controller->recordLiveDecision(meetingId: 'meeting-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRecordLiveDecisionWithoutSessionIs401()

	/**
	 * A member without a chair/secretary role ON THIS MEETING is 403, and the
	 * role question is asked about the meeting from the URL — not in general.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function testRecordLiveDecisionWithoutChairRoleIs403(): void {
		$this->signIn(uid: 'raadslid');
		$this->participantResolver->expects($this->once())
			->method('hasRole')
			->with(meetingId: 'meeting-B', nextcloudUid: 'raadslid', roles: ['chair', 'secretary'])
			->willReturn(false);
		$this->liveDecisionService->expects($this->never())->method('recordDecision');

		$response = $this->controller->recordLiveDecision(meetingId: 'meeting-B');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testRecordLiveDecisionWithoutChairRoleIs403()

	/**
	 * An instance admin is admitted without a per-meeting role lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function testRecordLiveDecisionAdminBypassesRoleCheck(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$this->participantResolver->expects($this->never())->method('hasRole');
		$this->completeBody();
		$this->liveDecisionService->method('recordDecision')->willReturn('decision-1');

		self::assertSame(
			Http::STATUS_OK,
			$this->controller->recordLiveDecision(meetingId: 'meeting-1')->getStatus()
		);

	}//end testRecordLiveDecisionAdminBypassesRoleCheck()

	/**
	 * A body missing any of title/text/outcome is 400 and nothing is recorded.
	 *
	 * @param string|null $title The title field as sent.
	 * @param string|null $text The text field as sent.
	 * @param string|null $outcome The outcome field as sent.
	 *
	 * @return void
	 *
	 * @dataProvider incompleteBodies
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function testRecordLiveDecisionIncompleteBodyIs400(?string $title, ?string $text, ?string $outcome): void {
		$this->signIn();
		$this->participantResolver->method('hasRole')->willReturn(true);
		$this->request->method('getParam')->willReturnMap(
			[
				['title', null, $title],
				['text', null, $text],
				['outcome', null, $outcome],
				['legalBasis', null, null],
			]
		);
		$this->liveDecisionService->expects($this->never())->method('recordDecision');

		$response = $this->controller->recordLiveDecision(meetingId: 'meeting-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testRecordLiveDecisionIncompleteBodyIs400()

	/**
	 * Decision bodies that must be refused.
	 *
	 * @return array<string, array{0: string|null, 1: string|null, 2: string|null}>
	 */
	public static function incompleteBodies(): array {
		return [
			'no title' => [null, 'De raad besluit.', 'adopted'],
			'no text' => ['Vaststelling', null, 'adopted'],
			'no outcome' => ['Vaststelling', 'De raad besluit.', null],
			'all empty' => ['', '', ''],
		];

	}//end incompleteBodies()

	/**
	 * A chair on this meeting records the decision: the service receives the
	 * meeting id, the assembled decision data and the ACTING uid, and the
	 * response carries the new decision's slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function testRecordLiveDecisionReturnsSlug(): void {
		$this->signIn(uid: 'voorzitter');
		$this->participantResolver->method('hasRole')->willReturn(true);
		$this->completeBody();

		$this->liveDecisionService->expects($this->once())
			->method('recordDecision')
			->with(
				'meeting-1',
				[
					'title' => 'Vaststelling begroting',
					'text' => 'De raad besluit de begroting vast te stellen.',
					'outcome' => 'adopted',
					'legalBasis' => 'Gemeentewet art. 189',
				]
			)
			->willReturn('besluit-2026-014');

		$response = $this->controller->recordLiveDecision(meetingId: 'meeting-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('besluit-2026-014', $response->getData()['slug']);

	}//end testRecordLiveDecisionReturnsSlug()

	/**
	 * An unknown meeting is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function testRecordLiveDecisionUnknownMeetingIs404(): void {
		$this->signIn();
		$this->participantResolver->method('hasRole')->willReturn(true);
		$this->completeBody();
		$this->liveDecisionService->method('recordDecision')
			->willThrowException(new MissingObjectException('Meeting not found.'));

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->recordLiveDecision(meetingId: 'ghost')->getStatus()
		);

	}//end testRecordLiveDecisionUnknownMeetingIs404()

	/**
	 * Recording into a meeting that is not opened is 409 — distinct from the
	 * generic 500 every other exception maps to. The client shows "open the
	 * meeting first" off this code.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function testRecordLiveDecisionOnClosedMeetingIs409(): void {
		$this->signIn();
		$this->participantResolver->method('hasRole')->willReturn(true);
		$this->completeBody();
		$this->liveDecisionService->method('recordDecision')
			->willThrowException(new \RuntimeException('Meeting is not opened.', 409));

		$response = $this->controller->recordLiveDecision(meetingId: 'meeting-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('Meeting is not opened.', $response->getData()['error']);

	}//end testRecordLiveDecisionOnClosedMeetingIs409()

	/**
	 * Any other service failure is a 500 whose body carries a GENERIC message —
	 * the exception text is not echoed to the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function testRecordLiveDecisionUnexpectedFailureIs500WithoutLeakingDetail(): void {
		$this->signIn();
		$this->participantResolver->method('hasRole')->willReturn(true);
		$this->completeBody();
		$this->liveDecisionService->method('recordDecision')
			->willThrowException(new \RuntimeException('SQLSTATE[42P01]: undefined_table oc_decidesk_secret'));

		$response = $this->controller->recordLiveDecision(meetingId: 'meeting-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Internal server error.', $response->getData()['error']);

	}//end testRecordLiveDecisionUnexpectedFailureIs500WithoutLeakingDetail()

}//end class
