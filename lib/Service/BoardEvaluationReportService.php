<?php

/**
 * Decidiq Board Evaluation Report Service
 *
 * Generates the evaluation report document for a closed BoardEvaluation
 * cycle, reusing the same generation posture as MinutesDocumentService:
 * markdown is the canonical format, Docudesk PDF rendering is used
 * opportunistically when Docudesk is installed, and the response says so
 * honestly when it falls back. No new document-rendering engine is
 * introduced. There is no Meeting to anchor a Files folder to (a
 * BoardEvaluation relates to a GovernanceBody), so the report is written
 * into a "Decidesk/<body>/Evaluations/<cycle>" folder using the same
 * OpenRegister FileService primitive MeetingFolderService uses.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\FileService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generates + persists the board-evaluation report document.
 *
 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
 */
class BoardEvaluationReportService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService / Docudesk lookup)
	 * @param LoggerInterface $logger Diagnostic logger
	 * @param FileService $fileService The OpenRegister file service
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly FileService $fileService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Generate the evaluation report (markdown canonical, Docudesk PDF
	 * opportunistic) and persist it under "Decidesk/<body>/Evaluations/<cycle>".
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation (must be closed or published)
	 *
	 * @throws MissingObjectException When the evaluation cannot be found
	 *
	 * @return array<string, mixed> {path, format, docudesk, note?}
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function generate(string $evaluationId): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $evaluationId, register: 'decidiq', schema: 'board-evaluation');
		if ($entity === null) {
			throw new MissingObjectException(message: "BoardEvaluation {$evaluationId} not found.");
		}

		$evaluation = $entity->jsonSerialize();
		$bodyName = $this->resolveBodyName(evaluation: $evaluation, objectService: $objectService);
		$markdown = $this->renderMarkdown(evaluation: $evaluation, bodyName: $bodyName);

		$title = sprintf('Board evaluation %s', (string)($evaluation['cycleLabel'] ?? $evaluationId));

		// Assume the markdown fallback; the Docudesk branch below clears the note.
		$note = 'Docudesk is not available on this instance — a markdown document was produced instead of a PDF.';
		$docudesk = false;
		$path = null;

		$pdfBytes = $this->tryDocudeskPdf(markdown: $markdown, title: $title);
		if ($pdfBytes !== null) {
			$path = $this->writeFile(bodyName: $bodyName, evaluation: $evaluation, fileName: $title . '.pdf', content: $pdfBytes);
			$docudesk = true;
			$note = null;
		}

		$format = 'pdf';
		if ($path === null) {
			$path = $this->writeFile(bodyName: $bodyName, evaluation: $evaluation, fileName: $title . '.md', content: $markdown);
			$format = 'markdown';
		}

		if ($path === null) {
			throw new RuntimeException('The evaluation report could not be stored: the Files backend is unavailable.', 503);
		}

		$result = ['path' => $path, 'format' => $format, 'docudesk' => $docudesk];
		if ($note !== null) {
			$result['note'] = $note;
		}

		return $result;
	}//end generate()

	/**
	 * Render the report body. Honours REQ-EVAL-004's threshold suppression:
	 * when `scoreSummary.suppressed` is true, only the aggregate overall
	 * score appears — no per-dimension or free-text section is rendered.
	 *
	 * @param array<string, mixed> $evaluation The BoardEvaluation payload
	 * @param string $bodyName Display name of the owning GovernanceBody
	 *
	 * @return string Markdown document body
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	private function renderMarkdown(array $evaluation, string $bodyName): string {
		$cycleLabel = (string)($evaluation['cycleLabel'] ?? '');
		$summary = $this->decodeSummary(evaluation: $evaluation);

		$bodyDisplayName = 'Governance body';
		if ($bodyName !== '') {
			$bodyDisplayName = $bodyName;
		}

		$lines = [];
		$lines[] = '# Board evaluation report — ' . $bodyDisplayName . ' (' . $cycleLabel . ')';
		$lines[] = '';
		$lines[] = sprintf(
			'Respondents: %d of %d invited.',
			(int)($summary['respondentCount'] ?? ($evaluation['respondedCount'] ?? 0)),
			(int)($summary['invitedMemberCount'] ?? ($evaluation['invitedMemberCount'] ?? 0))
		);
		$lines[] = '';

		if (empty($summary) === true) {
			$lines[] = '_No score summary has been materialised yet — close the cycle to generate scores._';
			return implode("\n", $lines);
		}

		$overall = $summary['overallScore'] ?? null;
		$overallLine = '_Not available (no Likert answers recorded)._';
		if ($overall !== null) {
			$overallLine = (string)$overall;
		}

		$lines[] = '## Overall board-effectiveness score';
		$lines[] = $overallLine;
		$lines[] = '';

		if (($summary['suppressed'] ?? false) === true) {
			$lines[] = '_Per-dimension and free-text breakdowns are suppressed: respondent count is below the '
				. 'minimum threshold, protecting anonymity on a small body._';
			return implode("\n", $lines);
		}

		return implode("\n", array_merge($lines, $this->renderBreakdown(summary: $summary)));
	}//end renderMarkdown()

	/**
	 * Decode the materialised `scoreSummary` JSON blob into an array.
	 *
	 * @param array<string, mixed> $evaluation The BoardEvaluation payload
	 *
	 * @return array<string, mixed> Decoded summary, or [] when absent/unparsable
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	private function decodeSummary(array $evaluation): array {
		$raw = (string)($evaluation['scoreSummary'] ?? '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decodeSummary()

	/**
	 * Render the per-dimension and free-text-theme sections.
	 *
	 * Only reached when REQ-EVAL-004 threshold suppression is NOT in force.
	 *
	 * @param array<string, mixed> $summary The decoded score summary
	 *
	 * @return string[] Markdown lines for the breakdown sections
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	private function renderBreakdown(array $summary): array {
		$lines = [];
		$lines[] = '## Per-dimension scores';
		foreach ((array)($summary['dimensionScores'] ?? []) as $dimension => $score) {
			$lines[] = sprintf('- **%s**: %s', $dimension, $score);
		}

		$lines[] = '';
		$lines[] = '## Recurring free-text themes';
		foreach ((array)($summary['themes'] ?? []) as $dimension => $words) {
			$wordList = implode(', ', array_map(static fn ($entry) => (string)($entry['word'] ?? ''), (array)$words));
			if ($wordList === '') {
				$wordList = '_none_';
			}

			$lines[] = sprintf('- **%s**: %s', $dimension, $wordList);
		}

		return $lines;
	}//end renderBreakdown()

	/**
	 * Try to render a PDF via Docudesk; return null when Docudesk is absent
	 * or rendering fails (the caller falls back to markdown).
	 *
	 * @param string $markdown The markdown document body
	 * @param string $title The document title (PDF metadata)
	 *
	 * @return string|null PDF binary content or null
	 */
	private function tryDocudeskPdf(string $markdown, string $title): ?string {
		try {
			// Resolved across every namespace filinq has shipped under. Bound to
			// 'OCA\DocuDesk\...' alone this get() threw on any current instance,
			// and the catch below turned that into a silent fallback — the board
			// evaluation report simply stopped coming out as a PDF.
			$pdfService = FleetAppId::getService($this->container, 'filinq', 'Service\PdfService');
			if ($pdfService === null) {
				return null;
			}
			$html = $this->markdownToHtml(markdown: $markdown);
			$pdf = $pdfService->generatePdfFromHtml($html, ['title' => $title]);
			if (is_string($pdf) === true && $pdf !== '') {
				return $pdf;
			}
		} catch (\Throwable $e) {
			$this->logger->info(
				'Decidiq: Docudesk PDF pathway unavailable for evaluation report, falling back to markdown',
				['error' => $e->getMessage()]
			);
		}

		return null;
	}//end tryDocudeskPdf()

	/**
	 * Minimal markdown -> HTML conversion for the Docudesk pathway (same
	 * minimal converter MinutesDocumentService uses; content is escaped
	 * before markup substitution).
	 *
	 * @param string $markdown The markdown source
	 *
	 * @return string A standalone HTML document
	 */
	private function markdownToHtml(string $markdown): string {
		$lines = explode("\n", $markdown);
		$html = [];

		foreach ($lines as $line) {
			$html[] = $this->markdownLineToHtml(line: $line);
		}

		return '<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body>' . implode("\n", $html) . '</body></html>';
	}//end markdownToHtml()

	/**
	 * Convert a single markdown line to its HTML equivalent.
	 *
	 * The line is HTML-escaped before any markup substitution.
	 *
	 * @param string $line Raw markdown line
	 *
	 * @return string HTML fragment for the line ('' for a blank line)
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	private function markdownLineToHtml(string $line): string {
		$escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

		if (preg_match('/^## (.*)$/', $escaped, $matches) === 1) {
			return '<h2>' . $matches[1] . '</h2>';
		}

		if (preg_match('/^# (.*)$/', $escaped, $matches) === 1) {
			return '<h1>' . $matches[1] . '</h1>';
		}

		if (preg_match('/^- (.*)$/', $escaped, $matches) === 1) {
			return '<p class="list-item">' . $matches[1] . '</p>';
		}

		if (trim($escaped) === '') {
			return '';
		}

		return '<p>' . $escaped . '</p>';
	}//end markdownLineToHtml()

	/**
	 * Write the report file into the body's evaluation folder tree
	 * ("Decidesk/<body>/Evaluations/<cycle>"), creating folders as needed.
	 *
	 * @param string $bodyName Display name of the owning GovernanceBody
	 * @param array<string, mixed> $evaluation The BoardEvaluation payload
	 * @param string $fileName File name including extension
	 * @param string $content File content (text or binary)
	 *
	 * @return string|null The full file path on success, or null on failure
	 */
	private function writeFile(string $bodyName, array $evaluation, string $fileName, string $content): ?string {
		try {
			$bodyDisplayName = 'Governance body';
			if ($bodyName !== '') {
				$bodyDisplayName = $bodyName;
			}

			$segments = ['Decidesk'];
			$segments[] = $this->sanitize(name: $bodyDisplayName);
			$segments[] = 'Evaluations';
			$segments[] = $this->sanitize(name: (string)($evaluation['cycleLabel'] ?? 'cycle'));

			$path = '';
			foreach ($segments as $segment) {
				$prefix = $path;
				$path = $segment;
				if ($prefix !== '') {
					$path = $prefix . '/' . $segment;
				}

				$this->fileService->createFolder($path);
			}

			$folderNode = $this->fileService->createFolder($path);
			$safeName = $this->sanitize(name: $fileName);

			try {
				$existing = $folderNode->get($safeName);
				$existing->putContent($content);
			} catch (\OCP\Files\NotFoundException) {
				$folderNode->newFile($safeName, $content);
			}

			return $path . '/' . $safeName;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: board evaluation report write failed',
				['fileName' => $fileName, 'error' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end writeFile()

	/**
	 * Resolve the owning GovernanceBody's display name.
	 *
	 * @param array<string, mixed> $evaluation The BoardEvaluation payload
	 * @param object $objectService OpenRegister ObjectService
	 *
	 * @return string Body name, or '' when not linked / not resolvable
	 */
	private function resolveBodyName(array $evaluation, object $objectService): string {
		$bodyId = $evaluation['governanceBody'] ?? ($evaluation['relations']['governanceBody'][0] ?? null);
		if (is_array($bodyId) === true) {
			$bodyId = ($bodyId['id'] ?? null);
		}

		if (is_string($bodyId) === false || $bodyId === '') {
			return '';
		}

		try {
			$entity = $objectService->find(id: $bodyId, register: 'decidiq', schema: 'governance-body');
		} catch (\Throwable) {
			return '';
		}

		if ($entity === null) {
			return '';
		}

		$body = (array)$entity->jsonSerialize();
		return (string)($body['name'] ?? ($body['title'] ?? ''));
	}//end resolveBodyName()

	/**
	 * Sanitize a name for use as a single Files path segment.
	 *
	 * @param string $name Raw display name
	 *
	 * @return string Path-safe segment (no slashes/backslashes/control chars)
	 */
	private function sanitize(string $name): string {
		$clean = preg_replace('/[\/\\\\:*?"<>|]+/', '-', $name) ?? '';
		$clean = preg_replace('/[[:cntrl:]]+/', '', $clean) ?? '';
		$clean = trim($clean, ' .-');
		if ($clean === '') {
			return 'evaluation';
		}

		return $clean;
	}//end sanitize()

	/**
	 * Lazy-load the OpenRegister ObjectService from the container.
	 *
	 * @return object The ObjectService instance
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
