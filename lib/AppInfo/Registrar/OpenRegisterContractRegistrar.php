<?php

/**
 * Decidiq OpenRegisterContractRegistrar
 *
 * Binds the OpenRegister contracts decidiq type-hints (ADR-084).
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
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds OpenRegister's published contracts to their implementations.
 *
 * ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
 * concrete class, so this app's unit tests can mock a type they are able to
 * load. Nextcloud autowires concrete classes across apps but not interfaces, so
 * each binding has to be stated.
 *
 * ALIASES, not factories: an alias resolves when something actually asks for the
 * interface, so an instance without OpenRegister fails at the route that needed
 * the data rather than at registration. Both names are strings and neither
 * triggers an autoload, which is what keeps ADR-083 rule 3's promise that the
 * start screen still boots. That matters more than it looks: apps register in
 * sorted order, so `decidiq` registers before `openregister` and the
 * `OCA\OpenRegister\` prefix is not autoloadable at this point even on a
 * perfectly healthy instance.
 *
 * These two lived inline in {@see \OCA\Decidiq\AppInfo\Application::register()}
 * until the second one was added. That class documents its own rule, that every
 * cohesive group of bindings belongs in a registrar, and phpmd enforced it:
 * adding one more contract took `register()` past its length threshold and the
 * class to a coupling of 14 against a limit of 14. Moving the pair here is the
 * fix the repository's own architecture already prescribed.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */
class OpenRegisterContractRegistrar {

	/**
	 * Register the contract aliases.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);

		// The register-slug resolver.
		//
		// Decidiq's register was renamed from `decidesk` by this app's own
		// `Repair\MigrateRegisterSlug`, which runs per instance. Both slugs are
		// therefore live across the estate at once, and NEITHER is safe written
		// as a literal: `decidesk` is wrong wherever the step has run, `decidiq`
		// wherever it has not. Reading with the wrong one returns zero rows
		// rather than an error, which is byte for byte what an empty register
		// returns.
		//
		// Verified against a leaf container rather than assumed. OpenRegister
		// registers the resolver in its OWN container, so nothing of that
		// registration reaches here; what does is this alias plus autowiring of
		// the concrete class, whose only dependencies are `RegisterMapper` and
		// `LoggerInterface`, both of which a leaf app's DIContainer resolves.
		// The one thing lost is OpenRegister's shared-instance registration, so
		// the request-scoped memo is per consumer rather than per request: one
		// indexed read per consumer, and the same answer.
		$context->registerServiceAlias(
			RegisterSlugResolverInterface::class,
			'OCA\OpenRegister\Service\RegisterSlugResolver'
		);
	}//end register()
}//end class
