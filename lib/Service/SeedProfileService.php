<?php
/**
 * Decidiq SeedProfileService.
 *
 * Imports ONE example set from `lib/Settings/profiles/*.json` on request.
 *
 * 🔴 THIS EXISTS BECAUSE INSTALL USED TO PLANT 334 OBJECTS NOBODY ASKED FOR.
 * Every `register.d` fragment carried its own `x-openregister.seedData`, and
 * `SettingsService::loadConfiguration()` merges all of them, so a fresh install
 * of this app seeded a Gemeenteraad Amsterdam, a VvE Zeewaarts, a pub quiz and
 * eight placeholder TOOI mappings into the operator's register, whether or not
 * any of it described their organisation. The setup wizard meanwhile offered a
 * SEPARATE dataset and told the operator to "skip this on a production
 * install" — advice that was already too late by the time they read it.
 *
 * So the fragments now declare schemas only, and the objects live here, split
 * into example sets an operator picks. A bare install plants nothing.
 *
 * 🔴 A PROFILE NEVER DECLARES `components.registers`, AND THAT IS LOAD-BEARING.
 * `ImportHandler::importRegister()` calls `setApplication($appId)`
 * unconditionally when it updates an existing register, so a descriptor that
 * declared `decidiq` would re-point the register at this service's config id
 * and hydrate over its `authorization` baseline — the block that stops any
 * authenticated user rewriting another body's decisions. Instead every seed
 * object carries `@self.configuration/register/schema`, which
 * `ImportHandler::importSeedDataObjects()` resolves per object. Verified on a
 * live instance: importing this shape left `application=decidiq`, the version
 * and the authorization block byte-identical.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lists the example sets this app ships and imports the one an operator picked.
 *
 * @spec openspec/changes/seed-profiles/specs/seed-profiles/spec.md
 */
class SeedProfileService {
	/**
	 * App-relative directory holding the example-set descriptors.
	 *
	 * 🔴 A SUBDIRECTORY ON PURPOSE. OpenRegister's `RegisterDescriptorService`
	 * scans `lib/Settings/*.json` NON-recursively and indexes what it finds by
	 * the register slug the file declares. Four profile files sitting in
	 * `lib/Settings` would therefore collide with each other and with
	 * `decidesk_register.json`, and `descriptorFor()` returns the FIRST match,
	 * so `occ openregister:descriptors:list` would silently describe one
	 * arbitrary profile as this app's register.
	 *
	 * @var string
	 */
	private const PROFILE_DIR = '/lib/Settings/profiles';

	/**
	 * The one profile id that is not a file: OpenRegister's generated mock.
	 *
	 * ADR-111 rule 2 requires the schema-generated dataset to stay on offer, and
	 * rule 1 requires the walkthrough to open with it. Folding it in here as an
	 * option keeps the operator's question single ("which example set?") instead
	 * of asking twice about the same thing in two different steps.
	 *
	 * @var string
	 */
	public const GENERATED_PROFILE = 'generated';

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_PROFILE = 'none';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Resolves this app's path and version.
	 * @param ContainerInterface $container  Resolves OpenRegister's importer.
	 * @param LoggerInterface    $logger     Records what was imported.
	 * @param DemoDataService    $demoData   Imports the ADR-111 generated dataset.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly DemoDataService $demoData,
	) {
	}//end __construct()

	/**
	 * Every example set this app ships, in the order the wizard should offer them.
	 *
	 * Read from disk rather than from a list in code: the descriptors ARE the
	 * source of truth, so a set that ships without being listed here is
	 * impossible by construction.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer}> The sets.
	 *
	 * @spec openspec/changes/seed-profiles/specs/seed-profiles/spec.md#requirement-list-example-sets
	 */
	public function listProfiles(): array {
		$profiles = [];
		foreach ($this->descriptorFiles() as $file) {
			$meta = $this->readProfileMeta(path: $file);
			if ($meta !== null) {
				$profiles[] = $meta;
			}
		}

		usort($profiles, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']));

		$listed = [];
		foreach ($profiles as $profile) {
			unset($profile['order']);
			$listed[] = $profile;
		}

		if ($this->demoData->isAvailable() === true) {
			$listed[] = [
				'id'          => self::GENERATED_PROFILE,
				'label'       => 'Every schema, generated values',
				'description' => 'Sample values generated from the schemas themselves. Use it to exercise the data model, not to demo the app.',
				'objectCount' => 0,
				'icon'        => 'DatabaseOutline',
			];
		}

		return $listed;

	}//end listProfiles()

	/**
	 * Every answer the wizard may offer, declining included.
	 *
	 * 🔴 SEPARATE FROM `listProfiles()` ON PURPOSE. `none` is an ANSWER, not a
	 * set: `isKnown()` must keep saying no to it, or `install()` would be asked
	 * to import a descriptor that does not exist. This list is for the choice
	 * step; that one is for the importer.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec openspec/changes/example-set-cards/specs/example-set-cards/spec.md#requirement-the-wizard-offers-the-sets-the-app-ships
	 */
	public function listChoices(): array {
		$choices = [
			[
				'id'          => self::NONE_PROFILE,
				'label'       => 'None, I will set this up myself',
				'description' => 'Nothing is imported. You start with an empty app and add your own bodies, meetings and decisions.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
		];

		return array_merge($choices, $this->listProfiles());

	}//end listChoices()

	/**
	 * Whether an id names a set this app can actually import.
	 *
	 * @param string $profileId The id to test.
	 *
	 * @return boolean True when the id is importable.
	 *
	 * @spec openspec/changes/example-set-cards/specs/example-set-cards/spec.md#requirement-the-wizard-offers-the-sets-the-app-ships
	 */
	public function isKnown(string $profileId): bool {
		if ($profileId === self::GENERATED_PROFILE) {
			return $this->demoData->isAvailable();
		}

		foreach ($this->listProfiles() as $profile) {
			if ($profile['id'] === $profileId) {
				return true;
			}
		}

		return false;

	}//end isKnown()

	/**
	 * Import one example set.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. An operator just asked for
	 * this, so "nothing happened" must not be presentable as success.
	 *
	 * Safe to run more than once: OpenRegister's seed import matches an existing
	 * object by slug before it creates one, so a repeat adds nothing.
	 *
	 * @param string $profileId The set to import.
	 *
	 * @return array{objects: integer, profile: string} What was imported.
	 *
	 * @throws RuntimeException When the id is unknown or OpenRegister is absent.
	 *
	 * @spec openspec/changes/seed-profiles/specs/seed-profiles/spec.md#requirement-import-one-example-set
	 */
	public function install(string $profileId): array {
		if ($profileId === self::GENERATED_PROFILE) {
			$imported = $this->demoData->install();
			return [
				'objects' => (int)($imported['objects'] ?? 0),
				'profile' => $profileId,
			];
		}

		$path = $this->pathFor(profileId: $profileId);
		if ($path === null) {
			throw new RuntimeException('No example set is called "' . $profileId . '".');
		}

		$data = $this->readDescriptor(path: $path);

		$objects = 0;
		$seeded  = ($data['x-openregister']['seedData']['objects'] ?? []);
		if (is_array($seeded) === true) {
			foreach ($seeded as $forSchema) {
				$objects += count((array)$forSchema);
			}
		}

		$this->configurationService()->importFromApp(
			appId: Application::APP_ID . '.profile.' . $profileId,
			data: $data,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		$this->logger->info(
			'[SeedProfileService] imported example set "' . $profileId . '": ' . $objects . ' object(s).',
			['app' => Application::APP_ID]
		);

		return [
			'objects' => $objects,
			'profile' => $profileId,
		];

	}//end install()

	/**
	 * Absolute paths of the shipped descriptors.
	 *
	 * @return array<int, string> The paths, sorted.
	 */
	private function descriptorFiles(): array {
		$dir = ($this->appManager->getAppPath(Application::APP_ID) . self::PROFILE_DIR);
		if (is_dir($dir) === false) {
			return [];
		}

		$files = glob($dir . '/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);

		return $files;

	}//end descriptorFiles()

	/**
	 * The descriptor path for one id, or null when no file declares it.
	 *
	 * 🔴 RESOLVED BY READING THE FILES, NEVER BY CONCATENATING THE ID INTO A
	 * PATH. The id arrives from an HTTP request; building `profiles/$id.json`
	 * from it would make `../../config/config` a readable file name.
	 *
	 * @param string $profileId The id to resolve.
	 *
	 * @return string|null The path, or null.
	 */
	private function pathFor(string $profileId): ?string {
		foreach ($this->descriptorFiles() as $file) {
			$meta = $this->readProfileMeta(path: $file);
			if ($meta !== null && $meta['id'] === $profileId) {
				return $file;
			}
		}

		return null;

	}//end pathFor()

	/**
	 * The `x-openregister.profile` block of one descriptor.
	 *
	 * A malformed or non-profile file is SKIPPED rather than fatal: one bad file
	 * must not make every other example set unreachable.
	 *
	 * @param string $path The descriptor path.
	 *
	 * @return array{id: string, label: string, description: string, order: integer, objectCount: integer, icon: string}|null The block, or null.
	 */
	private function readProfileMeta(string $path): ?array {
		$raw = file_get_contents($path);
		if ($raw === false) {
			$this->logger->warning('[SeedProfileService] unreadable example set: ' . basename($path));
			return null;
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			$this->logger->warning('[SeedProfileService] malformed example set: ' . basename($path));
			return null;
		}

		$profile = ($data['x-openregister']['profile'] ?? null);
		if (is_array($profile) === false || is_string(($profile['id'] ?? null)) === false) {
			$this->logger->warning('[SeedProfileService] example set declares no profile block: ' . basename($path));
			return null;
		}

		return [
			'id'          => (string)$profile['id'],
			'label'       => (string)($profile['label'] ?? $profile['id']),
			'description' => (string)($profile['description'] ?? ''),
			'order'       => (int)($profile['order'] ?? 99),
			'objectCount' => (int)($profile['objectCount'] ?? 0),
			'icon'        => (string)($profile['icon'] ?? ''),
		];

	}//end readProfileMeta()

	/**
	 * Read and decode one descriptor, or throw.
	 *
	 * @param string $path The descriptor path.
	 *
	 * @return array<string, mixed> The decoded descriptor.
	 *
	 * @throws RuntimeException When the file cannot be read or parsed.
	 */
	private function readDescriptor(string $path): array {
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The example set could not be read: ' . basename($path));
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			throw new RuntimeException('The example set is not valid JSON: ' . basename($path));
		}

		return $data;

	}//end readDescriptor()

	/**
	 * OpenRegister's configuration importer.
	 *
	 * 🔴 THE RETURN TYPE IS `object`, NOT THE CLASS, AND THAT IS THE POINT.
	 * Naming a class from an OPTIONAL app in a native return type makes PHP
	 * resolve it whenever this method returns, so on an instance without
	 * OpenRegister the failure is a TypeError about a class nobody mentioned
	 * instead of the RuntimeException below that names the missing app.
	 *
	 * @return object The importer, an OCA\OpenRegister\Service\ConfigurationService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ConfigurationService
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function configurationService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('Example data needs OpenRegister, which is not installed.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');

	}//end configurationService()
}//end class
