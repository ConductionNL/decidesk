<?php

/**
 * Decidiq Minutes Document Service
 *
 * Renders the minutes content into a formatted document and persists it into
 * the linked meeting's Files folder ('Minutes' subfolder). PDF rendering is
 * delegated to filinq when (and only when) FilinqPdf can resolve it, under
 * whichever name that app carries on this instance; otherwise the plain
 * markdown document is produced and the response says so honestly.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Exception\MissingRelationException;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generates and persists minutes documents (markdown canonical, Docudesk PDF
 * opportunistic) into the meeting's Files folder.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class MinutesDocumentService {

	/**
	 * Formats the caller may request.
	 *
	 * @var string[]
	 */
	public const FORMATS = [
		'markdown',
		'pdf',
	];

	/**
	 * Block-level markdown patterns mapped to their HTML template, in match order.
	 *
	 * @var array<string, string>
	 */
	private const BLOCK_PATTERNS = [
		'/^### (.*)$/' => '<h3>%s</h3>',
		'/^## (.*)$/' => '<h2>%s</h2>',
		'/^# (.*)$/' => '<h1>%s</h1>',
		'/^\d+\. (.*)$/' => '<p class="list-item">%s</p>',
	];

	/**
	 * Constructor for MinutesDocumentService.
	 *
	 * @param FilinqPdf $filinqPdf Renders a PDF through filinq when it is installed
	 * @param LoggerInterface $logger The logger
	 * @param MinutesGenerationService $generationService Draft generator (content fallback)
	 * @param MeetingFolderService $folderService Meeting Files folder writer
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly FilinqPdf $filinqPdf,
		private readonly LoggerInterface $logger,
		private readonly MinutesGenerationService $generationService,
		private readonly MeetingFolderService $folderService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Generate a minutes document and persist it into the meeting folder.
	 *
	 * Content resolution: the minutes' own `content` when non-empty, otherwise
	 * the generated draft (which auto-inserts voting results from the voting
	 * system); the live `itemNotes` captured during the meeting are appended
	 * as a "Live notes" section either way.
	 *
	 * Format `pdf` uses Docudesk's PdfService when resolvable; when Docudesk
	 * is absent (or rendering fails) the markdown document is persisted
	 * instead and the result carries `docudesk: false` plus an honest note.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 * @param string $format Requested format ('markdown' or 'pdf')
	 * @param string $displayName Display name of the requesting user (server session)
	 *
	 * @throws MissingObjectException When the Minutes object is not found
	 * @throws MissingRelationException When no Meeting is linked to the Minutes
	 * @throws InvalidArgumentException When the format is not supported
	 * @throws RuntimeException When OpenRegister or Files is unavailable
	 *
	 * @return array<string,mixed> Keys: path, format, docudesk, and optionally note.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function generate(string $minutesId, string $format, string $displayName): array {
		if (in_array($format, self::FORMATS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unsupported format "%s". Supported: %s.', $format, implode(', ', self::FORMATS)),
				422
			);
		}

		$objectService = $this->getObjectService();

		// ACL applies via session-scoped register/schema context (OWASP A01).
		$objectService->setRegister('decidiq');
		$objectService->setSchema('minutes');
		$minutesEntity = $objectService->find($minutesId);

		if ($minutesEntity === null) {
			throw new MissingObjectException(
				message: sprintf('Minutes object "%s" not found.', $minutesId)
			);
		}

		$minutes = $minutesEntity->getObject();
		$meeting = $this->resolveMeeting(minutes: $minutes, objectService: $objectService);

		if ($meeting === null) {
			throw new MissingRelationException(
				message: sprintf(
					'No linked Meeting found for Minutes "%s". A meeting link is required to store the document.',
					$minutesId
				)
			);
		}

		$markdown = $this->resolveContent(minutes: $minutes, minutesId: $minutesId, objectService: $objectService);

		$title = (string)($minutes['title'] ?? 'Minutes');
		$version = (int)($minutes['version'] ?? 1);
		$stamp = (new DateTimeImmutable())->format('Y-m-d Hi');
		$baseName = sprintf('%s v%d %s', $title, $version, $stamp);

		$document = $this->storeDocument(
			meeting: $meeting,
			baseName: $baseName,
			markdown: $markdown,
			title: $title,
			format: $format
		);

		$path = $document['path'];
		if ($path === null) {
			throw new RuntimeException(
				'The minutes document could not be stored: the Files backend is unavailable.',
				503
			);
		}

		$this->appendGeneratedDocument(
			minutes: $minutes,
			minutesId: $minutesId,
			record: [
				'path' => $path,
				'format' => $document['format'],
				'generatedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
				'generatedBy' => $displayName,
				'docudesk' => $document['docudesk'],
			],
			objectService: $objectService
		);

		$result = [
			'path' => $path,
			'format' => $document['format'],
			'docudesk' => $document['docudesk'],
		];

		if ($document['note'] !== null) {
			$result['note'] = $document['note'];
		}

		return $result;
	}//end generate()

	/**
	 * Render and persist the document, degrading from PDF to markdown honestly.
	 *
	 * A PDF is attempted only when it was asked for AND Docudesk rendered bytes;
	 * whenever no PDF path was produced the markdown document is written instead.
	 *
	 * @param array<string,mixed> $meeting The linked Meeting data
	 * @param string $baseName File name without extension
	 * @param string $markdown The markdown document body
	 * @param string $title The document title (PDF metadata)
	 * @param string $format Requested format ('markdown' or 'pdf')
	 *
	 * @return array{path: string|null, format: string, docudesk: bool, note: string|null}
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function storeDocument(
		array $meeting,
		string $baseName,
		string $markdown,
		string $title,
		string $format,
	): array {
		$produced = 'markdown';
		$note = null;
		$docudesk = false;
		$path = null;
		$pdfBytes = null;

		if ($format === 'pdf') {
			$pdfBytes = $this->tryDocudeskPdf(markdown: $markdown, title: $title);
			if ($pdfBytes === null) {
				$note = 'Docudesk is not available on this instance — a markdown document was produced instead of a PDF.';
			}
		}

		if ($pdfBytes !== null) {
			$path = $this->folderService->writeMeetingFile(
				meeting: $meeting,
				subfolder: 'Minutes',
				fileName: $baseName . '.pdf',
				content: $pdfBytes
			);
			$produced = 'pdf';
			$docudesk = true;
		}

		if ($path === null) {
			$path = $this->folderService->writeMeetingFile(
				meeting: $meeting,
				subfolder: 'Minutes',
				fileName: $baseName . '.md',
				content: $markdown
			);
			$produced = 'markdown';
		}

		return [
			'path' => $path,
			'format' => $produced,
			'docudesk' => $docudesk,
			'note' => $note,
		];

	}//end storeDocument()

	/**
	 * Resolve the document body: minutes content, draft fallback, live notes.
	 *
	 * @param array<string,mixed> $minutes The Minutes object data
	 * @param string $minutesId UUID of the Minutes object
	 * @param object $objectService OpenRegister ObjectService
	 *
	 * @return string Markdown document body
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function resolveContent(array $minutes, string $minutesId, object $objectService): string {
		$content = trim((string)($minutes['content'] ?? ''));

		if ($content === '') {
			// The generated draft auto-inserts agenda, motions, voting results,
			// and decisions from the meeting data.
			$content = $this->generationService->generateDraft($minutesId);
		}

		$itemNotes = ($minutes['itemNotes'] ?? []);
		if (is_array($itemNotes) === false || $itemNotes === []) {
			return $content;
		}

		$content .= "\n\n---\n\n## Aantekeningen per agendapunt\n";
		foreach ($itemNotes as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$content .= $this->renderItemNote(entry: $entry, objectService: $objectService);
		}

		return $content;
	}//end resolveContent()

	/**
	 * Render one agenda-item note block; empty string when it carries nothing.
	 *
	 * @param array<string,mixed> $entry One itemNotes entry
	 * @param object $objectService OpenRegister ObjectService
	 *
	 * @return string Markdown block (empty when notes and decisions are blank)
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function renderItemNote(array $entry, object $objectService): string {
		$notes = trim((string)($entry['notes'] ?? ''));
		$decisions = trim((string)($entry['decisions'] ?? ''));

		// Both parts are already trimmed, so an empty concatenation means both are blank.
		if (($notes . $decisions) === '') {
			return '';
		}

		$itemTitle = $this->resolveAgendaItemTitle(
			agendaItemId: (string)($entry['agendaItem'] ?? ''),
			objectService: $objectService
		);

		$block = "\n### " . $itemTitle . "\n";
		if ($notes !== '') {
			$block .= "\n" . $notes . "\n";
		}

		if ($decisions !== '') {
			$block .= "\n**Besluiten:** " . $decisions . "\n";
		}

		return $block;
	}//end renderItemNote()

	/**
	 * Resolve an agenda item's display title (fail-soft to the UUID).
	 *
	 * @param string $agendaItemId UUID of the agenda item
	 * @param object $objectService OpenRegister ObjectService
	 *
	 * @return string Agenda item title or the UUID when unresolvable
	 */
	private function resolveAgendaItemTitle(string $agendaItemId, object $objectService): string {
		if ($agendaItemId === '') {
			return 'Agendapunt';
		}

		try {
			$entity = $objectService->find(id: $agendaItemId, register: 'decidiq', schema: 'agenda-item');
			if ($entity !== null) {
				$item = $entity->getObject();
				return (string)($item['title'] ?? ($item['name'] ?? $agendaItemId));
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: agenda item title lookup failed for document generation',
				['agendaItemId' => $agendaItemId, 'error' => $e->getMessage()]
			);
		}

		return $agendaItemId;
	}//end resolveAgendaItemTitle()

	/**
	 * Try to render a PDF via Docudesk; return null when Docudesk is absent
	 * or rendering fails (the caller falls back to markdown — never a stub).
	 *
	 * @param string $markdown The markdown document body
	 * @param string $title The document title (PDF metadata)
	 *
	 * @return string|null PDF binary content or null
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function tryDocudeskPdf(string $markdown, string $title): ?string {
		return $this->filinqPdf->fromHtml(
			html: $this->markdownToHtml(markdown: $markdown),
			title: $title,
			context: 'the minutes document'
		);
	}//end tryDocudeskPdf()

	/**
	 * Minimal markdown → HTML conversion for the Docudesk pathway.
	 *
	 * Covers the constructs the minutes templates emit: headings (#/##/###),
	 * bold, horizontal rules, list items, and paragraphs. Content is
	 * HTML-escaped before markup substitution so minute text can never inject
	 * markup into the rendered PDF.
	 *
	 * @param string $markdown The markdown source
	 *
	 * @return string A standalone HTML document
	 */
	private function markdownToHtml(string $markdown): string {
		$html = [];
		foreach (explode("\n", $markdown) as $line) {
			$html[] = $this->markdownLineToHtml(line: $line);
		}

		return '<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body>'
			. implode("\n", $html)
			. '</body></html>';

	}//end markdownToHtml()

	/**
	 * Convert a single markdown line into its HTML equivalent.
	 *
	 * The line is HTML-escaped first, then inline emphasis is substituted and
	 * the block-level patterns in BLOCK_PATTERNS are tried in declaration order;
	 * anything left over becomes a horizontal rule, a blank line, or a paragraph.
	 *
	 * @param string $line One raw markdown line
	 *
	 * @return string The HTML fragment for that line
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function markdownLineToHtml(string $line): string {
		$escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
		$escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
		$escaped = preg_replace('/_(.+?)_/', '<em>$1</em>', $escaped) ?? $escaped;

		foreach (self::BLOCK_PATTERNS as $pattern => $template) {
			if (preg_match($pattern, $escaped, $matches) === 1) {
				return sprintf($template, $matches[1]);
			}
		}

		$trimmed = trim($escaped);
		if ($trimmed === '---') {
			return '<hr/>';
		}

		if ($trimmed === '') {
			return '';
		}

		return '<p>' . $escaped . '</p>';
	}//end markdownLineToHtml()

	/**
	 * Append a generated-document record on the Minutes object (fail-soft).
	 *
	 * @param array<string,mixed> $minutes The Minutes object data
	 * @param string $minutesId UUID of the Minutes object
	 * @param array<string,mixed> $record The generated-document record
	 * @param object $objectService OpenRegister ObjectService
	 *
	 * @return void
	 */
	private function appendGeneratedDocument(array $minutes, string $minutesId, array $record, object $objectService): void {
		try {
			$documents = [];
			if (is_array($minutes['generatedDocuments'] ?? null) === true) {
				$documents = $minutes['generatedDocuments'];
			}

			$documents[] = $record;
			$updated = array_merge($minutes, ['generatedDocuments' => $documents]);

			$objectService->saveObject(object: $updated, register: 'decidiq', schema: 'minutes', uuid: $minutesId);
		} catch (\Throwable $e) {
			// The document itself was persisted; a failed bookkeeping write must
			// not fail the request. Log and continue.
			$this->logger->warning(
				'Decidiq: failed to record generated document on minutes object',
				['minutesId' => $minutesId, 'error' => $e->getMessage()]
			);
		}

	}//end appendGeneratedDocument()

	/**
	 * Resolve the linked Meeting payload from the Minutes object.
	 *
	 * @param array<string,mixed> $minutes The Minutes object data
	 * @param object $objectService OpenRegister ObjectService
	 *
	 * @return array<string,mixed>|null The Meeting data array or null
	 */
	private function resolveMeeting(array $minutes, object $objectService): ?array {
		// A null relation casts to '' further down and is rejected there.
		$meetingRelation = $minutes['relations']['meeting'] ?? $minutes['meeting'] ?? null;

		if (is_array($meetingRelation) === true) {
			if (isset($meetingRelation['id']) === true && $meetingRelation['id'] !== '') {
				return $meetingRelation;
			}

			return null;
		}

		$meetingId = (string)$meetingRelation;
		if ($meetingId === '') {
			return null;
		}

		try {
			$meetingEntity = $objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
			if ($meetingEntity === null) {
				return null;
			}

			return $meetingEntity->getObject();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: failed to fetch linked Meeting for document generation',
				['meetingId' => $meetingId, 'error' => $e->getMessage()]
			);
			throw new RuntimeException(
				'OpenRegister service is temporarily unavailable. Please try again later.',
				503,
				$e
			);
		}//end try

	}//end resolveMeeting()

	/**
	 * The OpenRegister ObjectService, injected since ADR-083.
	 *
	 * @return object The OpenRegister ObjectService instance
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable.
		return $this->objectService;
	}//end getObjectService()
}//end class
