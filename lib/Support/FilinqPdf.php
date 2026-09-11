<?php

/**
 * FilinqPdf — render HTML to PDF through filinq, whatever it is called here.
 *
 * WHY THIS IS A CLASS AND NOT TWO COPIES OF FOUR LINES. Two services wanted a
 * PDF from filinq and each resolved it inline:
 * `FleetAppId::getService($this->container, 'filinq', 'Service\PdfService')`,
 * wrapped in its own try/catch that logs and falls back to markdown. The
 * duplication was small and the cost was not: each copy pulled
 * `ContainerInterface` and `FleetAppId` into a service that needed neither for
 * anything else, and `MinutesDocumentService` crossed phpmd's coupling limit
 * the day the resolver was added.
 *
 * Folding the lookup here removes two collaborators from each caller and adds
 * one. It also means the fallback is decided in a single place: filinq absent,
 * filinq present but unhappy, and filinq returning nothing all answer null, and
 * the caller writes markdown instead. Three different failures, one behaviour,
 * stated once.
 *
 * ⚠️ THE APP ID AND THE NAMESPACE BOTH MOVED. filinq shipped as `docudesk`
 * before the fleet rename, and a binding pinned to either name alone resolves
 * to nothing on an instance running the other. `FleetAppId` asks under every
 * name the app has had — that is the whole reason it exists, and it is why this
 * class delegates rather than calling the container directly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Decidiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Support;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves filinq's PDF service and renders through it.
 *
 * NOT `final`, deliberately. This is an injected collaborator, and the two
 * services that take it have to be testable without a container: PHPUnit
 * refuses to double a final class, so `final` here would force every caller's
 * test to reach back through `ContainerInterface` and re-test the lookup this
 * class exists to own. `FleetAppId` next door IS final, because it is a static
 * utility nobody injects.
 *
 * @spec exclude infrastructure utility with no feature requirement of its own; it is
 *   exercised through the documents that call it
 */
class FilinqPdf
{

    /**
     * Wire collaborators.
     *
     * @param ContainerInterface $container Resolves filinq's service, whatever it is called.
     * @param LoggerInterface    $logger    Records why a PDF was not produced.
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Render HTML to a PDF, or answer null so the caller can fall back.
     *
     * NULL IS A NORMAL ANSWER, not a fault. filinq is an optional app: an
     * instance without it is correctly configured, and a document that comes
     * out as markdown instead of PDF is a lesser outcome rather than an error.
     * The log line says which of the three reasons applied, because "no PDF"
     * with no cause is what made the retired-namespace binding invisible for a
     * fortnight.
     *
     * @param string $html    The document body.
     * @param string $title   PDF metadata title.
     * @param string $context What was being rendered, for the log line.
     *
     * @return string|null The PDF bytes, or null when filinq cannot supply one.
     *
     * @spec exclude infrastructure utility with no feature requirement of its
     *       own; the documents it renders are specified by their callers.
     */
    public function fromHtml(string $html, string $title, string $context): ?string {
        try {
            $pdfService = FleetAppId::getService($this->container, 'filinq', 'Service\PdfService');
            if ($pdfService === null) {
                $this->logger->info(
                    'Decidiq: filinq is not installed, falling back to markdown for ' . $context
                );

                return null;
            }

            $pdf = $pdfService->generatePdfFromHtml($html, ['title' => $title]);
            if (is_string($pdf) === true && $pdf !== '') {
                return $pdf;
            }

            $this->logger->info(
                'Decidiq: filinq returned no PDF, falling back to markdown for ' . $context
            );
        } catch (Throwable $e) {
            $this->logger->info(
                'Decidiq: filinq PDF pathway unavailable, falling back to markdown for ' . $context,
                ['error' => $e->getMessage()]
            );
        }//end try

        return null;

    }//end fromHtml()

}//end class
