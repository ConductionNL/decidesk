<?php

/**
 * PHPUnit bootstrap for Decidiq integration tests.
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

// Include Composer's autoloader.
// Also register OCP namespace from vendor for standalone runs (no Nextcloud server present).
$autoloader = require __DIR__ . '/../vendor/autoload.php';
// OpenRegister test stubs are registered here (test-time only), NOT via composer
// autoload-dev — a dev-built vendor would otherwise bake these OCA\OpenRegister\*
// stubs into the runtime classmap and shadow the real classes (openregister#2036).
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');
if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP') === true) {
	$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
	$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
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
				"[decidiq/tests/bootstrap] Nextcloud tree at %s is not installed (config/config.php lacks installed => true); "
				. "skipping lib/base.php and running in pure-unit mode.\n",
				$decidiqNcCandidate
			)
		);
	}
}

// Bootstrap Nextcloud only when an INSTALLED instance is present. The old
// gate was is_readable(config/config.php), which a 0-byte config file in a
// bare source tree passes, so base.php got loaded and threw "Not installed"
// after it had already built OC::$server.
if (defined('OC_CONSOLE') === false && $decidiqNcRoot !== null) {
	try {
		include_once $decidiqNcRoot . '/lib/base.php';

		// NC's own tests/autoload.php starts with `require_once ../lib/base.php`,
		// so it is only safe once base.php itself has succeeded.
		if (file_exists($decidiqNcRoot . '/tests/autoload.php') === true) {
			include_once $decidiqNcRoot . '/tests/autoload.php';
		}

		if (class_exists(\OC_App::class) === true) {
			\OC_App::loadApps();
			\OC_App::loadApp('decidiq');
		}

		if (class_exists(\OC_Hook::class) === true) {
			\OC_Hook::clear();
		}
	} catch (\Throwable $e) {
		// The root passed the installed check but base.php still failed
		// (unreachable database, broken app, ...). There is no way back to
		// pure-unit mode from here: `OC::$server` is a typed static that
		// already holds a half-built container, and every `\OC::$server->get()`
		// in the code under test would autowire from scratch until memory
		// runs out. Stop the run and say what to do instead.
		fwrite(
			STDERR,
			sprintf(
				"[decidiq/tests/bootstrap] Nextcloud root at %s could not be initialised (%s).\n"
				. "  A half-booted server cannot be undone, so the run stops here rather than pretending to be pure-unit.\n"
				. "  Fix the instance, or run the suite from a checkout that is not under a Nextcloud root.\n",
				$decidiqNcRoot,
				$e->getMessage()
			)
		);
		exit(1);
	}
}

// Load test stubs AFTER Nextcloud bootstrap so that OCP\EventDispatcher\Event
// (which the stub extends) is already resolvable — either via the Nextcloud
// autoloader (full NC environment) or via the vendor/nextcloud/ocp fallback
// registered above (standalone mode).
// The stubs are also registered via autoload-dev PSR-4 in composer.json so that
// Composer's autoloader can find them without needing Nextcloud to be bootstrapped.
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
	include_once __DIR__ . '/Stubs/Event/DeepLinkRegistrationEvent.php';
}

// ObjectService, ObjectEntity, Register and Schema need no include_once: the
// PSR-4 root registered above resolves them to tests/Stubs/Service/ and
// tests/Stubs/Db/ whenever the real OpenRegister app is absent, and to the real
// app when it is present. See tests/Stubs/Service/ObjectService.php for the
// signature-parity contract these stubs are held to (#399).
if (class_exists(\OCA\OpenRegister\Service\CalendarEventService::class) === false) {
	include_once __DIR__ . '/Stubs/OpenRegisterServices.php';
}
