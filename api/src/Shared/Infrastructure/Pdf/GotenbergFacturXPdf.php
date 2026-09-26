<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pdf;

use App\Shared\Application\FacturXPdf;
use App\Shared\Application\PdfRenderingFailed;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gotenberg's own Factur-X route (`/forms/pdfengines/factur-x`, in the pinned 8.37.0): it embeds the XML as
 * `factur-x.xml` with the Alternative relationship, writes the Factur-X XMP metadata at the conformance level asked and
 * converts the PDF to PDF/A-3b. The parts go up unencoded, as the renderer's do.
 */
final readonly class GotenbergFacturXPdf implements FacturXPdf
{
    /** The Factur-X profile the XML is written in (CiiInvoiceXml::GUIDELINE), as the route names it. */
    public const string CONFORMANCE_LEVEL = 'EN 16931';

    public function __construct(private HttpClientInterface $httpClient, private string $url, private float $timeoutSeconds = 60.0)
    {
    }

    public function embed(string $pdf, string $ciiXml): string
    {
        $form = new FormDataPart([
            'files' => new DataPart($pdf, 'invoice.pdf', 'application/pdf', '8bit'),
            'facturxXml' => new DataPart($ciiXml, 'factur-x.xml', 'application/xml', '8bit'),
            'facturxConformanceLevel' => self::CONFORMANCE_LEVEL,
            'pdfa' => 'PDF/A-3b',
        ]);

        try {
            $answer = $this->httpClient->request('POST', rtrim($this->url, '/').'/forms/pdfengines/factur-x', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToString(),
                'timeout' => $this->timeoutSeconds,
            ])->getContent();
        } catch (ExceptionInterface $failure) {
            throw new PdfRenderingFailed(\sprintf('Gotenberg did not write the Factur-X PDF: %s', $failure->getMessage()), 0, $failure);
        }
        if (!str_starts_with($answer, '%PDF-')) {
            throw new PdfRenderingFailed('Gotenberg answered something other than a PDF.');
        }

        return $answer;
    }
}
