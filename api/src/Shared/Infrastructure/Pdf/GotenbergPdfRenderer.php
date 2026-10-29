<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pdf;

use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use App\Shared\Domain\ValueObject\PrintedPage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gotenberg's Chromium routes: the page goes up as `index.html` in a multipart form and the PDF comes back, on the sheet
 * every document prints on ({@see PrintedPage}: A4, 8.27 x 11.7 inches) with its backgrounds printed; or its screenshot of the first sheet, A4 at 96 dots an inch in
 * print media, the margins the PDF takes laid inside it, since a screenshot has no page margins of its own. Gotenberg
 * reads a part's bytes as they are, so the page is sent unencoded.
 */
final readonly class GotenbergPdfRenderer implements PdfRenderer
{
    /** The page margins, in inches, on every side: the templates leave them to this (PdfTemplateMarginsTest). */
    private const string MARGIN = PrintedPage::MARGIN_IN.'';

    /** A4, in inches, as the fit of a watermark's word reads it: one sheet for the two. */
    private const string PAPER_WIDTH = PrintedPage::WIDTH_IN.'';
    private const string PAPER_HEIGHT = PrintedPage::HEIGHT_IN.'';

    /** The first sheet's picture is the sheet at 96 dots an inch, the screen's measure. */
    private const int DOTS = 96;

    public function __construct(private HttpClientInterface $httpClient, #[Autowire(param: 'app.pdf.gotenberg_url')] private string $url, private float $timeoutSeconds = 30.0)
    {
    }

    /**
     * A page may carry its own header and footer, each as a `<template data-pdf="header">` or `"footer"`: they leave the
     * page and go up as `header.html` and `footer.html`, documents of their own that Chromium prints in the top and
     * bottom margins of every sheet, beside the content and never over it.
     */
    public function render(string $html): string
    {
        $margins = [];
        $page = preg_replace_callback('#<template data-pdf="(header|footer)">(.*?)</template>\s*#s', static function (array $match) use (&$margins): string {
            if (isset($margins[$match[1]])) {
                throw new \LogicException(\sprintf('A page carries one %s at most.', $match[1]));
            }
            $margins[$match[1]] = '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$match[2].'</body></html>';

            return '';
        }, $html) ?? throw new PdfRenderingFailed('The page could not be read for its header and footer.');
        $files = [['files' => new DataPart($page, 'index.html', 'text/html', '8bit')]];
        foreach ($margins as $place => $document) {
            $files[] = ['files' => new DataPart($document, $place.'.html', 'text/html', '8bit')];
        }
        $pdf = $this->send('chromium/convert/html', [
            ...$files,
            'paperWidth' => self::PAPER_WIDTH,
            'paperHeight' => self::PAPER_HEIGHT,
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
            'width' => (string) (int) round(PrintedPage::WIDTH_IN * self::DOTS),
            'height' => (string) (int) round(PrintedPage::HEIGHT_IN * self::DOTS),
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
     * Gotenberg's PDF engines merge the files in the order of their names, whatever order they arrive in, so each is
     * named by its place, padded so that the tenth does not sort before the second.
     */
    public function merge(array $pdfs): string
    {
        if ([] === $pdfs) {
            throw new \InvalidArgumentException('Nothing to merge.');
        }
        if (1 === \count($pdfs)) {
            return $pdfs[0];
        }
        $width = \strlen((string) \count($pdfs));
        $parts = [];
        foreach ($pdfs as $index => $pdf) {
            $parts[] = ['files' => new DataPart($pdf, str_pad((string) ($index + 1), max(3, $width), '0', \STR_PAD_LEFT).'.pdf', 'application/pdf', '8bit')];
        }
        $merged = $this->send('pdfengines/merge', $parts);
        if (!str_starts_with($merged, '%PDF-')) {
            throw new PdfRenderingFailed('Gotenberg answered something other than a PDF.');
        }

        return $merged;
    }

    /**
     * @param 'screenshot'                   $route
     * @param array<string, string|DataPart> $fields
     */
    private function post(string $route, array $fields): string
    {
        return $this->send('chromium/'.$route.'/html', $fields);
    }

    /** @param array<int|string, string|DataPart|array<string, DataPart>> $fields */
    private function send(string $route, array $fields): string
    {
        $form = new FormDataPart($fields);
        try {
            return $this->httpClient->request('POST', rtrim($this->url, '/').'/forms/'.$route, [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToString(),
                'timeout' => $this->timeoutSeconds,
            ])->getContent();
        } catch (ExceptionInterface $failure) {
            throw new PdfRenderingFailed(\sprintf('Gotenberg did not render the page: %s', $failure->getMessage()), 0, $failure);
        }
    }
}
