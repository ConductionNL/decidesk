<?php

/**
 * Decidiq Migrate App Config Keys Repair Step
 *
 * Repair step that carries this app's stored `IAppConfig` values across the
 * `decidesk` -> `decidiq` app-id rename.
 *
 * Nextcloud namespaces `IAppConfig` by app id at the storage layer
 * (`oc_appconfig.appid`), so renaming `<id>` does not rename the rows — it
 * makes every previously stored value unreachable, because the app now asks
 * for them under a different app id. There is no in-place app-id upgrade in
 * Nextcloud: the new id is simply a different app. This step therefore copies
 * each value from the old namespace to the new one.
 *
 * WHY THIS MATTERS PARTICULARLY HERE. `voter_token_secret` is the HMAC key
 * that signs voting tokens and mail-reply links. `InitializeSettings` GENERATES
 * a fresh one whenever it finds none — so without this step running FIRST, the
 * rename would orphan the old key, InitializeSettings would mint a new one, and
 * every outstanding vote link and mail-reply link in flight would stop
 * verifying. Nothing would throw: the tokens would simply fail their HMAC check
 * and read as invalid votes. That is why both steps are registered ahead of
 * InitializeSettings under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml`.
 *
 * WHY EVERY KEY RATHER THAN A FIXED LIST. `IAppConfig::getKeys()` is
 * exhaustive by construction and cannot drift out of date the way a hardcoded
 * list does. Past releases have written keys this app no longer reads, and the
 * admin settings surface writes whatever the settings map currently holds.
 *
 * SENSITIVITY IS CARRIED WITH THE VALUE. A sensitive value copied with a plain
 * `setValueString()` would land UNFLAGGED in the new namespace — printed in
 * cleartext by `occ config:list` and in every support dump that feeds. For
 * `voter_token_secret` that is a vote-forgery key in a support bundle, so the
 * flag is read from the old namespace and re-applied on the write.
 *
 * SAFETY. Idempotent and non-destructive:
 *   - a key is copied only when the old value is non-empty AND the new
 *     namespace does not already hold a value, so an admin edit made after the
 *     rename is never clobbered and a second run is a no-op;
 *   - the old `decidesk` rows are never deleted, so a rollback to the previous
 *     app id still finds its configuration intact;
 *   - values round-trip as raw strings. `IAppConfig` stores every value as a
 *     string and the typed accessors only coerce on read, so a string
 *     round-trip cannot lose or corrupt a value written by a typed setter;
 *   - every failure is logged and the loop continues. A repair step that
 *     throws aborts the install, and a config value that could not be copied
 *     is not worth failing an install over — the app falls back to its
 *     defaults and the admin can re-enter the setting.
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
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy every stored IAppConfig value from the decidesk namespace to decidiq.
 *
 * @spec exclude One-off decidesk->decidiq app-id rename plumbing: it moves
 *       IAppConfig rows between app-id namespaces and adds no behaviour of
 *       its own.
 */
class MigrateAppConfigKeys implements IRepairStep {

	/**
	 * The app-config namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id. This constant is one of the few places in
	 * the app that is supposed to still say `decidesk`.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'decidesk';

	/**
	 * Config keys Nextcloud owns for every app. These MUST NOT be copied.
	 *
	 * `AppManager::enableApp()` writes `enabled` through the deprecated
	 * `IAppConfig::setValue()`, which stores type MIXED. Copying it here with
	 * `setValueString()` stores type STRING, and the next `app:enable` then
	 * fails with an `AppConfigTypeConflictException` — permanently, because the
	 * conflict is hit before the app can run anything that would repair it.
	 * `installed_version` and `types` are Nextcloud's own bookkeeping for the
	 * app and copying the old app's values would misreport the new one.
	 *
	 * @var string[]
	 */
	private const RESERVED_KEYS = [
		'enabled',
		'installed_version',
		'types',
	];

	/**
	 * Constructor for MigrateAppConfigKeys.
	 *
	 * @param IAppConfig $appConfig The app config store to read and write.
	 * @param LoggerInterface $logger Logger for keys that fail to copy.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude One-off decidesk->decidiq app-id rename plumbing: it moves
	 *       IAppConfig rows between namespaces and adds no behaviour of its own.
	 */
	public function getName(): string {
		return 'Copy Decidiq app configuration from the decidesk namespace to decidiq';
	}//end getName()

	/**
	 * Run the repair step to migrate the stored app configuration.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude One-off decidesk->decidiq app-id rename plumbing: it moves
	 *       IAppConfig rows between namespaces and adds no behaviour of its own.
	 *       The settings it preserves are specified where they are read — the
	 *       voter token secret in
	 *       openspec/specs/decision-methods/spec.md, and the admin surface in
	 *       openspec/specs/admin-settings/spec.md.
	 */
	public function run(IOutput $output): void {
		$keys = $this->oldKeys();
		if ($keys === []) {
			$output->info(
				'MigrateAppConfigKeys: no stored decidesk configuration on this install; nothing to do.'
			);
			return;
		}

		$migrated = 0;
		$alreadyPresent = 0;
		$emptySource = 0;
		$skippedReserved = 0;

		foreach ($keys as $key) {
			if (in_array($key, self::RESERVED_KEYS, strict: true) === true) {
				$skippedReserved++;
				continue;
			}

			/*
			 * The READS belong inside the try as much as the write does. Two
			 * earlier apps in this rename programme shipped a step whose
			 * getValueString() calls sat OUTSIDE it, so an unreadable value
			 * propagated out of run(). Because this step is also registered
			 * under <install>, a repair step that throws does not merely fail
			 * an upgrade — the app never enables, and every route goes with
			 * it. One unreadable key is not worth an install.
			 */
			try {
				$old = $this->appConfig->getValueString(self::OLD_APP_ID, $key, '');
				if ($old === '') {
					$emptySource++;
					continue;
				}

				$existing = $this->appConfig->getValueString(Application::APP_ID, $key, '');
				if ($existing !== '') {
					$alreadyPresent++;
					continue;
				}

				$this->appConfig->setValueString(
					Application::APP_ID,
					$key,
					$old,
					sensitive: $this->isSensitiveKey(key: $key)
				);
				$migrated++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Decidiq: could not migrate one app config key; leaving it under the old namespace',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(
			'MigrateAppConfigKeys: ' . $migrated . ' key(s) migrated, ' . $alreadyPresent
			. ' already present, ' . $emptySource . ' had no value to migrate, '
			. $skippedReserved . ' skipped as Nextcloud-reserved.'
		);

	}//end run()

	/**
	 * Every key currently stored under the old app-config namespace.
	 *
	 * @return array<int, string> The stored key names, empty when unreadable.
	 *
	 * @spec exclude Rename plumbing — see run().
	 */
	private function oldKeys(): array {
		try {
			return $this->appConfig->getKeys(self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not enumerate decidesk app config keys; skipping the migration',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end oldKeys()

	/**
	 * Whether the old namespace flagged this key as sensitive.
	 *
	 * Copying a sensitive value without its flag would leave it readable in
	 * cleartext in `occ config:list` output and every support dump — for
	 * `voter_token_secret` that is a vote-forgery key. Failing to READ the flag
	 * must not fail the copy, so an unreadable flag falls back to treating the
	 * value as sensitive: over-redacting a value is recoverable, under-
	 * redacting a signing key is not.
	 *
	 * @param string $key The config key being copied.
	 *
	 * @return bool True when the value should be written back flagged sensitive.
	 *
	 * @spec exclude Rename plumbing — see run().
	 */
	private function isSensitiveKey(string $key): bool {
		try {
			return $this->appConfig->isSensitive(self::OLD_APP_ID, $key);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not read the sensitivity flag for an app config key; '
				. 'copying it as sensitive to avoid exposing a secret',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return true;
		}//end try

	}//end isSensitiveKey()

}//end class
