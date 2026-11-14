<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pdf;

use App\Shared\Application\FacturXNotConformant;
use App\Shared\Application\FacturXPdf;
use App\Shared\Application\PdfRenderingFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gotenberg's remediation to Factur-X (`/forms/pdf/remediate` with the profile `Factur-X`, served by the release compose.yaml
 * pins): it embeds the XML as `factur-x.xml` with the Alternative relationship, writes the Factur-X XMP metadata at the level
 * asked and makes the PDF a PDF/A-3b, then checks its own result. It answers 200 even when violations are left, counted
 * in `Gotenberg-Remediation-Residual`: a file with any left, or with no count, is not conformant and is never handed out.
 * The parts go up unencoded, as the renderer's do.
 */
final readonly class GotenbergFacturXPdf implements FacturXPdf
{
    /** The Factur-X profile the XML is written in (CiiInvoiceXml::GUIDELINE), as the route names it. */
    public const string CONFORMANCE_LEVEL = 'EN 16931';

    /** The name the XML goes up under, which the `xml` field names and the PDF embeds it as. */
    private const string XML_NAME = 'factur-x.xml';

    public function __construct(private HttpClientInterface $httpClient, #[Autowire(param: 'app.pdf.gotenberg_url')] private string $url, private float $timeoutSeconds = 60.0)
    {
    }

    public function embed(string $pdf, string $ciiXml): string
    {
        $form = new FormDataPart([
            ['files' => new DataPart($pdf, 'invoice.pdf', 'application/pdf', '8bit')],
            ['files' => new DataPart($ciiXml, self::XML_NAME, 'application/xml', '8bit')],
            'profile' => 'Factur-X',
            'xml' => self::XML_NAME,
            'level' => self::CONFORMANCE_LEVEL,
        ]);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->url, '/').'/forms/pdf/remediate', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToString(),
                'timeout' => $this->timeoutSeconds,
            ]);
            $answer = $response->getContent();
            $residual = $response->getHeaders()['gotenberg-remediation-residual'][0] ?? null;
        } catch (ExceptionInterface $failure) {
            throw new PdfRenderingFailed(\sprintf('Gotenberg did not write the Factur-X PDF: %s', $failure->getMessage()), 0, $failure);
        }
        if (!str_starts_with($answer, '%PDF-')) {
            throw new PdfRenderingFailed('Gotenberg answered something other than a PDF.');
        }
        if (null === $residual || 1 !== preg_match('/^\d+$/', $residual)) {
            throw new FacturXNotConformant(null);
        }
        if (0 !== (int) $residual) {
            throw new FacturXNotConformant((int) $residual);
        }

        return $answer;
    }
}
