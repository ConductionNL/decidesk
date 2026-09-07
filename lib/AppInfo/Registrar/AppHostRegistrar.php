<?php

/**
 * Decidiq AppHost Registrar
 *
 * AppHost boilerplate adoption (ADR-040 / ADR-022): re-points the mechanical,
 * fleet-standard plumbing at the OpenRegister AppHost generics — keeping
 * decidiq's existing URLs unchanged — while leaving every domain-entangled
 * class bespoke.
 *
 * Extracted from {@see \OCA\Decidiq\AppInfo\Application} so the bootstrap
 * class stops accumulating a class reference for every registration it makes
 * (PHPMD CouplingBetweenObjects); the imports move with the registrations.
 *
 * @category AppInfo
 * @package  OCA\Decidiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2
 * @spec openspec/specs/apphost-adoption/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\AppInfo\OpenRegisterAutoloader;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the AppHost-generic plumbing decidiq adopts.
 *
 * Deliberately NOT adopted (kept bespoke — domain behaviour the generics
 * cannot express, per the "don't force" rule):
 *   - `Controller\SettingsController` + `Service\SettingsService`
 *     (`decidesk` register import — the register SLUG is frozen across the
 *     app-id rename, see appinfo/info.xml — plus publication-config CRUD).
 *   - `Settings\AdminSettings` (domain initial state: publication config,
 *     transcript-retention defaults) and `Sections\SettingsSection`,
 *     `Settings\PersonalSettings`, `Sections\PersonalSection`.
 *   - `Repair\InitializeSettings` (voter_token_secret seeding + OR
 *     configuration import).
 *   - `Controller\PreferencesController` — the AppHost has no
 *     `GenericPreferencesController` in OpenRegister development, so the
 *     bespoke per-user preferences controller is retained as-is.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class AppHostRegistrar {

	/**
	 * FQCN of the AppHost's manifest-driven deep-link registration listener.
	 *
	 * Referenced as a string, never imported: the class only exists when
	 * openregister is installed.
	 *
	 * @var string
	 */
	private const DEEP_LINK_LISTENER = 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener';

	/**
	 * Register the adopted AppHost boilerplate.
	 *
	 * The dashboard / metrics / health route targets are bespoke decidiq
	 * controllers (`Controller\DashboardController`,
	 * `Controller\MetricsController`, `Controller\HealthController`) that adopt
	 * the AppHost by COMPOSITION, not inheritance — concrete classes so the
	 * route targets stay reachable (gate-5 / gate-14), and free of any
	 * `extends`/import of an OpenRegister class so they still reflect when
	 * openregister is absent. They pull the engine's ManifestLoader /
	 * MetricsEngine / HealthCheckExecutor out of the container by FQCN string
	 * at dispatch time, so no explicit binding is needed here.
	 *
	 * ⚠️ Do NOT "simplify" those three back into subclasses of the AppHost
	 * generics. Nextcloud's router `ReflectionClass()`es every file in
	 * `lib/Controller/` while MATCHING a route, so an unresolvable parent
	 * returns HTTP 500 for EVERY route in decidiq, and `extends` is resolved
	 * by the autoloader — lazy DI cannot rescue it. See decidiq#377.
	 *
	 * Lazy by construction: the binding is a `registerService` closure, so a
	 * disabled OpenRegister never loads an AppHost class at bootstrap (the
	 * closure only resolves when a route is dispatched), matching the AppHost
	 * fatal-free invariant.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Generic, manifest-driven deep-link listener replaces the former
		// hand-written listener. Patterns now live in the manifest `deepLinks`
		// block. Fires only when OpenRegister dispatches the event.
		$context->registerService(
			self::DEEP_LINK_LISTENER,
			static function ($c): object {
				$class = self::DEEP_LINK_LISTENER;
				return new $class(
					appId: Application::APP_ID,
					appManager: $c->get(IAppManager::class),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// The listener is an OpenRegister AppHost class that only exists when
		// openregister is installed, so PHPStan cannot verify it is a
		// class-string<IEventListener>. It is registered as a service just above
		// and is only instantiated when OpenRegister dispatches the event.
		$listenerClass = self::DEEP_LINK_LISTENER;
		// @phpstan-ignore-next-line
		$context->registerEventListener(event: DeepLinkRegistrationEvent::class, listener: $listenerClass);

		$this->bindStoreController(context: $context);

	}//end register()

	/**
	 * Bind the store controller the adopted route table already declares.
	 *
	 * 🔴 THIS ROUTE ARRIVES WHETHER THE APP WANTS IT OR NOT.
	 *
	 * `Routes::standard()`, which appinfo/routes.php adopts, declares
	 * `/api/store/items`. The binding normally comes from
	 * `Bootstrap::register()`, and decidiq deliberately does not call that: it
	 * keeps its own Settings, Preferences, AdminSettings and repair classes.
	 * So the route matched a controller class that does not exist, and every
	 * request to it returned HTTP 500 rather than 404. Measured on a running
	 * instance 2026-09-03, alongside filinq and planninq.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCA\OpenRegister\AppHost\Bootstrap
	 * is a cross-app static entry point in a SIBLING app that may be absent or
	 * unloadable here — the call is guarded by class_exists() and wrapped in a
	 * catch(\Throwable) for exactly that reason. It cannot be injected: this
	 * runs at the composition root, so there is no container to resolve an
	 * adapter from. OpenRegisterAutoloader::register() is static for the same
	 * reason.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 *
	 * 🔴 `$context` IS USED. psalm used to say otherwise, and the error it
	 * printed named the wrong thing.
	 *
	 * It is passed to `Bootstrap::aliasStoreController(context: $context, …)`
	 * below. `OCA\OpenRegister\AppHost\Bootstrap` lives in a SIBLING app that
	 * may be absent, so psalm could not resolve it, pruned the guarded block as
	 * dead code, and then reported
	 * `UnusedParam: Param context is never referenced in this method`. Read
	 * literally that invites you to delete a parameter the store-route binding
	 * needs at runtime.
	 *
	 * There is no `@psalm-suppress UnusedParam` here any more. psalm now reads
	 * the class from a shared analysis stub,
	 * `vendor/conduction/hydra-gates/hydra-gates/stubs/openregister-apphost-bootstrap.stub.php`,
	 * named in psalm.xml. The suppression worked, but it would also have hidden
	 * the next genuinely unused parameter in this method; the stub answers the
	 * question psalm is asking and leaves the call type-checked.
	 *
	 * Shared rather than vendored here, because filinq and planninq adopted the
	 * same route table and write the same call.
	 */
	private function bindStoreController(IRegistrationContext $context): void {
		// ⚠️ THE PRELUDE IS NOT OPTIONAL HERE. `decidiq` sorts before
		// `openregister`, and Nextcloud registers apps one at a time in sorted
		// order, so OCA\OpenRegister\ is NOT on the autoloader yet. Without
		// this the class_exists() below answers false on a perfectly healthy
		// instance and the binding is skipped in silence.
		OpenRegisterAutoloader::register();

		// The class_exists() guard MUST stay in this method: it is also the
		// assertion psalm relies on to accept the Bootstrap call below, and
		// psalm does not carry that narrowing across a call.
		if (class_exists(Bootstrap::class) === true) {
			try {
				Bootstrap::aliasStoreController(
					context: $context,
					appId: Application::APP_ID,
					controllerNs: 'OCA\\Decidiq\\Controller'
				);
			} catch (\Throwable) {
				// An OpenRegister older than the helper, or present but
				// unloadable. The store route is then no worse off than it is
				// today, and every registration around this one still runs.
			}
		}

	}//end bindStoreController()
}//end class
