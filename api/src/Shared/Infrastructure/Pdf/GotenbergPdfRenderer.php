<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pdf;

use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gotenberg's Chromium routes: the page goes up as `index.html` in a multipart form and the PDF comes back, on A4
 * (8.27 x 11.7 inches) with its backgrounds printed; or its screenshot of the first sheet, A4 at 96 dots an inch in
 * print media, the margins the PDF takes laid inside it, since a screenshot has no page margins of its own. Gotenberg
 * reads a part's bytes as they are, so the page is sent unencoded.
 */
final readonly class GotenbergPdfRenderer implements PdfRenderer
{
    /** The page margins, in inches, on every side: the templates leave them to this (PdfTemplateMarginsTest). */
    private const string MARGIN = '0.4';

    public function __construct(private HttpClientInterface $httpClient, #[Autowire(param: 'app.pdf.gotenberg_url')] private string $url, private float $timeoutSeconds = 30.0)
    {
    }

    public function render(string $html): string
    {
        $pdf = $this->post('convert', [
            'files' => new DataPart($html, 'index.html', 'text/html', '8bit'),
            'paperWidth' => '8.27',
            'paperHeight' => '11.7',
            'marginTop' => self::MARGIN,
            'marginBottom' => self::MARGIN,
            'marginLeft' => self::MARGIN,
            'marginRight' => self::MARGIN,
            'printBackground' => 'true',
        ]);
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new PdfRenderingFailed('Gotenberg answered something other than a PDF.');
        }

        return $pdf;
    }

    public function firstPage(string $html): string
    {
        $margins = '<style>html { padding: '.self::MARGIN.'in; }</style>';
        $page = str_contains($html, '<head>') ? preg_replace('/<head>/', '<head>'.$margins, $html, 1) ?? $html : $margins.$html;
        $picture = $this->post('screenshot', [
            'files' => new DataPart($page, 'index.html', 'text/html', '8bit'),
            'width' => '794',
            'height' => '1123',
            'clip' => 'true',
            'format' => 'png',
            'emulatedMediaType' => 'print',
        ]);
        if (!str_starts_with($picture, "\x89PNG")) {
            throw new PdfRenderingFailed('Gotenberg answered something other than a PNG picture.');
        }

        return $picture;
    }

    /**
     * @param 'convert'|'screenshot'         $route
     * @param array<string, string|DataPart> $fields
     */
    private function post(string $route, array $fields): string
    {
        $form = new FormDataPart($fields);
        try {
            return $this->httpClient->request('POST', rtrim($this->url, '/').'/forms/chromium/'.$route.'/html', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToString(),
                'timeout' => $this->timeoutSeconds,
            ])->getContent();
        } catch (ExceptionInterface $failure) {
            throw new PdfRenderingFailed(\sprintf('Gotenberg did not render the page: %s', $failure->getMessage()), 0, $failure);
        }
    }
}
