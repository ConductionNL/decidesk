<?php

/**
 * Contract tests for MigrateGovernanceGroupNames.
 *
 * The step renames two notification recipient groups that were named in one
 * country's words: `griffie` (a Dutch council's clerk office) and
 * `decidesk-integriteit` (that word in Dutch, under the previous app id).
 *
 * What these tests pin is the property that makes the rename safe to ship:
 * it COPIES memberships and never removes, empties or deletes the old group.
 * An admin whose automation still points at the old name keeps a working
 * group; only the schemas stop naming it.
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
 * @spec exclude One-off group-id rename plumbing; mirrors memberships and adds
 *       no behaviour of its own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\MigrateGovernanceGroupNames;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The governance group rename copies members and keeps the old group.
 */
class MigrateGovernanceGroupNamesTest extends TestCase {

	/**
	 * A user mock that knows its own uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The mock.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A fresh install has neither old group, so the step must not create one.
	 *
	 * The new groups are provisioned empty by the register import from the
	 * notification declaration; creating them here would duplicate that work.
	 *
	 * @return void
	 */
	public function testNeitherOldGroupMeansNoWork(): void {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(false);
		$groupManager->expects($this->never())->method('createGroup');
		$groupManager->expects($this->never())->method('get');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('0 membership(s) copied'));

		$step = new MigrateGovernanceGroupNames(
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$step->run(output: $output);
	}//end testNeitherOldGroupMeansNoWork()

	/**
	 * 🔴 THE ONE THAT MATTERS. Members are COPIED, and the old group survives.
	 *
	 * A rename that emptied or deleted the old group would silently break every
	 * admin whose own automation still names it, and nothing would report that.
	 *
	 * @return void
	 */
	public function testCopiesMembersAndNeverTouchesTheOldGroup(): void {
		$alice = $this->user(uid: 'alice');
		$bob   = $this->user(uid: 'bob');

		$oldGriffie = $this->createMock(originalClassName: IGroup::class);
		$oldGriffie->method('getUsers')->willReturn([$alice, $bob]);
		$oldGriffie->expects($this->never())->method('removeUser');
		$oldGriffie->expects($this->never())->method('delete');

		$added        = [];
		$newSecretary = $this->createMock(originalClassName: IGroup::class);
		$newSecretary->method('inGroup')->willReturn(false);
		$newSecretary->method('addUser')->willReturnCallback(
			function (IUser $user) use (&$added): void {
				$added[] = $user->getUID();
			}
		);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(
			static fn (string $id): bool => $id === 'griffie'
		);
		$created = [];
		$groupManager->method('createGroup')->willReturnCallback(
			function (string $id) use (&$created): ?IGroup {
				$created[] = $id;
				return null;
			}
		);
		$groupManager->method('get')->willReturnCallback(
			static fn (string $id): ?IGroup => match ($id) {
				'griffie' => $oldGriffie,
				'decidiq-secretariat' => $newSecretary,
				default => null,
			}
		);

		$step = new MigrateGovernanceGroupNames(
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(
			['alice', 'bob'],
			$added,
			'Every member of the old group must be copied into the renamed one'
		);
		$this->assertSame(
			['decidiq-secretariat'],
			$created,
			'Only the group whose old counterpart exists may be created'
		);
	}//end testCopiesMembersAndNeverTouchesTheOldGroup()

	/**
	 * A second run adds nothing, because every member is already there.
	 *
	 * @return void
	 */
	public function testRerunCopiesNothing(): void {
		$oldGroup = $this->createMock(originalClassName: IGroup::class);
		$oldGroup->method('getUsers')->willReturn([$this->user(uid: 'alice')]);

		$newGroup = $this->createMock(originalClassName: IGroup::class);
		$newGroup->method('inGroup')->willReturn(true);
		$newGroup->expects($this->never())->method('addUser');

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);
		$groupManager->method('get')->willReturnCallback(
			static fn (string $id): IGroup => str_starts_with($id, 'decidiq-') === true
				? $newGroup
				: $oldGroup
		);

		$step = new MigrateGovernanceGroupNames(
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$step->run(output: $this->createMock(originalClassName: IOutput::class));
	}//end testRerunCopiesNothing()

	/**
	 * The step names itself for what it does.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		$step = new MigrateGovernanceGroupNames(
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$this->assertStringContainsString('plain words', $step->getName());
	}//end testGetName()
}//end class
