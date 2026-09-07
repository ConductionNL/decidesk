<?php

/**
 * appinfo/info.xml's repair-step blocks hold only comments and steps.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * A dropped `<!--` turns a comment into character content, which is invalid.
 *
 * 🔴 WELL-FORMED IS NOT SCHEMA-VALID, AND THE LOCAL CHECK ONLY TESTED THE FIRST.
 *
 * Resolving a merge conflict in `info.xml` lost the opening `<!--` of one repair
 * step's comment. The file still PARSED — `xml.dom.minidom` and every PHP XML
 * reader accept it, because stray text inside an element is well-formed — so the
 * local check said yes. CI's `info.xml lint` runs `xmllint --schema` against
 * Nextcloud's XSD, which declares `<post-migration>` as element-only content, and
 * refused it:
 *
 *   appinfo/info.xml:134: element post-migration: Schemas validity error :
 *   Element 'post-migration': Character content other than whitespace is not
 *   allowed because the content type is element-only.
 *
 * `xmllint` is not installed on this workstation, so the only local signal was a
 * parser that does not care. This test is the signal.
 *
 * @spec exclude Markup contract on a file this repository owns; no behavioural spec.
 */
class RepairStepMarkupContractTest extends TestCase {

	/**
	 * The repair-step blocks contain nothing but comments and `<step>` elements.
	 *
	 * @return void
	 */
	public function testRepairBlocksHoldOnlyCommentsAndSteps(): void {
		$path   = __DIR__ . '/../../../appinfo/info.xml';
		$source = (string)file_get_contents($path);
		$this->assertNotSame('', $source, 'appinfo/info.xml must be readable');

		$blocks = ['pre-migration', 'post-migration', 'install', 'uninstall'];
		$seen   = 0;

		foreach ($blocks as $block) {
			// The element, not a mention of it. `info.xml` discusses
			// `<post-migration>` inside its own comments, and matching the first
			// occurrence would read from the COMMENT to the real closing tag and
			// report the comment's prose as stray content. Anchor on the tag
			// alone on its own line, which only the real element is.
			$pattern = sprintf(
				'#^[ \t]*<%1$s>[ \t]*$(.*?)^[ \t]*</%1$s>[ \t]*$#ms',
				preg_quote($block, '#')
			);
			if (preg_match($pattern, $source, $match) !== 1) {
				continue;
			}

			$seen++;
			$body = $match[1];
			$body = (string)preg_replace('#<!--.*?-->#s', '', $body);
			$body = (string)preg_replace('#<step>.*?</step>#s', '', $body);

			$this->assertSame(
				'',
				trim($body),
				sprintf(
					'`<%s>` holds character content, which Nextcloud\'s XSD declares element-only. '
						. 'Almost always a dropped `<!--` turning a comment into text: the file still '
						. 'PARSES, so a well-formedness check passes and only `xmllint --schema` in CI '
						. 'refuses it. Leftover: %s',
					$block,
					substr(trim($body), 0, 200)
				)
			);
		}//end foreach

		// Not vacuous: if the blocks are ever renamed this test would otherwise
		// pass by checking nothing at all.
		$this->assertGreaterThan(
			0,
			$seen,
			'No repair-step block was found, so this test asserted nothing.'
		);

	}//end testRepairBlocksHoldOnlyCommentsAndSteps()

	/**
	 * Every comment in the file is closed.
	 *
	 * An unclosed `<!--` swallows every element after it, which shows up as a
	 * repair step that silently stops running rather than as a parse error.
	 *
	 * @return void
	 */
	public function testEveryCommentIsClosed(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		$this->assertSame(
			substr_count($source, '<!--'),
			substr_count($source, '-->'),
			'appinfo/info.xml has an unbalanced comment: an unclosed `<!--` swallows every '
				. 'element after it, so a repair step stops being registered without any parse error.'
		);

	}//end testEveryCommentIsClosed()
}//end class
