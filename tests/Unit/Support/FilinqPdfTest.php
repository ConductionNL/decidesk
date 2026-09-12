<?php

/**
 * FilinqPdf Unit Tests
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Support;

use OCA\Decidiq\Support\FilinqPdf;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The one place that decides whether a document comes out as PDF.
 *
 * THREE DIFFERENT FAILURES, ONE BEHAVIOUR, and that is the whole point of the
 * class: filinq absent, filinq unhappy, and filinq answering with nothing all
 * mean "write markdown instead". Before this existed each caller made that
 * decision itself, so the three cases were only ever exercised by accident.
 *
 * NULL IS A NORMAL ANSWER here, not a fault, which is why every case below
 * asserts a null rather than an exception.
 *
 * The named-parameter sniff is for calls into OUR code, where a named
 * argument documents the call site. These are PHPUnit assertions and
 * anonymous-class methods, so it does not apply — the same exemption the
 * Repair tests next door take.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Decidiq\Support\FilinqPdf
 *
 * @uses \OCA\Decidiq\Support\FleetAppId
 */
class FilinqPdfTest extends TestCase {

	/**
	 * A container that answers with $service for the named class, and throws
	 * for every other lookup the resolver tries.
	 *
	 * @param string|null $fqcn    The class the container knows, or null for none.
	 * @param object|null $service What it answers with.
	 *
	 * @return ContainerInterface The double.
	 */
	private function container(?string $fqcn, ?object $service): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($fqcn, $service): object {
				if ($fqcn !== null && $id === $fqcn && $service !== null) {
					return $service;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * Build the subject.
	 *
	 * @param ContainerInterface $container The container double.
	 *
	 * @return FilinqPdf The subject.
	 */
	private function subject(ContainerInterface $container): FilinqPdf {
		return new FilinqPdf($container, $this->createMock(LoggerInterface::class));
	}//end subject()

	/**
	 * A renderer that answers with whatever it was given.
	 *
	 * @param mixed $answer What generatePdfFromHtml returns.
	 *
	 * @return object The double.
	 */
	private function renderer(mixed $answer): object {
		return new class($answer) {

			/**
			 * @param mixed $answer What to answer with.
			 */
			public function __construct(private readonly mixed $answer) {
			}//end __construct()

			/**
			 * @param string $html    Ignored.
			 * @param array<string,mixed> $options Ignored.
			 *
			 * @return mixed The canned answer.
			 */
			public function generatePdfFromHtml(string $html, array $options = []): mixed {
				return $this->answer;
			}//end generatePdfFromHtml()
		};
	}//end renderer()

	/**
	 * The happy path, under filinq's CURRENT namespace.
	 *
	 * @return void
	 */
	public function testItRendersThroughFilinq(): void {
		$container = $this->container('OCA\Filinq\Service\PdfService', $this->renderer('%PDF-1.4 real'));

		$this->assertSame(
			'%PDF-1.4 real',
			$this->subject($container)->fromHtml('<p>x</p>', 'Title', 'a test')
		);
	}//end testItRendersThroughFilinq()

	/**
	 * 🔴 THE CASE THE WHOLE RESOLVER EXISTS FOR. An instance still running the
	 * app under its retired name must render, not fall back silently. Pinned to
	 * one name, thirteen bindings across five apps went dark for a fortnight
	 * and every gate stayed green.
	 *
	 * @return void
	 */
	public function testItRendersThroughFilinqUnderItsRetiredName(): void {
		$container = $this->container('OCA\DocuDesk\Service\PdfService', $this->renderer('%PDF-1.4 legacy'));

		$this->assertSame(
			'%PDF-1.4 legacy',
			$this->subject($container)->fromHtml('<p>x</p>', 'Title', 'a test')
		);
	}//end testItRendersThroughFilinqUnderItsRetiredName()

	/**
	 * filinq absent: null, so the caller writes markdown.
	 *
	 * @return void
	 */
	public function testAnAbsentFilinqAnswersNull(): void {
		$this->assertNull(
			$this->subject($this->container(null, null))->fromHtml('<p>x</p>', 'Title', 'a test')
		);
	}//end testAnAbsentFilinqAnswersNull()

	/**
	 * filinq present but answering with nothing is NOT a PDF. An empty string
	 * returned as success is exactly the shape that lets an empty document
	 * ship looking like a real one.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerIsNotAPdf(): void {
		$container = $this->container('OCA\Filinq\Service\PdfService', $this->renderer(''));

		$this->assertNull($this->subject($container)->fromHtml('<p>x</p>', 'Title', 'a test'));
	}//end testAnEmptyAnswerIsNotAPdf()

	/**
	 * A non-string answer is refused too, rather than handed on as bytes.
	 *
	 * @return void
	 */
	public function testANonStringAnswerIsRefused(): void {
		$container = $this->container('OCA\Filinq\Service\PdfService', $this->renderer(null));

		$this->assertNull($this->subject($container)->fromHtml('<p>x</p>', 'Title', 'a test'));
	}//end testANonStringAnswerIsRefused()

	/**
	 * A renderer that throws is a fallback, not a failure: the caller still has
	 * a markdown document to produce.
	 *
	 * @return void
	 */
	public function testAThrowingRendererFallsBackRatherThanPropagating(): void {
		$exploding = new class {

			/**
			 * @param string $html    Ignored.
			 * @param array<string,mixed> $options Ignored.
			 *
			 * @return string Never; always throws.
			 */
			public function generatePdfFromHtml(string $html, array $options = []): string {
				throw new RuntimeException('renderer exploded');
			}//end generatePdfFromHtml()
		};

		$container = $this->container('OCA\Filinq\Service\PdfService', $exploding);

		$this->assertNull($this->subject($container)->fromHtml('<p>x</p>', 'Title', 'a test'));
	}//end testAThrowingRendererFallsBackRatherThanPropagating()

	/**
	 * Every fallback says WHY in the log. "No PDF" with no cause is what let
	 * the retired-namespace binding hide for a fortnight.
	 *
	 * @return void
	 */
	public function testEveryFallbackIsLoggedWithItsContext(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logged = [];
		$logger->method('info')->willReturnCallback(
			static function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);

		$subject = new FilinqPdf($this->container(null, null), $logger);
		$subject->fromHtml('<p>x</p>', 'Title', 'the minutes document');

		$this->assertCount(1, $logged);
		$this->assertStringContainsString('the minutes document', $logged[0]);
	}//end testEveryFallbackIsLoggedWithItsContext()

}//end class
