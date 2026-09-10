<?php

/**
 * PHPUnit bootstrap for Decidiq unit tests.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader and register OCP namespace for standalone test runs.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Register the OpenRegister test-stub namespace at test time ONLY. These stubs are
// Doctrine placeholders, loaded BEFORE anything can mock an OCP DB interface.
// IQueryBuilder evaluates class constants referencing Doctrine\DBAL\ParameterType
// at parse time, and IDBConnection::getQueryBuilder() returns IQueryBuilder — so
// without these, createMock(IDBConnection::class) dies with
// `Class "Doctrine\DBAL\ParameterType" not found`, raised from inside
// createMock(), which reads as a broken test rather than a missing dependency.
// Only the two CONSTANT HOLDERS are stubbed: stubbing Doctrine\DBAL\Connection
// as well fatals a full-server run, because OC\DB\Connection extends it.
require_once __DIR__ . '/stubs/DoctrineStubs.php';

// deliberately NOT registered via composer autoload-dev: a dev-built vendor bakes
// autoload-dev into the runtime classmap, and OCA\OpenRegister\* stubs then shadow
// the REAL OpenRegister classes instance-wide (see openregister#2036 / hermiq#21).
// Registering here keeps them available for phpunit without ever entering the app's
// runtime autoloader. Loading stays lazy, so OCP is fully registered before any stub
// (which extends OCP\...\Event) is actually loaded.
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');

// Test-only helper classes that are not themselves tests, so PHPUnit's `*Test.php`
// suffix never loads them and something has to. `OCA\Decidiq\Tests\` is LONGER
// than composer's `OCA\Decidiq\` -> `lib/`, and PSR-4 is longest-prefix-wins, so
// this claims the test namespace without disturbing the app's own.
$autoloader->addPsr4('OCA\\Decidiq\\Tests\\', __DIR__ . '/');

// THE OpenRegister CONTRACT INTERFACES, OPTED INTO RATHER THAN AUTOLOADED.
//
// conduction/hydra-gates claims `OCA\OpenRegister\Contract\` as a RUNTIME psr-4
// prefix, so consumers get these interfaces implicitly. That prefix is LONGER
// than both openregister's own `OCA\OpenRegister\` -> `lib/` AND the stub root
// registered on the line above, and PSR-4 is longest-prefix-wins — so whichever
// app's autoloader registers first defines OpenRegister's contract for the whole
// process (ConductionNL/.github#531).
//
// Once that prefix is dropped, the stub root above resolves
// `...\Contract\ObjectServiceInterface` to tests/Stubs/Contract/, which this app
// does not ship. MEASURED without this block: 662 errors, every one
// "Class or interface OCA\OpenRegister\Contract\ObjectServiceInterface does not
// exist" from MockBuilder.
//
// interface_exists() is order-independent — it asks whether the interface is
// RESOLVABLE, not who registered first. Appending a fallback autoloader does NOT
// work: spl_autoload_register appends relative to registration order, and that
// order across independently loaded apps is exactly what nobody controls.
//
// `RegisterSlugResolution` is a CLASS, not an interface, so the guard has to ask
// both questions. Asking only interface_exists() would answer false for a class
// that is already loaded and then require its file a second time, and a
// duplicate declaration is a fatal, not a no-op.
//
// These two arrived in `conduction/hydra-gates` v1.18.0. v1.17.0 and earlier
// ship only the two Object* contracts, so on an older constraint the file is
// simply absent and the loop leaves the name undefined, exactly as it did
// before they existed.
foreach ([
	'ObjectEntityInterface',
	'ObjectServiceInterface',
	'RegisterSlugResolution',
	'RegisterSlugResolverInterface',
] as $contract) {
	$fqcn = '\\OCA\\OpenRegister\\Contract\\' . $contract;
	if (interface_exists($fqcn) === false && class_exists($fqcn) === false) {
		$shipped = __DIR__ . '/../vendor/conduction/hydra-gates/hydra-gates/contracts/' . $contract . '.php';
		if (file_exists($shipped) === true) {
			require_once $shipped;
		}
	}
}

// Register OCP\ and NCU\ namespaces.
// vendor/nextcloud/ocp/OCP is a symlink to the live NC server (/var/www/html/lib/public)
// that resolves on a deployed instance but is broken in the bare php:8.3-cli CI container.
// In CI we fall back to vendor/nextcloud/ocp/OCP.bak which holds the shipped stubs.
// This MUST happen before any class_exists() call that may trigger autoloading of
// stub classes that extend OCP\EventDispatcher\Event etc.
$ocpDir = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
$ocpBakDir = __DIR__ . '/../vendor/nextcloud/ocp/OCP.bak';
$ncuDir = __DIR__ . '/../vendor/nextcloud/ocp/NCU';
if (is_dir($ocpDir) === true) {
	$autoloader->addPsr4('OCP\\', $ocpDir . '/');
	$autoloader->addPsr4('NCU\\', $ncuDir . '/');
} elseif (is_dir($ocpBakDir) === true) {
	// CI environment — broken symlink, use the shipped backup stubs.
	$autoloader->addPsr4('OCP\\', $ocpBakDir . '/');
	$autoloader->addPsr4('NCU\\', $ncuDir . '/');
}

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * `lib/base.php` from a source tree that was never installed still declares
 * `OC` and builds `\OC::$server` before it throws "Not installed". That server
 * cannot be undone (`OC::$server` is a typed static), so from then on every
 * `\OC::$server->get()` in the code under test hits a container that knows
 * none of this app's registrations and autowires from scratch; constructor
 * cycles then recurse until memory runs out (19 GB on one openregister test,
 * 2026-09-08). So the decision has to be made BEFORE base.php is loaded, and
 * the only cheap signal is the `installed` flag in config/config.php.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true`.
 */
function decidiq_nc_root_is_installed(string $ncRoot): bool
{
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it in a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	return ($config['installed'] ?? false) === true;
}

// The Nextcloud root this checkout sits under (apps-extra/decidiq/), or null
// when there is none or it is only a bare source tree. Decided ONCE, up here,
// so that lib/base.php is never loaded from a tree that cannot finish booting.
$decidiqNcRoot = null;
$decidiqNcCandidate = dirname(__DIR__, 3);
if (is_file($decidiqNcCandidate . '/lib/base.php') === true) {
	if (decidiq_nc_root_is_installed($decidiqNcCandidate) === true) {
		$decidiqNcRoot = $decidiqNcCandidate;
	} else {
		fwrite(
			STDERR,
			sprintf(
				"[decidiq/tests/bootstrap-unit] Nextcloud tree at %s is not installed (config/config.php lacks installed => true); "
				. "skipping lib/base.php and running in pure-unit mode.\n",
				$decidiqNcCandidate
			)
		);
	}
}

// Bootstrap Nextcloud only when an INSTALLED instance is present. The old
// version caught whatever base.php threw and carried on "in standalone mode",
// which is exactly the half-booted state the helper above exists to prevent.
if ($decidiqNcRoot !== null) {
	try {
		include_once $decidiqNcRoot . '/lib/base.php';
	} catch (\Throwable $e) {
		// The tree IS installed, so the dangerous case this guard exists for
		// (loading a bare source tree) did not happen. base.php still failed
		// part-way.
		//
		// This does NOT abort. `OC::$server` is a typed static, so a half-built
		// container cannot be unset, and aborting was tried: it turned all six
		// PHPUnit legs red on a suite that passes (humaniq, 2026-09-08). The
		// runaway this guard exists for needs an autowiring lookup to reach the
		// poisoned container, this app has none in lib, and phpunit.xml's 2G cap
		// bounds one anyway.
		//
		// So: say plainly that the container is unreliable, and let the pure unit
		// tests run. A container-bound test failing loudly is the intended outcome.
		fwrite(
			STDERR,
			sprintf(
				"[decidiq/tests/bootstrap-unit] Nextcloud at %s could not finish booting (%s).\n"
				. "  \\OC::\$server now holds a HALF-BUILT container and cannot be unset. Pure unit tests\n"
				. "  continue; anything resolving a service from that container is UNVERIFIED by this run.\n",
				$decidiqNcRoot,
				$e->getMessage()
			)
		);
	}
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib) === true) {
	$loader = new \Composer\Autoload\ClassLoader();
	$loader->addPsr4('Test\\', $serverTestsLib);
	$loader->register(true);
}

// Load test stubs for cross-app classes not available as Composer dependencies
// (e.g. OCA\OpenRegister classes that are only present when the app is installed).
// The stubs are also registered via autoload-dev PSR-4 in composer.json so that
// Composer's autoloader can find them without needing Nextcloud to be bootstrapped.
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
	require_once __DIR__ . '/Stubs/Event/DeepLinkRegistrationEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectCreatedEvent::class) === false) {
	require_once __DIR__ . '/Stubs/Event/ObjectCreatedEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectCreatingEvent::class) === false) {
	require_once __DIR__ . '/Stubs/Event/ObjectCreatingEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectUpdatedEvent::class) === false) {
	require_once __DIR__ . '/Stubs/Event/ObjectUpdatedEvent.php';
}

// ObjectService, ObjectEntity, Register and Schema need no require_once: the
// PSR-4 root registered above resolves them to tests/Stubs/Service/ and
// tests/Stubs/Db/ whenever the real OpenRegister app is absent, and to the real
// app when it is present. The same is true of the ADR-066 leaf trio
// (Event\RegisterLeafProvidersEvent, Service\Integration\LeafDescriptor,
// Service\Integration\IntegrationProvider) — their paths under tests/Stubs/
// mirror their namespaces exactly, so adding a require_once branch for them
// would recreate the dead-guard shape #399 removed. There used to be four more require_once branches here
// pointing at a second, LOOSER copy of each stub; the guards could never be
// true, so the copies were dead while still reading as the contract (#399).
//
// CalendarEventService is different: PSR-4 would look for
// tests/Stubs/Service/CalendarEventService.php, which does not exist, so the
// class really is missing without OpenRegister and must be required explicitly.
if (class_exists(\OCA\OpenRegister\Service\CalendarEventService::class) === false) {
	require_once __DIR__ . '/Stubs/OpenRegisterServices.php';
}

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// This allows DecidiqToolProvider unit tests to run in standalone CI environments.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
	require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}
