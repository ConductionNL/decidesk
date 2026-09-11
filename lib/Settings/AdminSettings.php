<?php

/**
 * Decidiq Admin Settings
 *
 * Provides the admin settings form for the Decidiq application.
 *
 * @category Settings
 * @package  OCA\Decidiq\Settings
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

namespace OCA\Decidiq\Settings;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\PublicationConfigService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;

/**
 * Provides the admin settings form for the Decidiq application.
 *
 * Implements IDelegatedSettings so the form can be guarded by
 * #[AuthorizedAdminSetting(AdminSettings::class)] on the controllers that
 * mutate Decidiq configuration.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class AdminSettings implements IDelegatedSettings {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param IInitialState $initialState The initial state service.
	 * @param PublicationConfigService $publicationConfig The publication configuration service.
	 */
	public function __construct(
		private IAppManager $appManager,
		private IInitialState $initialState,
		private \OCA\Decidiq\Service\PublicationConfigService $publicationConfig,
	) {
	}//end __construct()

	/**
	 * Get the settings form template.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
	 */
	public function getForm(): TemplateResponse {
		$version = $this->appManager->getAppVersion(appId: Application::APP_ID);
		$this->initialState->provideInitialState('version', $version);

		// @spec openspec/specs/public-publication/spec.md
		// Provide the publication configuration (per-body catalog/policy/attendance)
		// and policy enums to the admin settings page via IInitialState — rendered
		// by the NC settings framework, NOT added to the in-app vue-router.
		$this->initialState->provideInitialState('publicationConfig', $this->publicationConfig->getAll());
		$this->initialState->provideInitialState(
			'publicationPolicies',
			[
				'policies' => \OCA\Decidiq\Service\PublicationConfigService::POLICIES,
				'attendance' => \OCA\Decidiq\Service\PublicationConfigService::ATTENDANCE_POLICIES,
			]
		);

		// @spec openspec/specs/meeting-transcription/spec.md
		// Default per-body transcript/recording retention policy
		// (meeting-transcription-ai-minutes). Bodies inherit these defaults
		// until a chair/secretary overrides them on the body detail view; the
		// frontend reads them via loadState (NOT DOM data-attributes).
		$this->initialState->provideInitialState('transcriptRetentionDefaultPolicy', 'delete-both');
		$this->initialState->provideInitialState('transcriptRetentionDefaultDays', 30);

		return new TemplateResponse(
			Application::APP_ID,
			'settings/admin',
			[]
		);
	}//end getForm()

	/**
	 * Get the section ID this settings page belongs to.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
	 */
	public function getSection(): string {
		return Application::APP_ID;
	}//end getSection()

	/**
	 * Get the priority for ordering within the section.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.5
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
	 */
	public function getPriority(): int {
		return 10;
	}//end getPriority()

	/**
	 * Human-readable name of the delegated settings section.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @return string|null The section name, or null to use the section default.
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App config keys an authorized (delegated) admin may manage.
	 *
	 * Returned as a map of appId => list of allowed config keys. Decidiq
	 * exposes no delegatable sub-keys yet, so this is intentionally empty;
	 * the attribute still scopes the endpoint to full admins.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @return array<string,string[]> Map of appId to allowed config keys.
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
