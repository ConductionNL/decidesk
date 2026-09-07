<?php

/**
 * Unit tests for MinutesDocumentService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Exception\MissingRelationException;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\MinutesDocumentService;
use OCA\Decidiq\Service\MinutesGenerationService;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests document content resolution, persistence into the meeting folder,
 * the honest Docudesk-absent fallback, and the generatedDocuments record.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class MinutesDocumentServiceTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock MinutesGenerationService.
	 *
	 * @var MinutesGenerationService&MockObject
	 */
	private MinutesGenerationService&MockObject $generationService;

	/**
	 * Mock MeetingFolderService.
	 *
	 * @var MeetingFolderService&MockObject
	 */
	private MeetingFolderService&MockObject $folderService;

	/**
	 * The service under test.
	 *
	 * @var MinutesDocumentService
	 */
	private MinutesDocumentService $service;

	/**
	 * Optional Docudesk PdfService fake (null = Docudesk absent).
	 *
	 * @var object|null
	 */
	/**
	 * The PDF bytes filinq should answer with, or null when it cannot.
	 *
	 * @var string|null
	 */
	private ?string $pdfBytes = null;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// The OpenRegister object service is injected directly (ADR-083/084),
		// and the optional PDF pathway now arrives as its own collaborator.
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->generationService = $this->createMock(MinutesGenerationService::class);
		$this->folderService = $this->createMock(MeetingFolderService::class);

		// The RESOLVER is the collaborator now, not the container. Which name
		// filinq answers to, and what happens when it answers to none, is
		// FilinqPdf's own concern and has its own test — asserting it again
		// through this service would be testing the lookup twice and the
		// minutes document never.
		$filinqPdf = $this->createMock(FilinqPdf::class);
		$filinqPdf->method('fromHtml')->willReturnCallback(
			fn (): ?string => $this->pdfBytes
		);

		$this->service = new MinutesDocumentService(
			filinqPdf: $filinqPdf,
			logger: $this->createMock(LoggerInterface::class),
			generationService: $this->generationService,
			folderService: $this->folderService,
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Helper: create an ObjectEntity double exposing getObject().
	 *
	 * Must be an ObjectEntity double, not a stdClass one: ObjectService::find()
	 * is typed `?ObjectEntity` in production, so a stdClass mock is a value the
	 * service can never hand the code under test (#399).
	 *
	 * @param array<string,mixed> $data Object data
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function createEntityMock(array $data): ObjectEntity&MockObject {
		$mock = $this->createMock(ObjectEntity::class);
		$mock->method('getObject')->willReturn($data);
		return $mock;
	}//end createEntityMock()

	/**
	 * Markdown generation persists the minutes content into the meeting
	 * folder's 'Minutes' subfolder and records the generated document.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testGenerateMarkdownPersistsContentAndRecordsDocument(): void {
		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-001',
				'title' => 'Notulen raadsvergadering',
				'version' => 2,
				'content' => '# Notulen\n\nBesproken.',
				'lifecycle' => 'approved',
				'meeting' => ['id' => 'meeting-001', 'title' => 'Raadsvergadering'],
			]
		);

		$this->objectService->method('find')->willReturn($minutesEntity);

		$capturedContent = null;
		$this->folderService->expects($this->once())
			->method('writeMeetingFile')
			->willReturnCallback(
				static function (array $meeting, string $subfolder, string $fileName, string $content) use (&$capturedContent): string {
					$capturedContent = $content;
					return 'Decidiq/Raad/Minutes/' . $fileName;
				}
			);

		$savedObject = null;
		$this->objectService->method('saveObject')
			->willReturnCallback(static function (array $object) use (&$savedObject): array {
				$savedObject = $object;
				return $object;
			});

		$result = $this->service->generate(minutesId: 'minutes-001', format: 'markdown', displayName: 'Secretaris');

		self::assertSame('markdown', $result['format']);
		self::assertFalse($result['docudesk']);
		self::assertArrayNotHasKey('note', $result);
		self::assertStringContainsString('# Notulen', $capturedContent);
		self::assertCount(1, $savedObject['generatedDocuments']);
		self::assertSame('Secretaris', $savedObject['generatedDocuments'][0]['generatedBy']);

	}//end testGenerateMarkdownPersistsContentAndRecordsDocument()

	/**
	 * Empty content falls back to the generated draft and appends the live
	 * itemNotes section.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testGenerateFallsBackToDraftAndAppendsItemNotes(): void {
		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-002',
				'title' => 'Notulen',
				'content' => '',
				'meeting' => ['id' => 'meeting-001'],
				'itemNotes' => [
					['agendaItem' => 'ai-1', 'notes' => 'Lange discussie.', 'decisions' => 'Aangenomen.'],
				],
			]
		);

		$this->objectService->method('find')->willReturnCallback(
			function (string $id) use ($minutesEntity): ?object {
				if ($id === 'minutes-002') {
					return $minutesEntity;
				}

				if ($id === 'ai-1') {
					return $this->createEntityMock(['id' => 'ai-1', 'title' => 'Woningbouwplan Oost']);
				}

				return null;
			}
		);

		$this->generationService->expects($this->once())
			->method('generateDraft')
			->with('minutes-002')
			->willReturn('# Conceptnotulen');

		$capturedContent = null;
		$this->folderService->method('writeMeetingFile')
			->willReturnCallback(
				static function (array $meeting, string $subfolder, string $fileName, string $content) use (&$capturedContent): string {
					$capturedContent = $content;
					return 'Decidiq/x/Minutes/' . $fileName;
				}
			);

		$this->service->generate(minutesId: 'minutes-002', format: 'markdown', displayName: 'S');

		self::assertStringContainsString('# Conceptnotulen', $capturedContent);
		self::assertStringContainsString('Woningbouwplan Oost', $capturedContent);
		self::assertStringContainsString('Lange discussie.', $capturedContent);
		self::assertStringContainsString('Aangenomen.', $capturedContent);

	}//end testGenerateFallsBackToDraftAndAppendsItemNotes()

	/**
	 * PDF without Docudesk degrades to markdown with an honest note —
	 * never a silent failure or a fake PDF.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testGeneratePdfWithoutDocudeskFallsBackToMarkdownWithNote(): void {
		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-003',
				'title' => 'Notulen',
				'content' => '# Inhoud',
				'meeting' => ['id' => 'meeting-001'],
			]
		);

		$this->objectService->method('find')->willReturn($minutesEntity);

		$writtenNames = [];
		$this->folderService->method('writeMeetingFile')
			->willReturnCallback(
				static function (array $meeting, string $subfolder, string $fileName, string $content) use (&$writtenNames): string {
					$writtenNames[] = $fileName;
					return 'Decidiq/x/Minutes/' . $fileName;
				}
			);

		$result = $this->service->generate(minutesId: 'minutes-003', format: 'pdf', displayName: 'S');

		self::assertSame('markdown', $result['format']);
		self::assertFalse($result['docudesk']);
		self::assertArrayHasKey('note', $result);
		self::assertStringContainsString('Docudesk', $result['note']);
		self::assertCount(1, $writtenNames);
		self::assertStringEndsWith('.md', $writtenNames[0]);

	}//end testGeneratePdfWithoutDocudeskFallsBackToMarkdownWithNote()

	/**
	 * PDF with Docudesk resolvable writes the rendered PDF bytes.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testGeneratePdfWithDocudeskWritesPdf(): void {
		$this->pdfBytes = '%PDF-1.4 fake';

		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-004',
				'title' => 'Notulen',
				'content' => '# Inhoud',
				'meeting' => ['id' => 'meeting-001'],
			]
		);

		$this->objectService->method('find')->willReturn($minutesEntity);

		$writes = [];
		$this->folderService->method('writeMeetingFile')
			->willReturnCallback(
				static function (array $meeting, string $subfolder, string $fileName, string $content) use (&$writes): string {
					$writes[$fileName] = $content;
					return 'Decidiq/x/Minutes/' . $fileName;
				}
			);

		$result = $this->service->generate(minutesId: 'minutes-004', format: 'pdf', displayName: 'S');

		self::assertSame('pdf', $result['format']);
		self::assertTrue($result['docudesk']);
		self::assertCount(1, $writes);
		self::assertStringEndsWith('.pdf', array_key_first($writes));
		self::assertSame('%PDF-1.4 fake', $writes[array_key_first($writes)]);

	}//end testGeneratePdfWithDocudeskWritesPdf()

	/**
	 * Unsupported format is refused with 422 semantics.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testGenerateUnsupportedFormatThrowsInvalidArgumentException(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported format');

		$this->service->generate(minutesId: 'minutes-001', format: 'odt', displayName: 'S');

	}//end testGenerateUnsupportedFormatThrowsInvalidArgumentException()

	/**
	 * Unknown minutes throws MissingObjectException.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testGenerateUnknownMinutesThrowsMissingObjectException(): void {
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(MissingObjectException::class);

		$this->service->generate(minutesId: 'minutes-404', format: 'markdown', displayName: 'S');

	}//end testGenerateUnknownMinutesThrowsMissingObjectException()

	/**
	 * Minutes without a linked meeting throws MissingRelationException —
	 * the document has no folder to live in.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testGenerateWithoutMeetingThrowsMissingRelationException(): void {
		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-005',
				'title' => 'Notulen',
				'content' => '# Inhoud',
			]
		);

		$this->objectService->method('find')->willReturn($minutesEntity);

		$this->expectException(MissingRelationException::class);

		$this->service->generate(minutesId: 'minutes-005', format: 'markdown', displayName: 'S');

	}//end testGenerateWithoutMeetingThrowsMissingRelationException()

	/**
	 * A null write result (Files unavailable) becomes a RuntimeException
	 * with 503 semantics — the caller is told, nothing silently succeeds.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testGenerateThrowsRuntimeExceptionWhenWriteFails(): void {
		$minutesEntity = $this->createEntityMock(
			[
				'id' => 'minutes-006',
				'title' => 'Notulen',
				'content' => '# Inhoud',
				'meeting' => ['id' => 'meeting-001'],
			]
		);

		$this->objectService->method('find')->willReturn($minutesEntity);
		$this->folderService->method('writeMeetingFile')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('could not be stored');

		$this->service->generate(minutesId: 'minutes-006', format: 'markdown', displayName: 'S');

	}//end testGenerateThrowsRuntimeExceptionWhenWriteFails()
}//end class
