<?php

/**
 * Decidiq Domain Service Registrar
 *
 * The decidiq-specific container bindings that the autowiring container
 * cannot infer from a constructor signature: the delegated-decision event
 * listener, the MCP tool-provider alias, the eIDAS QES implementation
 * resolver, and the dormant default translation adapter.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\Decidiq\Mcp\DecidiqToolProvider;
use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\Decidiq\Service\IEIDASSignatureService;
use OCA\Decidiq\Service\ITranslationAdapter;
use OCA\Decidiq\Service\LogEIDASSignatureService;
use OCA\Decidiq\Service\LogTranslationAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Decidiq domain bindings the container cannot infer.
 *
 * Decidiq's services, controllers and background jobs are otherwise resolved
 * by Nextcloud's autowiring container. `SimpleContainer::query()` resolves an
 * unregistered class through `resolve()` -> `buildClass()`, which injects each
 * constructor parameter by its declared type, and then caches the built
 * instance by registering it as a service. An explicit `registerService()`
 * closure that repeats `<param>: $c->get(<declared type>::class)` for every
 * parameter is therefore exact boilerplate: it produces the same object graph
 * with the same per-container sharing. Only bindings that cannot be derived
 * from the constructor signature are declared here, namely: interface ->
 * implementation bindings, named service aliases, event listeners, and
 * factories that pass a value the container cannot infer.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md
 */
class DomainServiceRegistrar {
	/**
	 * Register the decidiq domain bindings.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-decision-events/specs/decidesk-decision-events/spec.md
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerMcpToolProvider(context: $context);
		$this->registerEidasBindings(context: $context);
		$this->registerTranslationAdapter(context: $context);

	}//end register()

	/**
	 * Register DecidiqToolProvider as the MCP tool provider for the AI Chat Companion.
	 *
	 * The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::decidiq' is the
	 * format that OR's McpToolsService enumerates to discover per-app providers
	 * (design D3). The interface ships in openregister PR #1466
	 * (ai-chat-companion-orchestrator).
	 *
	 * The app-id suffix MUST track this app's `<id>`: OpenRegister builds the
	 * lookup key as `'…\IMcpToolProvider::' . $appId` over the enumerated
	 * installed apps (see openregister AppHost\Bootstrap and AppInfo\Application),
	 * so a stale suffix here is not a cosmetic miss — the provider is simply
	 * never discovered and all five tools disappear without an error.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function registerMcpToolProvider(IRegistrationContext $context): void {
		$context->registerServiceAlias(
			'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidiq',
			DecidiqToolProvider::class
		);

	}//end registerMcpToolProvider()

	/**
	 * Phase 4 — eIDAS QES integration bindings.
	 *
	 * The IEIDASSignatureService binding picks the dormant
	 * {@see LogEIDASSignatureService} fallback when openconnector is absent or
	 * its `eidas-qes` Source is not configured; otherwise the
	 * openconnector-delegating {@see EIDASSignatureService} is used.
	 *
	 * Both implementations are individually constructable so tests / DI
	 * overrides can pick either side without going through the resolver; both
	 * are autowired from their constructor signatures.
	 *
	 * QesGuard and EIDASSignatureController take IEIDASSignatureService by its
	 * interface type, so the resolver below is what the container injects into
	 * them; both are autowired. GovernanceScopeGuard consumes the
	 * OpenRegister-projected per-body signatory/chair scopes
	 * (consume-or-rbac-authorization); it replaces the retired app-local
	 * MinutesAuthorizationService, and is autowired.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
	 */
	private function registerEidasBindings(IRegistrationContext $context): void {
		// Resolve the IEIDASSignatureService interface at request time. If
		// openconnector's CallService binding is registered, prefer the
		// delegating implementation; otherwise the dormant LogEIDASSignatureService.
		$context->registerService(
			IEIDASSignatureService::class,
			static function ($c): IEIDASSignatureService {
				$hasOpenconnector = false;
				try {
					$c->get('OCA\\OpenConnector\\Service\\CallService');
					$hasOpenconnector = true;
				} catch (\Throwable $e) {
					$hasOpenconnector = false;
				}

				if ($hasOpenconnector === true) {
					return $c->get(EIDASSignatureService::class);
				}

				return $c->get(LogEIDASSignatureService::class);
			}
		);

	}//end registerEidasBindings()

	/**
	 * Phase 6 — the one binding the container cannot infer: the dormant default
	 * translation adapter.
	 *
	 * Rebind in production to delegate to openconnector's translation source
	 * service. Phase 5 (proxy votes, written resolutions, governance reporting)
	 * and the rest of Phase 6 (regulator export, multilingual reconciliation,
	 * board self-evaluation) are autowired from their constructor signatures.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
	 */
	private function registerTranslationAdapter(IRegistrationContext $context): void {
		$context->registerService(
			ITranslationAdapter::class,
			static function ($c): ITranslationAdapter {
				return new LogTranslationAdapter(
					container: $c->get(ContainerInterface::class),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

	}//end registerTranslationAdapter()
}//end class
