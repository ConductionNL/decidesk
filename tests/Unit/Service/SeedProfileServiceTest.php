<?php

namespace Unit\Service;

use OCA\Decidiq\Service\DemoDataService;
use OCA\Decidiq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The example sets an operator picks in the setup wizard.
 *
 * These run against the REAL descriptors in `lib/Settings/profiles`, not a
 * fixture. The point of the change is that the shipped files are the source of
 * truth, so a test over invented ones would assert nothing about what installs.
 */
class SeedProfileServiceTest extends TestCase {
	private IAppManager $appManager;
	private DemoDataService $demoData;
	private SeedProfileService $service;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$this->demoData = $this->createMock(DemoDataService::class);

		$this->service = new SeedProfileService(
			$this->appManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->demoData
		);
	}

	public function testEveryShippedSetIsOffered(): void {
		$this->demoData->method('isAvailable')->willReturn(false);

		$ids = array_column($this->service->listProfiles(), 'id');

		// Read from disk rather than a list in code, so a set that ships without
		// being offered is impossible by construction.
		$this->assertSame(['municipality', 'association', 'corporate', 'works-council'], $ids);
	}

	public function testSetsAreOfferedInTheirDeclaredOrder(): void {
		$this->demoData->method('isAvailable')->willReturn(false);

		$profiles = $this->service->listProfiles();

		$this->assertSame('municipality', $profiles[0]['id']);
		// `order` is an implementation detail of the file, not of the offer.
		$this->assertArrayNotHasKey('order', $profiles[0]);
		$this->assertGreaterThan(0, $profiles[0]['objectCount']);
		$this->assertNotSame('', $profiles[0]['label']);
	}

	public function testTheGeneratedSetIsOfferedOnlyWhenItShips(): void {
		// ADR-111 rule 2 keeps the schema-generated dataset on offer, but only
		// when the descriptor is actually on disk: offering an import that
		// cannot run is worse than not offering it.
		$this->demoData->method('isAvailable')->willReturn(true);
		$this->assertContains('generated', array_column($this->service->listProfiles(), 'id'));

		$absent = new SeedProfileService(
			$this->appManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->stubDemoData(available: false)
		);
		$this->assertNotContains('generated', array_column($absent->listProfiles(), 'id'));
	}

	public function testAnUnknownSetIsNotKnown(): void {
		$this->demoData->method('isAvailable')->willReturn(false);

		$this->assertTrue($this->service->isKnown('municipality'));
		$this->assertFalse($this->service->isKnown('atlantis'));
	}

	public function testInstallingAnUnknownSetThrowsRatherThanReturningNothing(): void {
		$this->demoData->method('isAvailable')->willReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No example set is called "atlantis".');

		$this->service->install('atlantis');
	}

	public function testASetIdCannotEscapeTheProfileDirectory(): void {
		// 🔴 THE POINT OF THIS TEST. The id arrives from an HTTP request. If the
		// path were built by concatenating it, `../../config/config` would name a
		// readable file, so resolution goes through the descriptors that were
		// actually read and matched on their declared id.
		$this->demoData->method('isAvailable')->willReturn(false);

		foreach (['../../config/config', '../decidesk_register', 'municipality/../../../etc/passwd'] as $hostile) {
			$this->assertFalse($this->service->isKnown($hostile), $hostile . ' must not resolve');

			try {
				$this->service->install($hostile);
				$this->fail('Installing ' . $hostile . ' must throw');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('No example set is called', $e->getMessage());
			}
		}
	}

	public function testTheWizardReadsTheOfferedSetsFromTheServer(): void {
		// 🔴 THE MANIFEST USED TO HOLD A SECOND COPY OF THE LIST, AND A SECOND
		// COPY CAN DRIFT: adding a set without listing it shipped a set nobody
		// could pick, and removing one left an option whose import failed at the
		// next step. The step now names WHERE to read the list instead of
		// restating it, so this test guards that the manifest keeps no options
		// of its own rather than guarding that two lists still agree.
		$manifest = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/src/manifest.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$step = null;
		foreach (($manifest['setup']['steps'] ?? []) as $candidate) {
			if (($candidate['id'] ?? '') === 'example-set') {
				$step = $candidate;
			}
		}

		$this->assertNotNull($step, 'The wizard must ask which example set to load');
		$this->assertSame('choice', $step['type']);
		$this->assertSame('example_profile', $step['configKey']);
		$this->assertSame('cards', $step['display'], 'The sets need their descriptions, and a dropdown has nowhere to put them');
		$this->assertSame('profiles', $step['optionsSource']);
		$this->assertTrue($step['multiple'], 'An operator may load more than one set');
		$this->assertArrayNotHasKey('options', $step, 'A static list is exactly the copy that drifts');
	}

	public function testTheOfferedListLeadsWithDecliningAndThenTheShippedSets(): void {
		$this->demoData->method('isAvailable')->willReturn(true);

		$choices = $this->service->listChoices();

		$this->assertSame(
			['none', 'municipality', 'association', 'corporate', 'works-council', 'generated'],
			array_column($choices, 'id')
		);

		// Every card has to be able to say what it is, so every entry carries the
		// three things the card renders.
		foreach ($choices as $choice) {
			$this->assertNotSame('', $choice['label'], $choice['id'] . ' needs a label');
			$this->assertNotSame('', $choice['description'], $choice['id'] . ' needs a description');
			$this->assertNotSame('', $choice['icon'], $choice['id'] . ' needs an icon');
		}
	}

	public function testDecliningIsOfferedButIsNotAnImportableSet(): void {
		// 🔴 `none` IS AN ANSWER, NOT A DESCRIPTOR. It has to be on offer and it
		// must never resolve to something the importer will try to read.
		$this->demoData->method('isAvailable')->willReturn(false);

		$this->assertContains('none', array_column($this->service->listChoices(), 'id'));
		$this->assertNotContains('none', array_column($this->service->listProfiles(), 'id'));
		$this->assertFalse($this->service->isKnown('none'));
	}

	public function testEveryShippedSetCarriesAnIconTheLibraryCanResolve(): void {
		// An unresolvable name renders a help-circle, which reads as "unknown
		// option" on a card that is meant to be recognisable at a glance.
		$this->demoData->method('isAvailable')->willReturn(false);

		foreach ($this->service->listProfiles() as $profile) {
			$this->assertMatchesRegularExpression(
				'/^[A-Z][A-Za-z]+$/',
				$profile['icon'],
				$profile['id'] . ' must name a PascalCase MDI icon'
			);
		}
	}

	public function testNoSeedIsReferencedByASlugTooLongToStore(): void {
		// 🔴 A 37-CHARACTER SLUG SILENTLY DROPPED AN OBJECT.
		//
		// OpenRegister types a relation column as varchar(36), the width of a
		// UUID. A seed that points at another object BY SLUG therefore fails to
		// insert when that slug is 37 characters or more, and the importer skips
		// the row with a warning nobody reads:
		//
		//   Skipping seed object for 'termijnagenda-item' - import failed:
		//   SQLSTATE[22001] value too long for type character varying(36)
		//
		// Measured on a fresh instance: the municipality set reported "Imported
		// 199 example object(s)" and 198 landed, because
		// `lta-herziening-parkeerbeleid` referenced
		// `goal-amsterdam-parkeerbeleid-kwartaal` (37). The count comes from the
		// FILE, so the message could not tell the operator anything was wrong.
		//
		// The rule is about being REFERENCED, not about being long: `_slug`
		// itself is a wider column, and 20 other seeds carry slugs past 36
		// characters with no trouble because nothing points at them.
		$objects = [];
		foreach (glob(dirname(__DIR__, 3) . '/lib/Settings/profiles/*.json') as $file) {
			$data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach (($data['x-openregister']['seedData']['objects'] ?? []) as $schema => $seeds) {
				foreach ($seeds as $seed) {
					$objects[] = [$schema, $seed];
				}
			}
		}

		$slugs = [];
		foreach ($objects as [$schema, $seed]) {
			$slugs[(string)($seed['slug'] ?? '')] = true;
		}

		$offenders = [];
		foreach ($objects as [$schema, $seed]) {
			$ownSlug = (string)($seed['slug'] ?? '');
			foreach ($this->everyString($seed) as $value) {
				if ($value !== $ownSlug && isset($slugs[$value]) === true && strlen($value) > 36) {
					$offenders[] = $schema . '/' . $ownSlug . ' -> ' . $value . ' (' . strlen($value) . ' chars)';
				}
			}
		}

		$this->assertSame(
			[],
			array_values(array_unique($offenders)),
			'A seed referenced by another seed must have a slug of at most 36 characters, '
			. 'or OpenRegister cannot store the reference and drops the referring object.'
		);
	}

	/** Every string anywhere in a seed, however deeply nested. */
	private function everyString(array $seed): array {
		$found = [];
		array_walk_recursive(
			$seed,
			static function ($value) use (&$found): void {
				if (is_string($value) === true) {
					$found[] = $value;
				}
			}
		);

		return $found;
	}

	public function testTheCiSeedScriptSettlesTheStepByARouteTheControllerAnswers(): void {
		// 🔴 A RENAMED ROUTE BREAKS THE WHOLE E2E SUITE, SILENTLY.
		//
		// `tests/e2e/ci-seed.sh` settles the optional setup step before the suite
		// runs, because CnAppRoot opens the wizard as a full modal mask in every
		// fresh browser context while an optional step is outstanding. A rename
		// therefore does not fail the seed step; it fails every later spec on
		// `<ol class="cn-wizard-dialog__progress">` intercepting the click — a
		// message that accuses the selectors.
		//
		// The script has settled the step two different ways, and both are
		// legitimate, so this asserts the AGREEMENT rather than one spelling:
		//   - POST api/setup/action/<id>, which the controller must handle;
		//   - POST api/setup/config with example_profile, which closes BOTH steps
		//     and is what it does today.
		// Either way, whatever the script names must exist in the controller.
		$script     = (string)file_get_contents(dirname(__DIR__, 3) . '/tests/e2e/ci-seed.sh');
		$controller = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/SetupController.php');

		$this->assertMatchesRegularExpression(
			'#api/setup/(action/|config)#',
			$script,
			'ci-seed.sh must still settle the setup step, or the wizard masks every click'
		);

		// Route A: any posted action id must be one the controller answers.
		preg_match_all('#api/setup/action/([a-z0-9-]+)#', $script, $matches);
		foreach (array_unique($matches[1]) as $actionId) {
			$this->assertStringContainsString(
				"'" . $actionId . "'",
				$controller,
				'ci-seed.sh posts setup action "' . $actionId . '", which SetupController does not handle'
			);
		}

		// Route B: settling through setup/config means sending the profile key,
		// and the controller must read that exact key. Scoped to the key rather
		// than to every JSON key in the file: ci-seed.sh also parses unrelated
		// responses, so a blanket sweep picks up "error", "success", "provider"
		// and asserts things about the wrong payload.
		if (str_contains($script, 'api/setup/config') === true) {
			// Bind the payload to ITS OWN url. Matching the first --data in the
			// file picks up an unrelated empty '{}' from an earlier curl, and then
			// the loop below has nothing to check and the test passes vacuously.
			preg_match(
				'#--data\s+\x27(\{[^\x27]*\})\x27[^\x27]{0,400}?api/setup/config#s',
				$script,
				$payload
			);
			$this->assertNotEmpty(
				$payload,
				'ci-seed.sh posts to setup/config but sends no --data payload'
			);

			preg_match_all('#"([a-z_]+)"\s*:#', $payload[1], $keys);
			$sent = array_unique($keys[1]);
			$this->assertNotEmpty($sent, 'the setup/config payload carries no key');

			foreach ($sent as $key) {
				$this->assertStringContainsString(
					"'" . $key . "'",
					$controller,
					'ci-seed.sh posts setup config key "' . $key . '", which SetupController does not read'
				);
			}
		}
	}

	private function stubDemoData(bool $available): DemoDataService {
		$stub = $this->createMock(DemoDataService::class);
		$stub->method('isAvailable')->willReturn($available);

		return $stub;
	}
}
