<?php

/**
 * Decidiq Nextcloud Dashboard Widget
 *
 * Registers a "Decidiq" widget on the Nextcloud main dashboard (the Hub) via
 * the platform OCP\Dashboard\IWidget API. Shows the current user's pending
 * votes count and their next upcoming meeting, deep-linking back into the
 * Decidiq app. Per-user (session-scoped, no IDOR) and fail-soft.
 *
 * @category Dashboard
 * @package  OCA\Decidiq\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Dashboard;

use DateTimeImmutable;
use OCA\Decidiq\Service\DashboardWidgetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * The Decidiq Hub dashboard widget.
 *
 * Implements the base {@see \OCP\Dashboard\IWidget} contract plus
 * {@see \OCP\Dashboard\IIconWidget} (icon url), {@see \OCP\Dashboard\IButtonWidget}
 * (an "Open Decidiq" deep-link button) and {@see \OCP\Dashboard\IAPIWidgetV2}
 * (the NC32 pure-backend item path — no JS bundle required). Item data is
 * resolved per-user by {@see DashboardWidgetService}.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class DecidiqDashboardWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n App-scoped translation
	 * @param IURLGenerator $urlGenerator URL generator (deep links)
	 * @param ITimeFactory $timeFactory Clock source
	 * @param DashboardWidgetService $widgetService Per-user summary resolver
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly ITimeFactory $timeFactory,
		private readonly DashboardWidgetService $widgetService,
	) {
	}//end __construct()

	/**
	 * Unique widget id.
	 *
	 * FROZEN at the pre-rename value `decidesk`. Nextcloud's dashboard app
	 * persists each user's enabled + ordered widget ids as a per-user
	 * preference in the `dashboard` app's OWN namespace, which neither
	 * MigrateAppConfigKeys nor MigrateUserPreferences can reach — they only
	 * migrate rows this app owns. Change this id and every user who had the
	 * widget on their dashboard silently loses it, with no error anywhere.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return string The widget id
	 */
	public function getId(): string {
		return 'decidesk';
	}//end getId()

	/**
	 * Human-readable widget title.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return string The translated title
	 */
	public function getTitle(): string {
		return $this->l10n->t('Decidiq');
	}//end getTitle()

	/**
	 * Sort order among dashboard widgets.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return int The order weight
	 */
	public function getOrder(): int {
		return 20;
	}//end getOrder()

	/**
	 * CSS icon class (rendered before the icon url loads).
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return string The icon class
	 */
	public function getIconClass(): string {
		return 'icon-decidiq';
	}//end getIconClass()

	/**
	 * Absolute icon url for the widget header.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return string The icon url
	 */
	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('decidiq', 'app-dark.svg')
		);

	}//end getIconUrl()

	/**
	 * Deep link opened when the widget header is clicked.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return string|null The Decidiq app url
	 */
	public function getUrl(): ?string {
		return $this->appUrl();
	}//end getUrl()

	/**
	 * No server-side asset loading is required for this widget.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return void
	 */
	public function load(): void {
		// The IAPIWidgetV2 path renders items server-side; no JS/CSS to load.
	}//end load()

	/**
	 * Header buttons — a single "Open Decidiq" deep-link button.
	 *
	 * @param string $userId Current Nextcloud user id (unused — link is static)
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @return list<WidgetButton> The widget buttons
	 */
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->appUrl(),
				$this->l10n->t('Open Decidiq')
			),
		];

	}//end getWidgetButtons()

	/**
	 * Per-user widget items: pending votes count + next upcoming meeting.
	 *
	 * Fail-soft: the underlying service never throws, so a broken or absent
	 * register yields an empty item set with an empty-content message.
	 *
	 * @param string $userId Current Nextcloud user id (platform-resolved)
	 * @param string|null $since Pagination cursor (unused — fixed summary)
	 * @param int $limit Max items (unused — at most two summary items)
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @return WidgetItems The widget items
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$summary = $this->widgetService->getUserSummary(
			userId: $userId,
			now: $this->timeFactory->getTime()
		);

		$appUrl = $this->appUrl();
		$iconUrl = $this->getIconUrl();
		$items = [];

		$pending = $summary['pendingVotes'];
		$items[] = new WidgetItem(
			$this->l10n->t('Pending votes: %s', [(string)$pending]),
			$this->l10n->t('Decisions awaiting your vote'),
			$appUrl,
			$iconUrl,
			'decidiq-pending-votes'
		);

		$items[] = $this->buildNextMeetingItem(
			nextMeeting: ($summary['nextMeeting'] ?? null),
			appUrl: $appUrl,
			iconUrl: $iconUrl
		);

		return new WidgetItems(
			$items,
			$this->l10n->t('You are all caught up'),
		);

	}//end getItemsV2()

	/**
	 * Build the next-meeting widget item, or its "nothing scheduled" placeholder.
	 *
	 * @param mixed $nextMeeting The summary's nextMeeting entry (a meeting array, or null/absent)
	 * @param string $appUrl Deep link to the Decidiq app root
	 * @param string $iconUrl Widget icon url
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return WidgetItem The next-meeting item
	 */
	private function buildNextMeetingItem(mixed $nextMeeting, string $appUrl, string $iconUrl): WidgetItem {
		if (is_array($nextMeeting) === false) {
			return new WidgetItem(
				$this->l10n->t('No upcoming meetings'),
				'',
				$appUrl,
				$iconUrl,
				'decidiq-next-meeting'
			);
		}

		return new WidgetItem(
			(string)($nextMeeting['title'] ?? ($nextMeeting['name'] ?? $this->l10n->t('Next meeting'))),
			$this->formatMeetingSubtitle(scheduledDate: (string)($nextMeeting['scheduledDate'] ?? '')),
			$appUrl,
			$iconUrl,
			'decidiq-next-meeting'
		);

	}//end buildNextMeetingItem()

	/**
	 * Absolute url to the Decidiq app root (in-app dashboard).
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return string The deep-link url
	 */
	private function appUrl(): string {
		try {
			return $this->urlGenerator->linkToRouteAbsolute('decidiq.dashboard.page');
		} catch (\Throwable) {
			return $this->urlGenerator->getAbsoluteURL('/apps/decidiq/');
		}

	}//end appUrl()

	/**
	 * Format the next-meeting subtitle from its scheduledDate.
	 *
	 * @param string $scheduledDate ISO-8601 scheduled date
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @return string The subtitle (formatted date, or a fallback label)
	 */
	private function formatMeetingSubtitle(string $scheduledDate): string {
		if ($scheduledDate === '') {
			return $this->l10n->t('Your next meeting');
		}

		try {
			$date = new DateTimeImmutable($scheduledDate);
		} catch (\Throwable) {
			return $this->l10n->t('Your next meeting');
		}

		return $date->format('Y-m-d H:i');
	}//end formatMeetingSubtitle()
}//end class
