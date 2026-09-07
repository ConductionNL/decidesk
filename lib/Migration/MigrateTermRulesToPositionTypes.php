<?php
/**
 * Decidiq MigrateTermRulesToPositionTypes.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * retire-the-unbuilt-rooster change: copies every `termijn-regeling` row onto
 * the generic `position-type`.
 *
 * 🔴 ONLY THE CONFIGURATION MOVES. `rooster-van-aftreden` and `rooster-regel`
 * are retired WITHOUT being copied: both are a projection of source data, their
 * generator was never written, and PositionHold requires a start date a rooster
 * regel never recorded. Writing source rows from a stale projection would invent
 * facts. The rows stay readable under their original schemas.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies term rules onto the positions they are rules about.
 *
 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md
 */
class MigrateTermRulesToPositionTypes implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The generic target schema slug.
	 *
	 * @var string
	 */
	private const TARGET = 'position-type';

	/**
	 * The key recording which source row a copied record came from.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * Readable names for the role enum the retired schema keyed on.
	 *
	 * A position type is NAMED, where a term rule was keyed on one of seven
	 * fixed roles. An unlisted value is title-cased rather than dropped: the
	 * enum could grow, and a position called 'Observer' reads better than one
	 * called 'observer' either way.
	 *
	 * @var array<string,string>
	 */
	private const ROLE_NAMES = [
		'chair' => 'Chair',
		'vice-chair' => 'Vice-chair',
		'secretary' => 'Secretary',
		'treasurer' => 'Treasurer',
		'member' => 'Member',
		'observer' => 'Observer',
		'guest' => 'Guest',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface $container       Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface    $logger          Records what was migrated.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The logger the shared legacy-row reads report through.
	 *
	 * @return LoggerInterface The logger.
	 *
	 * @spec exclude Trait accessor; exposes an already-injected dependency.
	 */
	protected function migrationLogger(): LoggerInterface {
		return $this->logger;

	}//end migrationLogger()

	/**
	 * Repair-step label.
	 *
	 * @return string The label.
	 *
	 * @spec exclude Trivial repair-step label accessor.
	 */
	public function getName(): string {
		return 'Copy Decidiq term rules onto the positions they govern';

	}//end getName()

	/**
	 * Run the copy.
	 *
	 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md#requirement-term-rules-are-carried-across
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->info('OpenRegister unavailable — nothing to migrate.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('Could not resolve OpenRegister ObjectService: ' . $e->getMessage());
			return;
		}

		// 🔴 RUN AS SYSTEM. A repair step has no session, so OpenRegister sees
		// the actor as 'Anonymous' and refuses `create`, and this step reports
		// that as a warning, which does not fail an upgrade.
		$objectService->runAsSystem(
			function () use ($objectService, $output): void {
				$this->migrateAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Copy every term rule, inside the caller's system scope.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-the-unbuilt-rooster/specs/retire-the-unbuilt-rooster/spec.md#requirement-term-rules-are-carried-across
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$existing = $this->originIndex(objectService: $objectService);
		$migrated = 0;

		foreach ($this->readRows(objectService: $objectService, schema: 'termijn-regeling', limit: 10000) as $source) {
			$origin = $this->identifierOf(object: $source);
			if ($origin === '' || isset($existing[$origin]) === true) {
				continue;
			}

			$body = $this->resolveBody(
				objectService: $objectService,
				reference: (string)($source['body'] ?? '')
			);
			if ($body === '') {
				// `governanceBody` is required on the target, and a position
				// belonging to no body is not a position.
				continue;
			}

			try {
				$objectService->setRegister(self::REGISTER);
				$objectService->setSchema(self::TARGET);
				$objectService->saveObject(
					register: self::REGISTER,
					schema: self::TARGET,
					object: $this->mapPositionType(source: $source, body: $body, origin: $origin),
				);
				$existing[$origin] = true;
				$migrated++;
			} catch (Throwable $e) {
				$output->warning('Failed to migrate a term rule: ' . $e->getMessage());
				$this->logger->warning(
					'Decidiq: position-type migration failed for one row',
					['error' => $e->getMessage(), 'origin' => $origin]
				);
			}//end try
		}//end foreach

		$output->info('Decidiq term-rule migration complete: ' . $migrated . ' position type(s).');

	}//end migrateAll()

	/**
	 * Position types already copied, keyed by the source they came from.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Keyed by source identifier.
	 */
	private function originIndex(object $objectService): array {
		$index = [];

		foreach ($this->readRows(objectService: $objectService, schema: self::TARGET, limit: 10000) as $object) {
			$origin = trim((string)($object[self::ORIGIN_KEY] ?? ''));
			if ($origin !== '') {
				$index[$origin] = true;
			}
		}

		return $index;

	}//end originIndex()

	/**
	 * Map one term rule onto a position type.
	 *
	 * @param array<string,mixed> $source The legacy row.
	 * @param string              $body   The already-resolved governance body.
	 * @param string              $origin The source identifier.
	 *
	 * @return array<string,mixed> The generic payload.
	 */
	private function mapPositionType(array $source, string $body, string $origin): array {
		$role = trim((string)($source['role'] ?? ''));

		$payload = [
			'name' => $this->nameForRole(role: $role),
			'governanceBody' => $body,
			self::ORIGIN_KEY => $origin,
		];

		$duration = ($source['termDurationMonths'] ?? null);
		if (is_numeric($duration) === true) {
			$payload['termDurationMonths'] = (int)$duration;
		}

		// `maxAansluitendeTermijnen` under one name becomes the same number under
		// another; nothing about the rule changes.
		$maximum = ($source['maxConsecutivePeriods'] ?? null);
		if (is_numeric($maximum) === true) {
			$payload['maxConsecutiveTerms'] = (int)$maximum;
		}

		$notes = trim((string)($source['notes'] ?? ''));
		if ($notes !== '') {
			$payload['notes'] = $notes;
		}

		return $payload;

	}//end mapPositionType()

	/**
	 * A readable position name for a retired role value.
	 *
	 * @param string $role The legacy role.
	 *
	 * @return string The position name.
	 */
	private function nameForRole(string $role): string {
		if ($role === '') {
			// A body-wide rule named no role. It becomes the position every body
			// has, which is what a body-wide rule was about.
			return 'Member';
		}

		return (self::ROLE_NAMES[$role] ?? ucfirst(str_replace('-', ' ', $role)));

	}//end nameForRole()
}//end class
