<?php

/**
 * Decidiq Migrate User Preferences Repair Step
 *
 * Repair step that carries this app's per-user preferences across the
 * `decidesk` -> `decidiq` app-id rename.
 *
 * WHY THIS EXISTS SEPARATELY FROM MigrateAppConfigKeys. `IAppConfig` and
 * per-user config (`IUserConfig`) are different stores: the former is `oc_appconfig`,
 * the latter `oc_preferences`. Both are namespaced by app id, so both are cut
 * off by the rename, but copying one does nothing for the other.
 *
 * WHY IT MATTERS MORE THAN IT LOOKS. Every read of a per-user preference in
 * this app carries a DEFAULT. `PreferencesController::getPreference()` reads
 * with `default: ''`, and the frontend then falls back to its shipped default
 * for anything absent. After the rename those lookups miss and the defaults
 * apply — so a member who turned a notification OFF starts receiving it again,
 * and a delegation window a user configured reads as unset. Nothing throws,
 * nothing is logged, and every page still renders. **A default-valued read
 * turns missing data into wrong behaviour rather than into an error**, which is
 * exactly why this is a migration and not a release note.
 *
 * WHY IT ENUMERATES BY USER-THEN-KEY, AND NEVER BY VALUE. `IUserConfig` offers
 * `searchUsersByValueString(app, key, value)`, which requires the caller to know
 * BOTH the key and the value up front. Neither is knowable here:
 *
 *   - The values are open-ended. Preferences hold delegation dates, delegate
 *     user ids and display choices, not a closed boolean set.
 *   - **The KEYS are open-ended too.** `PreferencesController` stores under
 *     `'pref_' . $safeKey`, where `$safeKey` is caller-supplied and sanitised
 *     only to `[a-z0-9-]{1,64}`. There is no finite key list to iterate, so a
 *     hardcoded `MIGRATED_KEYS` constant — the shape the pilot app used — would
 *     silently migrate an incomplete subset here while reporting success.
 *
 * This step therefore walks the users (`IUserManager::callForSeenUsers()`) and
 * asks `IUserConfig::getKeys()` for each one's actual stored keys under the old
 * app id. That is exhaustive by construction and cannot drift as new
 * preferences are added. `callForSeenUsers()` is the right walk rather than
 * `callForAllUsers()`: a user who has never logged in cannot have set a
 * preference, so the cheaper enumeration is also the complete one here.
 *
 * SAFETY. Idempotent and non-destructive, matching MigrateAppConfigKeys:
 *   - a value is copied only when the user has nothing stored under the new
 *     app id, so a preference changed after the rename is never clobbered and
 *     a second run is a no-op;
 *   - the old `decidesk` rows are never deleted, so a rollback still finds them;
 *   - every failure is logged and the walk continues, because one unreadable
 *     preference is not worth aborting an install over — and this step is
 *     registered under `<install>`, where a throwing repair step means the app
 *     never enables at all.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml` alongside MigrateAppConfigKeys — see the ordering comment
 * there.
 *
 * @category Repair
 * @package  OCA\Decidiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Repair;

use OCA\Decidiq\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy per-user preferences from the decidesk app id to decidiq.
 *
 * @spec exclude One-off decidesk->decidiq app-id rename plumbing: it moves
 *       oc_preferences rows between app-id namespaces and adds no behaviour
 *       of its own. The preferences it preserves are specified where they
 *       are read, in openspec/specs/user-settings/spec.md.
 */
class MigrateUserPreferences implements IRepairStep {

	/**
	 * The preferences namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id. This constant is one of the few places in
	 * the app that is supposed to still say `decidesk`.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'decidesk';

	/**
	 * Constructor for MigrateUserPreferences.
	 *
	 * @param IUserConfig $userConfig The per-user config store to read and write.
	 * @param IUserManager $userManager The user enumeration used to walk seen users.
	 * @param LoggerInterface $logger Logger for preferences that fail to copy.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserConfig $userConfig,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec exclude One-off decidesk->decidiq app-id rename plumbing: it moves
	 *       oc_preferences rows between app-id namespaces and adds no behaviour
	 *       of its own.
	 */
	public function getName(): string {
		return 'Copy Decidiq per-user preferences from the decidesk app id';
	}//end getName()

	/**
	 * Copy every stored per-user preference from the old app id to the new one.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec exclude One-off decidesk->decidiq app-id rename plumbing: it moves
	 *       oc_preferences rows between app-id namespaces and adds no behaviour
	 *       of its own. The preferences it preserves are specified where they
	 *       are read — openspec/specs/user-settings/spec.md.
	 */
	public function run(IOutput $output): void {
		$migrated = 0;
		$alreadyPresent = 0;

		$walked = $this->walkSeenUsers(
			callback: function (IUser $user) use (&$migrated, &$alreadyPresent): void {
				$userId = $user->getUID();
				foreach ($this->oldKeysFor(userId: $userId) as $key) {
					/*
					 * Both READS sit inside the try alongside the write. A read
					 * that throws from a step registered under <install> stops
					 * the app enabling entirely, taking every route with it.
					 */
					try {
						$old = $this->userConfig->getValueString($userId, self::OLD_APP_ID, $key, '');
						if ($old === '') {
							continue;
						}

						$existing = $this->userConfig->getValueString($userId, Application::APP_ID, $key, '');
						if ($existing !== '') {
							$alreadyPresent++;
							continue;
						}

						$this->userConfig->setValueString($userId, Application::APP_ID, $key, $old);
						$migrated++;
					} catch (Throwable $e) {
						$this->logger->warning(
							'Decidiq: could not migrate one per-user preference; '
							. 'leaving it under the old app id',
							['key' => $key, 'exception' => $e->getMessage()]
						);
					}//end try
				}//end foreach
			}
		);

		if ($walked === false) {
			$output->warning(
				'MigrateUserPreferences: could not enumerate users; '
				. 'decidesk preferences were left in place.'
			);
			return;
		}

		if ($migrated === 0 && $alreadyPresent === 0) {
			$output->info(
				'MigrateUserPreferences: no stored decidesk user preferences on this install; nothing to do.'
			);
			return;
		}

		$output->info(
			sprintf(
				'MigrateUserPreferences: migrated %d preference(s); %d already set under decidiq.',
				$migrated,
				$alreadyPresent
			)
		);

	}//end run()

	/**
	 * Every preference key this user has stored under the old app id.
	 *
	 * Deliberately an enumeration of the DATA rather than of a hardcoded key
	 * list: `PreferencesController` writes under an open-ended `pref_*`
	 * namespace, so no constant could be complete. See the class docblock.
	 *
	 * @param string $userId The user whose keys to enumerate.
	 *
	 * @return array<int, string> The stored key names, empty when unreadable.
	 *
	 * @spec exclude Rename plumbing — see run().
	 */
	private function oldKeysFor(string $userId): array {
		try {
			return $this->userConfig->getKeys($userId, self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not enumerate decidesk preference keys for one user; skipping that user',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end oldKeysFor()

	/**
	 * Walk every user who has logged in at least once.
	 *
	 * A user who has never logged in cannot have set a preference, so
	 * `callForSeenUsers()` is both the cheaper and the complete enumeration
	 * here.
	 *
	 * @param callable(IUser):void $callback Invoked once per seen user.
	 *
	 * @return bool True when the walk completed, false when it could not run.
	 *
	 * @spec exclude Rename plumbing — see run().
	 */
	private function walkSeenUsers(callable $callback): bool {
		try {
			$this->userManager->callForSeenUsers($callback);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not enumerate users; skipping the per-user preference migration',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end walkSeenUsers()

}//end class
