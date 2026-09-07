<?php

/**
 * Decidiq Migrate Admin Group Repair Step
 *
 * Repair step that carries the app's administrators group across the
 * `decidesk` -> `decidiq` app-id rename.
 *
 * The register configuration's authorization baseline names an
 * administrators group for the griffie/secretariat that must edit records it
 * did not create, and OpenRegister's GroupProvisioner CREATES every group an
 * authorization block names during the register import. Until this change the
 * block named `decidesk-administrators` — the pre-rename app id — so every
 * existing install holds a group under the OLD id, with real memberships an
 * administrator granted by hand.
 *
 * WHY MIGRATE RATHER THAN RENAME. A Nextcloud group id (gid) is the primary
 * key of the group across every backend; `IGroupManager` exposes create and
 * delete but no rename, and `IGroup::setDisplayName()` changes the label, not
 * the id the authorization rows match on. The only rename available would be
 * delete-and-recreate, which destroys memberships and shares. So this step
 * CREATES `decidiq-administrators` (when the import's GroupProvisioner has not
 * already) and COPIES every member of the old group into it.
 *
 * WHY THE OLD GROUP STAYS, HONORED. The authorization arrays in
 * `lib/Settings/decidesk_register.json` now name BOTH groups, new id first.
 * The old group is never deleted and never has a member removed, for the same
 * reason the other rename legs keep their old rows: a rollback still finds
 * them, and an install where this step could not copy a member (an LDAP-backed
 * read-only group backend refuses local writes) keeps working through the old
 * name. The old name can only be retired once no install's group backend
 * depends on it — a decision about data, not a migration.
 *
 * SAFETY. Idempotent and non-destructive:
 *   - when the old group does not exist there is nothing to migrate and the
 *     step is a no-op — a fresh install gets the NEW group provisioned empty
 *     by the register import, preserving the documented admin-provisioned,
 *     fail-closed posture (no group means owner-plus-admin only);
 *   - a member already in the new group is skipped, so a second run is a
 *     no-op;
 *   - every failure is logged and the loop continues. This step is registered
 *     under `<install>`, where a throwing repair step means the app never
 *     enables at all — one uncopyable membership is not worth that.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml`, after InitializeSettings — the import that step
 * triggers provisions the new group, so this step normally only copies
 * members (and creates the group itself only when the import could not).
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

use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy the decidesk-administrators group's members to decidiq-administrators.
 *
 * @spec exclude No canonical spec covers the `decidesk` -> `decidiq`
 *  administrators-group migration. Pointing this at an existing spec would
 *  report conformance to a requirement that says nothing about it.
 */
class MigrateAdminGroup implements IRepairStep {

	/**
	 * The administrators group id under the OLD app id.
	 *
	 * Deliberately still `decidesk`: this constant and the honored entry in
	 * `lib/Settings/decidesk_register.json` are the places that are supposed
	 * to keep saying it.
	 *
	 * @var string
	 */
	private const OLD_GROUP_ID = 'decidesk-administrators';

	/**
	 * The canonical administrators group id under the current app id.
	 *
	 * @var string
	 */
	private const NEW_GROUP_ID = 'decidiq-administrators';

	/**
	 * Constructor for MigrateAdminGroup.
	 *
	 * @param IGroupManager $groupManager Group existence, creation and membership.
	 * @param LoggerInterface $logger Logger for members that fail to copy.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec exclude One-off decidesk->decidiq app-id rename plumbing: it
	 *       mirrors group memberships between the old-id and new-id
	 *       administrators groups and adds no behaviour of its own.
	 */
	public function getName(): string {
		return 'Copy the Decidiq administrators group from the decidesk group id';
	}//end getName()

	/**
	 * Ensure decidiq-administrators exists and carries every member of the
	 * old-id group.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec exclude One-off decidesk->decidiq app-id rename plumbing: it
	 *       mirrors group memberships between the old-id and new-id
	 *       administrators groups and adds no behaviour of its own. The
	 *       authorization the groups feed is specified in
	 *       lib/Settings/decidesk_register.json's register-level baseline.
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->groupManager->groupExists(self::OLD_GROUP_ID) === false) {
				// Nothing to migrate. The register import provisions the new
				// group (empty) from the authorization declaration; creating
				// it here too would only duplicate that work.
				return;
			}

			$oldGroup = $this->groupManager->get(self::OLD_GROUP_ID);
			if ($oldGroup === null) {
				return;
			}

			if ($this->groupManager->groupExists(self::NEW_GROUP_ID) === false) {
				$this->groupManager->createGroup(self::NEW_GROUP_ID);
			}

			$newGroup = $this->groupManager->get(self::NEW_GROUP_ID);
			if ($newGroup === null) {
				$this->logger->warning(
					'Decidiq: could not resolve the new administrators group; '
					. 'the old-id group stays honored by the authorization baseline',
					['group' => self::NEW_GROUP_ID]
				);
				return;
			}

			$copied = 0;
			foreach ($oldGroup->getUsers() as $user) {
				try {
					if ($newGroup->inGroup($user) === true) {
						continue;
					}

					$newGroup->addUser($user);
					$copied++;
				} catch (Throwable $e) {
					$this->logger->warning(
						'Decidiq: could not copy one administrators-group member; '
						. 'the member keeps access through the old-id group',
						['user' => $user->getUID(), 'exception' => $e->getMessage()]
					);
				}
			}//end foreach

			$output->info(
				sprintf(
					'Decidiq administrators group: copied %d member(s) from %s to %s',
					$copied,
					self::OLD_GROUP_ID,
					self::NEW_GROUP_ID
				)
			);
		} catch (Throwable $e) {
			// A repair step registered under <install> that throws stops the
			// app enabling entirely; a group backend hiccup is not worth that.
			$this->logger->error(
				'Decidiq: administrators-group migration failed; '
				. 'members keep access through the old-id group',
				['exception' => $e]
			);
		}//end try
	}//end run()
}//end class
