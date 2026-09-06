<?php

/**
 * Unit tests for the MigrateAdminGroup repair step.
 *
 * The failure this step prevents: the register's authorization baseline named
 * `decidesk-administrators`, OpenRegister provisioned the group under that
 * old app id, and administrators granted real memberships in it. Renaming the
 * declaration alone would strand those memberships — a gid cannot be renamed,
 * so the step must copy members into `decidiq-administrators` while the old
 * group stays honored.
 *
 * Properties pinned here:
 *   - no old group means NO work: the step must not create an empty new group
 *     (the register import provisions it), preserving the admin-provisioned,
 *     fail-closed posture on fresh installs;
 *   - members are copied, never moved: the old group loses nobody;
 *   - a re-run copies nothing (members already present are skipped);
 *   - one failing membership write does not abort the rest, and nothing this
 *     step does may throw — it is registered under <install>, where an
 *     escaping exception stops the app enabling at all.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\MigrateAdminGroup;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateAdminGroup.
 *
 * @covers \OCA\Decidiq\Repair\MigrateAdminGroup
 */
class MigrateAdminGroupTest extends TestCase {

	/**
	 * The administrators group id under the old app id.
	 *
	 * @var string
	 */
	private const OLD_GROUP = 'decidesk-administrators';

	/**
	 * The administrators group id under the current app id.
	 *
	 * @var string
	 */
	private const NEW_GROUP = 'decidiq-administrators';

	/**
	 * Build a user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * The step must do nothing at all when the old-id group does not exist.
	 *
	 * A fresh install must keep the documented admin-provisioned posture: the
	 * register import provisions the new group, and this step creating one
	 * would duplicate that work.
	 *
	 * @return void
	 */
	public function testNoOldGroupMeansNoWork(): void {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(false);
		$groupManager->expects($this->never())->method('createGroup');
		$groupManager->expects($this->never())->method('get');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->never())->method('info');

		$step = new MigrateAdminGroup(
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$step->run(output: $output);
	}//end testNoOldGroupMeansNoWork()

	/**
	 * Members of the old group are copied into a newly created new group.
	 *
	 * @return void
	 */
	public function testCreatesNewGroupAndCopiesMembers(): void {
		$alice = $this->user(uid: 'alice');
		$bob = $this->user(uid: 'bob');

		$oldGroup = $this->createMock(originalClassName: IGroup::class);
		$oldGroup->method('getUsers')->willReturn([$alice, $bob]);
		$oldGroup->expects($this->never())->method('removeUser');

		$newGroup = $this->createMock(originalClassName: IGroup::class);
		$newGroup->method('inGroup')->willReturn(false);
		$added = [];
		$newGroup->method('addUser')->willReturnCallback(
			function (IUser $user) use (&$added): void {
				$added[] = $user->getUID();
			}
		);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturnMap(
			[
				[self::OLD_GROUP, true],
				[self::NEW_GROUP, false],
			]
		);
		$groupManager->expects($this->once())
			->method('createGroup')
			->with(self::NEW_GROUP);
		$groupManager->method('get')->willReturnMap(
			[
				[self::OLD_GROUP, $oldGroup],
				[self::NEW_GROUP, $newGroup],
			]
		);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')
			->with($this->stringContains(string: 'copied 2 member(s)'));

		$step = new MigrateAdminGroup(
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$step->run(output: $output);

		$this->assertSame(expected: ['alice', 'bob'], actual: $added);
	}//end testCreatesNewGroupAndCopiesMembers()

	/**
	 * A member already present in the new group is skipped, so a re-run
	 * copies nothing.
	 *
	 * @return void
	 */
	public function testRerunCopiesNothing(): void {
		$alice = $this->user(uid: 'alice');

		$oldGroup = $this->createMock(originalClassName: IGroup::class);
		$oldGroup->method('getUsers')->willReturn([$alice]);

		$newGroup = $this->createMock(originalClassName: IGroup::class);
		$newGroup->method('inGroup')->willReturn(true);
		$newGroup->expects($this->never())->method('addUser');

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);
		$groupManager->expects($this->never())->method('createGroup');
		$groupManager->method('get')->willReturnMap(
			[
				[self::OLD_GROUP, $oldGroup],
				[self::NEW_GROUP, $newGroup],
			]
		);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')
			->with($this->stringContains(string: 'copied 0 member(s)'));

		$step = new MigrateAdminGroup(
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$step->run(output: $output);
	}//end testRerunCopiesNothing()

	/**
	 * One member whose write fails is logged and the rest still copy — and
	 * nothing escapes the step.
	 *
	 * @return void
	 */
	public function testOneFailingMemberDoesNotAbortTheRest(): void {
		$alice = $this->user(uid: 'alice');
		$bob = $this->user(uid: 'bob');

		$oldGroup = $this->createMock(originalClassName: IGroup::class);
		$oldGroup->method('getUsers')->willReturn([$alice, $bob]);

		$newGroup = $this->createMock(originalClassName: IGroup::class);
		$newGroup->method('inGroup')->willReturn(false);
		$added = [];
		$newGroup->method('addUser')->willReturnCallback(
			function (IUser $user) use (&$added): void {
				if ($user->getUID() === 'alice') {
					throw new RuntimeException(message: 'read-only backend');
				}

				$added[] = $user->getUID();
			}
		);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);
		$groupManager->method('get')->willReturnMap(
			[
				[self::OLD_GROUP, $oldGroup],
				[self::NEW_GROUP, $newGroup],
			]
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')
			->with($this->stringContains(string: 'copied 1 member(s)'));

		$step = new MigrateAdminGroup(groupManager: $groupManager, logger: $logger);
		$step->run(output: $output);

		$this->assertSame(expected: ['bob'], actual: $added);
	}//end testOneFailingMemberDoesNotAbortTheRest()

	/**
	 * An unresolvable new group is logged, not thrown — the old-id group
	 * stays honored by the authorization baseline.
	 *
	 * @return void
	 */
	public function testUnresolvableNewGroupLogsAndReturns(): void {
		$oldGroup = $this->createMock(originalClassName: IGroup::class);
		$oldGroup->expects($this->never())->method('getUsers');

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturnMap(
			[
				[self::OLD_GROUP, true],
				[self::NEW_GROUP, false],
			]
		);
		$groupManager->method('get')->willReturnMap(
			[
				[self::OLD_GROUP, $oldGroup],
				[self::NEW_GROUP, null],
			]
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->never())->method('info');

		$step = new MigrateAdminGroup(groupManager: $groupManager, logger: $logger);
		$step->run(output: $output);
	}//end testUnresolvableNewGroupLogsAndReturns()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		$step = new MigrateAdminGroup(
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertNotSame(expected: '', actual: $step->getName());
	}//end testGetName()
}//end class
