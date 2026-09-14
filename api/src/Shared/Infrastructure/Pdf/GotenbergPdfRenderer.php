<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pdf;

use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gotenberg's Chromium route: the page goes up as `index.html` in a multipart form and the PDF comes back, on A4
 * (8.27 x 11.7 inches) with its backgrounds printed. Gotenberg reads a part's bytes as they are, so the page is sent
 * unencoded.
 */
final readonly class GotenbergPdfRenderer implements PdfRenderer
{
    public function __construct(private HttpClientInterface $httpClient, private string $url, private float $timeoutSeconds = 30.0)
    {
    }

    public function render(string $html): string
    {
        $form = new FormDataPart([
            'files' => new DataPart($html, 'index.html', 'text/html', '8bit'),
            'paperWidth' => '8.27',
            'paperHeight' => '11.7',
            'marginTop' => '0.4',
            'marginBottom' => '0.4',
            'marginLeft' => '0.4',
            'marginRight' => '0.4',
            'printBackground' => 'true',
        ]);

        try {
            $pdf = $this->httpClient->request('POST', rtrim($this->url, '/').'/forms/chromium/convert/html', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToString(),
                'timeout' => $this->timeoutSeconds,
            ])->getContent();
        } catch (ExceptionInterface $failure) {
            throw new PdfRenderingFailed(\sprintf('Gotenberg did not render the PDF: %s', $failure->getMessage()), 0, $failure);
        }
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new PdfRenderingFailed('Gotenberg answered something other than a PDF.');
        }

        return $pdf;
    }
}
