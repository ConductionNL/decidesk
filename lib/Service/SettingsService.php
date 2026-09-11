<?php

/**
 * Decidiq Settings Service
 *
 * Service for managing Decidiq application configuration and settings.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Decidiq application configuration and settings.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
 */
class SettingsService {

	/**
	 * Configuration keys managed by this service.
	 *
	 * Includes the main register slug plus schema slugs for Minutes, Decision,
	 * and ActionItem so the frontend initializeStores() can register object stores.
	 * motion_min_cosigners (motion-amendment spec) is the configurable minimum
	 * co-signer count enforced on the motion proposed→deliberating transition
	 * (0 = disabled).
	 *
	 * @var array<string>
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-10
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-3
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private const CONFIG_KEYS = [
		'register',
		'ori_endpoint',
		'ori_bearer_secret',
		'email_voting_enabled',
		'minutesSchema',
		'decisionSchema',
		'actionItemSchema',
		// Co-signer minimum threshold per openspec/specs/motion-amendment/spec.md.
		'motion_min_cosigners',
		// Organization-level defaults per openspec/specs/admin-settings/spec.md
		// (Organization Configuration requirement).
		'organisation_name',
		'organisation_logo',
		'organisation_timezone',
		'organisation_locale',
		'organisation_currency',
		'organisation_retention_days',
		// Mode-aware label selection: gov|corp|assoc|ops|citizen.
		// @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting
		// Cosmetic UI hint only — drives no authorization decision.
		'organisatie_modus',
		// @spec openspec/specs/citizen-participation/spec.md
		// Citizen-participation instance defaults.
		'participation_default_moderation_policy',
		'participation_catalog',
		'participation_anon_rate_limit',
	];

	/**
	 * Write-only configuration keys: accepted by updateSettings() but never
	 * echoed back by getSettings(). The settings#index route is reachable by
	 * any authenticated user (#[NoAdminRequired]) and previously leaked the
	 * ORI bearer secret to non-admins; consumers (OriPublicationService) read
	 * the secret directly from IAppConfig.
	 *
	 * @var array<string>
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private const SECRET_KEYS = [
		'ori_bearer_secret',
	];

	/**
	 * Constructor for the SettingsService.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The container
	 * @param IGroupManager $groupManager The group manager
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
	 *
	 * @return bool
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end isOpenRegisterAvailable()

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * The Minutes, Decision, and ActionItem schema/register slugs are registered
	 * directly in src/store/store.js::OBJECT_TYPES (alongside all other entity types)
	 * and do not require additional settings keys — the frontend resolves them from the
	 * static OBJECT_TYPES map after confirming OpenRegister is available.
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-3.1
	 *
	 * @return array<string,mixed>
	 */
	public function getSettings(): array {
		// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-3.
		// @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting
		// Default schema slugs match the slugs defined in decidesk_register.json.
		// organisatie_modus defaults to 'gov' (government/municipal mode).
		$defaults = [
			'minutesSchema' => 'minutes',
			'decisionSchema' => 'decision',
			'actionItemSchema' => 'action-item',
			'organisatie_modus' => 'gov',
		];

		$settings = [];
		foreach (self::CONFIG_KEYS as $key) {
			if (in_array($key, self::SECRET_KEYS, true) === true) {
				// Write-only: never echo secrets to the (any-user) index route.
				continue;
			}

			$value = $this->appConfig->getValueString(Application::APP_ID, $key, '');
			$settings[$key] = ($defaults[$key] ?? '');
			if ($value !== '') {
				$settings[$key] = $value;
			}
		}

		$user = $this->userSession->getUser();
		$isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

		return array_merge(
			$settings,
			[
				'openregisters' => $this->isOpenRegisterAvailable(),
				// UI-HINT ONLY: isAdmin is used exclusively to control frontend rendering
				// (e.g. showing/hiding admin-only settings panels). It MUST NOT be used
				// for server-side access control decisions. All admin-gated backend routes
				// enforce the admin check independently via IGroupManager::isAdmin().
				'isAdmin' => $isAdmin,
			]
		);
	}//end getSettings()

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.2
	 *
	 * @return array<string,mixed> The updated settings
	 */
	public function updateSettings(array $data): array {
		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
			}
		}

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Load configuration from decidesk_register.json via OpenRegister.
	 *
	 * Import is skipped when the register is already configured at the same
	 * version/fragment signature. Use {@see reloadConfiguration()} to force a
	 * re-import.
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	public function loadConfiguration(): array {
		return $this->importConfiguration(force: false);
	}//end loadConfiguration()

	/**
	 * Re-import configuration from decidesk_register.json, ignoring the
	 * already-configured check.
	 *
	 * The forcing counterpart of {@see loadConfiguration()}; both delegate to
	 * the same import routine.
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	public function reloadConfiguration(): array {
		return $this->importConfiguration(force: true);
	}//end reloadConfiguration()

	/**
	 * Shared configuration import used by loadConfiguration()/reloadConfiguration().
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	private function importConfiguration(bool $force): array {
		if ($this->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Decidiq: OpenRegister not available, skipping register initialization');
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		try {
			[$configData, $failure] = $this->readBaseRegisterConfig();
			if ($failure !== null) {
				return $failure;
			}

			[$configData, $fragmentSig] = $this->mergeRegisterFragments(configData: $configData);

			return $this->importRegisterConfig(
				configData: $configData,
				fragmentSig: $fragmentSig,
				force: $force
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: configuration import failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => 'Configuration import failed. See server log for details.',
			];
		}//end try
	}//end importConfiguration()

	/**
	 * Read and decode the monolithic decidesk_register.json.
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array{0: array<string,mixed>, 1: array<string,mixed>|null} [configData, failureResult]
	 */
	private function readBaseRegisterConfig(): array {
		$configPath = __DIR__ . '/../Settings/decidesk_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error('Decidiq: decidesk_register.json not found at ' . $configPath);
			return [
				[],
				[
					'success' => false,
					'message' => 'Configuration file decidesk_register.json not found.',
				],
			];
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			$this->logger->error('Decidiq: failed to read decidesk_register.json');
			return [
				[],
				[
					'success' => false,
					'message' => 'Failed to read configuration file.',
				],
			];
		}

		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Decidiq: failed to parse decidesk_register.json: ' . json_last_error_msg());
			return [
				[],
				[
					'success' => false,
					'message' => 'Failed to parse configuration file: ' . json_last_error_msg(),
				],
			];
		}

		return [$configData, null];
	}//end readBaseRegisterConfig()

	/**
	 * Merge modular register fragments from Settings/register.d/*.json (ADR-037).
	 *
	 * Each OpenSpec change drops its own fragment file instead of editing the
	 * monolith, so concurrent builds touch disjoint files (no merge conflicts).
	 * OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
	 * fragments union cleanly by key.
	 *
	 * @param array<string,mixed> $configData The decoded base configuration.
	 *
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array{0: array<string,mixed>, 1: string} [mergedConfig, fragmentSignature]
	 */
	private function mergeRegisterFragments(array $configData): array {
		$fragmentDir = __DIR__ . '/../Settings/register.d';
		$fragmentSig = '';
		if (is_dir($fragmentDir) === false) {
			return [$configData, $fragmentSig];
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
		sort($fragmentFiles);
		foreach ($fragmentFiles as $fragmentFile) {
			$fragmentContent = file_get_contents($fragmentFile);
			if ($fragmentContent === false) {
				continue;
			}

			$fragmentData = json_decode($fragmentContent, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->logger->warning(
					'Decidiq: skipping malformed register fragment ' . basename($fragmentFile)
					. ': ' . json_last_error_msg()
				);
				continue;
			}

			$configData = self::deepMergeConfig(base: $configData, overlay: $fragmentData);
			$fragmentSig .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';
		}//end foreach

		return [$configData, $fragmentSig];
	}//end mergeRegisterFragments()

	/**
	 * Hand the merged configuration to OpenRegister's version-gated importer.
	 *
	 * The fragment signature is folded into the version so importFromApp
	 * re-imports whenever any fragment changes.
	 *
	 * @param array<string,mixed> $configData The merged configuration.
	 * @param string $fragmentSig Signature of the merged fragments.
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	private function importRegisterConfig(array $configData, string $fragmentSig, bool $force): array {
		$configVersion = ($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$configVersion .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
		$result = $configurationService->importFromApp(
			appId: Application::APP_ID,
			data: $configData,
			version: $configVersion,
			force: $force
		);

		if (empty($result) === true) {
			return [
				'success' => false,
				'message' => 'Import returned an empty result.',
			];
		}

		$this->logger->info('Decidiq: register configuration imported successfully');

		return [
			'success' => true,
			'message' => 'Configuration imported successfully.',
			'version' => ($result['version'] ?? 'unknown'),
		];
	}//end importRegisterConfig()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			// A scalar overlay, or a key the base does not hold as an array,
			// overwrites the base outright.
			if (is_array($value) === false
				|| isset($base[$key]) === false
				|| is_array($base[$key]) === false
			) {
				$base[$key] = $value;
				continue;
			}

			$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
			$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));

			// Two list arrays concatenate; anything else recurses by key union.
			if ($baseIsList === true && $overlayIsList === true) {
				$base[$key] = array_merge($base[$key], $value);
				continue;
			}

			$base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value);
		}//end foreach

		return $base;
	}//end deepMergeConfig()
}//end class
