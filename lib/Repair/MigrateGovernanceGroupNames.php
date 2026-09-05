<?php
/**
 * Decidiq MigrateGovernanceGroupNames.
 *
 * Copies the membership of the two notification groups that were named in one
 * country's words onto groups named for what they do.
 *
 * @category Repair
 * @package  OCA\Decidiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Renames the governance notification groups into plain words.
 *
 * 🔴 ADDITIVE. The old group is never deleted and never emptied. An admin who
 * still has automation pointing at it keeps a working group; the schemas simply
 * stop naming it.
 *
 * @spec exclude One-off group-id rename plumbing: it mirrors memberships
 *       between an old-id and a new-id group and adds no behaviour of its own.
 *       The authorization these groups feed is specified in
 *       lib/Settings/decidesk_register.json's register-level baseline.
 */
class MigrateGovernanceGroupNames implements IRepairStep {

	/**
	 * Old group id => new group id.
	 *
	 * `griffie` is a Dutch council's clerk office. `decidesk-integriteit` is
	 * that word in Dutch, under the previous app id. Both are recipient groups
	 * on schema notifications, so what they need is a name that says what the
	 * group DOES.
	 *
	 * `decidesk-members` is deliberately absent: that prefix is the old app id
	 * and it moves in a coordinated fleet pass, not here.
	 *
	 * @var array<string,string>
	 */
	private const RENAMES = [
		'griffie' => 'decidiq-secretariat',
		'decidesk-integriteit' => 'decidiq-integrity',
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager   $groupManager Group existence, creation and membership.
	 * @param LoggerInterface $logger       Records members that fail to copy.
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
	 * @return string The label.
	 *
	 * @spec exclude Trivial repair-step label accessor.
	 */
	public function getName(): string {
		return 'Copy the Decidiq governance notification groups onto names in plain words';

	}//end getName()

	/**
	 * Mirror each old group's membership onto its renamed counterpart.
	 *
	 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude One-off group-id rename plumbing; see the class docblock.
	 */
	public function run(IOutput $output): void {
		$copied = 0;

		foreach (self::RENAMES as $old => $new) {
			try {
				$copied += $this->mirror(old: $old, new: $new);
			} catch (Throwable $e) {
				$output->warning('Could not migrate the group ' . $old . ': ' . $e->getMessage());
				$this->logger->warning(
					'Decidiq: governance group rename failed',
					['old' => $old, 'new' => $new, 'exception' => $e->getMessage()]
				);
			}
		}

		$output->info('Decidiq governance group rename complete: ' . $copied . ' membership(s) copied.');

	}//end run()

	/**
	 * Copy every member of one group into another, creating it if needed.
	 *
	 * @param string $old The group id being retired from the schemas.
	 * @param string $new The group id the schemas now name.
	 *
	 * @return int How many memberships were added.
	 */
	private function mirror(string $old, string $new): int {
		if ($this->groupManager->groupExists($old) === false) {
			// Nothing to migrate. The new group is provisioned empty by the
			// register import from the notification declaration; creating it
			// here as well would only duplicate that work.
			return 0;
		}

		$oldGroup = $this->groupManager->get($old);
		if ($oldGroup === null) {
			return 0;
		}

		if ($this->groupManager->groupExists($new) === false) {
			$this->groupManager->createGroup($new);
		}

		$newGroup = $this->groupManager->get($new);
		if ($newGroup === null) {
			$this->logger->warning(
				'Decidiq: could not resolve the renamed group; the old group keeps its members',
				['group' => $new]
			);
			return 0;
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
					'Decidiq: could not copy a user into the renamed group',
					['user' => $user->getUID(), 'group' => $new, 'exception' => $e->getMessage()]
				);
			}
		}

		return $copied;

	}//end mirror()
}//end class
