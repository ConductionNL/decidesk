<?php

/**
 * Unit tests for the MigrateUserPreferences repair step.
 *
 * The failure this step prevents is the quietest one in the whole rename: every
 * per-user preference read carries a default, so after the app-id rename a user
 * who turned a notification OFF simply starts receiving it again. Nothing
 * throws, nothing is logged, and no other test fails.
 *
 * Two properties are pinned here that a passing migration could otherwise lack:
 *   - the step enumerates keys from the DATA (`getKeys`) and NEVER by value
 *     (`searchUsersByValueString`), because this app's `pref_*` key namespace is
 *     open-ended and a value-enumerating step would migrate nothing while
 *     reporting success;
 *   - the doubles can fail on READ, not only on write.
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

use OCA\Decidiq\Repair\MigrateUserPreferences;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateUserPreferences.
 */
class MigrateUserPreferencesTest extends TestCase {

	/**
	 * The app id this app used before the rename.
	 *
	 * @var string
	 */
	private const OLD = 'decidesk';

	/**
	 * The app id this app uses after the rename.
	 *
	 * @var string
	 */
	private const NEW = 'decidiq';

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
	 * Build the step over an in-memory per-user preference double.
	 *
	 * @param array<string, array<string, string>> $old Old-namespace values per user id.
	 * @param array<string, array<string, string>> $new New-namespace values per user id.
	 * @param array<string, string> &$written Receives every write performed.
	 * @param string[] $failReads Keys whose READ throws.
	 * @param bool $failWalk Whether the user walk throws.
	 * @param bool &$byValueUsed Set true if searchUsersByValueString is called.
	 *
	 * @return MigrateUserPreferences
	 */
	private function step(
		array $old,
		array $new,
		array &$written,
		array $failReads = [],
		bool $failWalk = false,
		bool &$byValueUsed = false,
	): MigrateUserPreferences {
		$config = $this->createMock(originalClassName: IUserConfig::class);

		$config->method('getKeys')->willReturnCallback(
			static function (string $uid, string $app) use ($old): array {
				if ($app === self::OLD) {
					return array_keys($old[$uid] ?? []);
				}

				return [];
			}
		);

		$config->method('getValueString')->willReturnCallback(
			static function (
				string $uid,
				string $app,
				string $key,
				string $default = '',
			) use ($old, $new, $failReads): string {
				if (in_array($key, $failReads, true) === true) {
					throw new RuntimeException('read exploded for ' . $key);
				}

				if ($app === self::OLD) {
					return ($old[$uid][$key] ?? $default);
				}

				return ($new[$uid][$key] ?? $default);
			}
		);

		$config->method('setValueString')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $value) use (&$written): bool {
				$written[$uid . '/' . $app . '/' . $key] = $value;
				return true;
			}
		);

		$config->method('searchUsersByValueString')->willReturnCallback(
			static function () use (&$byValueUsed): \Generator {
				$byValueUsed = true;
				yield from [];
			}
		);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$users = array_map(fn (string $uid): IUser => $this->user($uid), array_keys($old));
		$userManager->method('callForSeenUsers')->willReturnCallback(
			static function (callable $cb) use ($users, $failWalk): void {
				if ($failWalk === true) {
					throw new RuntimeException('user walk exploded');
				}

				foreach ($users as $u) {
					$cb($u);
				}
			}
		);

		return new MigrateUserPreferences(
			$config,
			$userManager,
			$this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end step()

	/**
	 * Every stored preference is copied to the new app id, for every seen user.
	 *
	 * @return void
	 */
	public function testCopiesEveryStoredPreferenceForEverySeenUser(): void {
		$written = [];
		$step = $this->step(
			old: [
				'alice' => ['pref_notify-vote-open' => 'false', 'pref_delegate-until' => '2026-09-01'],
				'bob' => ['pref_notify-vote-open' => 'true'],
			],
			new: [],
			written: $written,
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertSame('false', $written['alice/' . self::NEW . '/pref_notify-vote-open'] ?? null);
		self::assertSame('2026-09-01', $written['alice/' . self::NEW . '/pref_delegate-until'] ?? null);
		self::assertSame('true', $written['bob/' . self::NEW . '/pref_notify-vote-open'] ?? null);

	}//end testCopiesEveryStoredPreferenceForEverySeenUser()

	/**
	 * The step enumerates keys from the data and NEVER by value.
	 *
	 * `getUsersForUserValue(app, key, value)` needs both the key and the value
	 * up front. This app stores under an open-ended `pref_*` namespace with
	 * open-ended values, so a value-enumerating implementation would migrate
	 * NOTHING while reporting success. The pilot app's implementation did not
	 * transfer to a single later app for exactly this reason, so the choice is
	 * pinned here rather than left to review.
	 *
	 * @return void
	 */
	public function testEnumeratesByStoredKeysAndNeverByValue(): void {
		$written = [];
		$byValueUsed = false;
		$step = $this->step(
			old: ['alice' => ['pref_some-open-valued-key' => 'an-arbitrary-value']],
			new: [],
			written: $written,
			byValueUsed: $byValueUsed,
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertFalse($byValueUsed, 'searchUsersByValueString() must never be used to enumerate an open-valued key.');
		self::assertSame('an-arbitrary-value', $written['alice/' . self::NEW . '/pref_some-open-valued-key'] ?? null);

	}//end testEnumeratesByStoredKeysAndNeverByValue()

	/**
	 * A preference already set under the new app id is never clobbered.
	 *
	 * @return void
	 */
	public function testNeverOverwritesAPreferenceSetAfterTheRename(): void {
		$written = [];
		$step = $this->step(
			old: ['alice' => ['pref_notify-vote-open' => 'false']],
			new: ['alice' => ['pref_notify-vote-open' => 'true']],
			written: $written,
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertArrayNotHasKey('alice/' . self::NEW . '/pref_notify-vote-open', $written);

	}//end testNeverOverwritesAPreferenceSetAfterTheRename()

	/**
	 * A READ that throws is logged and the walk continues to the next user.
	 *
	 * The step is registered under `<install>`, so a throwing read would stop
	 * the app enabling at all rather than merely failing an upgrade.
	 *
	 * @return void
	 */
	public function testAReadThatThrowsDoesNotAbortTheWalk(): void {
		$written = [];
		$step = $this->step(
			old: [
				'alice' => ['pref_broken' => 'x'],
				'bob' => ['pref_notify-vote-open' => 'true'],
			],
			new: [],
			written: $written,
			failReads: ['pref_broken'],
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertSame('true', $written['bob/' . self::NEW . '/pref_notify-vote-open'] ?? null);
		self::assertArrayNotHasKey('alice/' . self::NEW . '/pref_broken', $written);

	}//end testAReadThatThrowsDoesNotAbortTheWalk()

	/**
	 * An unusable user enumeration is survivable and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableUserWalkIsSurvivable(): void {
		$written = [];
		$step = $this->step(
			old: ['alice' => ['pref_notify-vote-open' => 'false']],
			new: [],
			written: $written,
			failWalk: true,
		);

		$step->run($this->createMock(originalClassName: IOutput::class));

		self::assertSame([], $written);

	}//end testAnUnreadableUserWalkIsSurvivable()

}//end class
