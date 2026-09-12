<?php

/**
 * Decidiq RegisterSlugPinTest
 *
 * No code under lib/ pins a superseded register slug.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The case that has never once been caught.
 *
 * A consumer pinned to a superseded register slug on a MIGRATED instance does
 * not raise. `openregister_registers` has no row with that slug, so the read
 * matches nothing, which is byte for byte what a healthy empty register returns.
 * No exception, no 404, no log line. This defect has no behaviour to watch: it
 * is a feature that quietly stops happening.
 *
 * So the guard is static, and it is repo-wide rather than diff-scoped. Diff
 * scope is right for debt a PR could reasonably be asked to carry; it is wrong
 * here, because every one of these references was written BEFORE the rename and
 * will therefore never appear in a diff. A diff-scoped version of this test
 * passes on a repository full of the defect.
 *
 * ## The positional shape, which is why this guard has a fifth pattern
 *
 * `ObjectServiceInterface::find()` takes `$register` as its FOURTH POSITIONAL
 * parameter. openregister's own copy of this guard carries four patterns and all
 * four look for a `register:` label, a `'register' =>` key or a
 * `setRegister(...)` call. None of them can see
 *
 *     $this->objectService->find($id, [], false, 'decidesk', 'participant');
 *
 * which is exactly how this repository's one pin was written, in
 * `VotingBehaviourController::ownsParticipant()`, an AUTHORIZATION check. The
 * fleet sweep that found ten pins across four apps ran openregister's four
 * patterns and reported decidiq as clean. See ConductionNL/openregister#3579.
 *
 * The positional pattern below is deliberately anchored to the three
 * ObjectService methods that take a register in that position, rather than to
 * any call with a string in its fourth argument, which would flag most of the
 * codebase.
 *
 * ## What it still does NOT catch
 *
 * It reads lines, not data flow. A slug arriving from app config, from a
 * manifest, or through more than one assignment is invisible to it, as is a
 * `match` arm built at run time. That is why
 * {@see \OCA\Decidiq\Tests\Unit\Controller\RegisterSlugResolutionTest} exists
 * beside it: this guard stops the literal being TYPED, and that one stops the
 * resolved slug being IGNORED.
 */
class RegisterSlugPinTest extends TestCase {

	/**
	 * Superseded register slug => the canonical slug replacing it.
	 *
	 * Only the register this app reads. openregister owns the full fleet map in
	 * `lib/Support/RegisterSlugAliases.php`, which is not published to
	 * consumers, and copying all ten here would put a second copy of that truth
	 * in a repository that does not own it.
	 *
	 * @var array<string, string>
	 */
	private const SUPERSEDED = ['decidesk' => 'decidiq'];

	/**
	 * Files allowed to name a superseded slug, and why.
	 *
	 * Each must be a file that exists in order to name the old slug, not a
	 * deferral. `MigrateRegisterSlug` IS the rename, and the three app-id
	 * migrations name the old APP ID, whose source of truth is `IAppManager`
	 * rather than `openregister_registers`. Those are a different question: the
	 * app id and the register slug are moved by separate repair steps and either
	 * can run first, so one never predicts the other.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		'lib/Repair/MigrateRegisterSlug.php'         => 'the rename itself; it must name what it renames from',
		'lib/Repair/MigrateSchemaApplicationId.php'  => 'app ids, not register slugs',
		'lib/Repair/MigrateAppConfigKeys.php'        => 'app ids, not register slugs',
		'lib/Repair/MigrateUserPreferences.php'      => 'app ids, not register slugs',
		'lib/Support/FleetAppId.php'                 => 'the app-id alias map; a different question with a different source of truth',
	];

	/**
	 * Source patterns that put a string literal in REGISTER position.
	 *
	 * Deliberately narrow. A slug is only a defect where it identifies a
	 * register; the same word as an app id, a provider name or a log message is
	 * not this defect, and a guard that flagged those would be turned off.
	 *
	 * @var list<string>
	 */
	private const REGISTER_POSITION = [
		'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		// The positional shape: find/findAll/saveObject with the register as the
		// fourth argument and no label anywhere on the line.
		'/->(?:find|findAll|saveObject)\(\s*[^,()]+,\s*[^,()\[\]]*(?:\[[^\]]*\])?[^,()]*,\s*[^,()]+,\s*\'([a-zA-Z0-9_-]+)\'/',
	];

	/**
	 * No file under lib/ names a superseded register slug in register position.
	 *
	 * @return void
	 */
	public function testNoSourceFilePinsASupersededRegisterSlug(): void {
		$findings = [];
		foreach ($this->sourceFiles() as $relative => $absolute) {
			if (isset(self::ALLOWED[$relative]) === true) {
				continue;
			}

			// NOT FILE_SKIP_EMPTY_LINES. Skipping blank lines renumbers every
			// line after the first one, so `$index + 1` stops being the line
			// number and becomes the count of non-blank lines. Measured on
			// openregister's reconciler: a pin on line 590 was reported as line
			// 528, because 62 blank lines preceded it. A guard that names the
			// wrong line is a guard whose next reader concludes it is broken.
			$lines = file($absolute, FILE_IGNORE_NEW_LINES);
			if ($lines === false) {
				continue;
			}

			foreach ($lines as $index => $line) {
				foreach (self::REGISTER_POSITION as $pattern) {
					if (preg_match($pattern, $line, $matches) !== 1) {
						continue;
					}

					$slug = strtolower($matches[1]);
					if (isset(self::SUPERSEDED[$slug]) === false) {
						continue;
					}

					$findings[] = sprintf(
						'%s:%d pins the superseded register slug \'%s\'. Resolve \'%s\' through '
						. 'RegisterSlugResolverInterface::resolve() instead, and branch on isResolved(), '
						. 'because reading with a slug this instance does not carry returns zero rows, '
						. 'not an error.',
						$relative,
						($index + 1),
						$slug,
						self::SUPERSEDED[$slug]
					);
				}
			}
		}

		$this->assertSame([], $findings, "Superseded register slugs are pinned:\n" . implode("\n", $findings));
	}//end testNoSourceFilePinsASupersededRegisterSlug()

	/**
	 * The guard actually looks at something.
	 *
	 * A file walker that silently finds no files is the classic hollow green:
	 * the assertion above would pass on an empty list forever. This pins the
	 * walker to a floor well below the real count, so a broken path fails here
	 * rather than passing there.
	 *
	 * @return void
	 */
	public function testTheGuardScansTheSourceTree(): void {
		$files = $this->sourceFiles();

		$this->assertGreaterThan(200, count($files), 'The walker must see lib/, or the guard above cannot fail.');
		$this->assertArrayHasKey(
			'lib/Controller/VotingBehaviourController.php',
			$files,
			'The controller is the file this guard was written for; the walker must reach it.'
		);
	}//end testTheGuardScansTheSourceTree()

	/**
	 * The patterns match a pinned slug when one is present.
	 *
	 * Watched failing is not enough on its own once the tree is clean: from then
	 * on the guard passes whether or not its regexes still work. This feeds each
	 * register-position form a known-bad line and requires a match, so a regex
	 * that stops matching reddens immediately instead of going quiet.
	 *
	 * @return void
	 */
	public function testEachRegisterPositionPatternStillMatches(): void {
		$samples = [
			'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/' => "\$objectService->setRegister('decidesk');",
			'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/'                    => "\$svc->find(id: \$id, register: 'decidesk', schema: 'participant');",
			'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/'              => "'filters' => ['register' => 'decidesk', 'schema' => 'vote'],",
			'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\tprivate const PARTICIPANT_REGISTER = 'decidesk';",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$registerSlug = 'decidesk';",
			'/->(?:find|findAll|saveObject)\(\s*[^,()]+,\s*[^,()\[\]]*(?:\[[^\]]*\])?[^,()]*,\s*[^,()]+,\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$e = \$this->objectService->find(\$participantId, [], false, 'decidesk', 'participant');",
		];

		foreach (self::REGISTER_POSITION as $pattern) {
			$this->assertArrayHasKey($pattern, $samples, 'Every register-position pattern needs a known-bad sample.');
			$this->assertSame(
				1,
				preg_match($pattern, $samples[$pattern], $matches),
				'Pattern must match its known-bad sample: ' . $pattern
			);
			$this->assertArrayHasKey(
				strtolower($matches[1]),
				self::SUPERSEDED,
				'The sample must capture a slug this guard calls superseded: ' . $pattern
			);
		}
	}//end testEachRegisterPositionPatternStillMatches()

	/**
	 * Every PHP file under lib/, keyed by repository-relative path.
	 *
	 * @return array<string, string> Relative path => absolute path.
	 */
	private function sourceFiles(): array {
		$root = dirname(__DIR__, 3);
		$lib = $root . '/lib';

		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (($file instanceof SplFileInfo) === false || $file->isFile() === false) {
				continue;
			}

			if ($file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			$files[ltrim(str_replace($root, '', $path), '/')] = $path;
		}

		return $files;
	}//end sourceFiles()
}//end class
