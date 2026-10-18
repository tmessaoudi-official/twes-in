<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\PdfRenderingFailed;
use App\Shared\Infrastructure\Pdf\GotenbergPdfRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GotenbergPdfRendererTest extends TestCase
{
    private const string URL = 'http://gotenberg:3000';

    public function testTheHtmlIsSentAsIndexHtmlOnA4AndThePdfComesBack(): void
    {
        $response = new MockResponse('%PDF-1.7 rendered', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/pdf']]);

        $pdf = new GotenbergPdfRenderer(new MockHttpClient($response), self::URL.'/')->render('<h1>BL-2026-00001</h1>');

        self::assertSame('%PDF-1.7 rendered', $pdf);
        self::assertSame(['POST', self::URL.'/forms/chromium/convert/html'], [$response->getRequestMethod(), $response->getRequestUrl()]);
        $options = $response->getRequestOptions();
        self::assertIsString($options['body']);
        self::assertMatchesRegularExpression('/name="files"; filename="index\.html".*?\r\n\r\n<h1>BL-2026-00001<\/h1>\r\n/s', $options['body']);
        foreach (['paperWidth' => '8.27', 'paperHeight' => '11.7', 'printBackground' => 'true'] as $field => $value) {
            self::assertMatchesRegularExpression('/name="'.$field.'".*?\r\n\r\n'.preg_quote($value, '/').'\r\n/s', $options['body'], $field);
        }
        self::assertIsArray($options['headers']);
        self::assertMatchesRegularExpression('/^Content-Type: multipart\/form-data; boundary=/mi', implode("\n", array_filter($options['headers'], is_string(...))));
    }

    public function testAPagesOwnHeaderAndFooterLeaveItAndGoAsHeaderAndFooterDocuments(): void
    {
        $response = new MockResponse('%PDF-1.7 rendered', ['http_code' => 200]);
        $page = '<html><head><title>x</title></head><body>'
            ."<template data-pdf=\"header\"><div>DEMO top</div></template>\n  "
            .'<template data-pdf="footer"><div>DEMO bottom</div></template>'
            .'<h1>FA-1</h1></body></html>';

        new GotenbergPdfRenderer(new MockHttpClient($response), self::URL)->render($page);

        $options = $response->getRequestOptions();
        self::assertIsString($options['body']);
        preg_match_all('/name="files"; filename="([^"]+)".*?\r\n\r\n(.*?)\r\n--/s', $options['body'], $parts);
        self::assertSame([
            'index.html' => '<html><head><title>x</title></head><body><h1>FA-1</h1></body></html>',
            'header.html' => '<!doctype html><html><head><meta charset="utf-8"></head><body><div>DEMO top</div></body></html>',
            'footer.html' => '<!doctype html><html><head><meta charset="utf-8"></head><body><div>DEMO bottom</div></body></html>',
        ], array_combine($parts[1], $parts[2]), 'the page keeps nothing of them, and each goes as a document of its own');
        foreach (['marginTop' => '0.4', 'marginBottom' => '0.4', 'printBackground' => 'true'] as $field => $value) {
            self::assertMatchesRegularExpression('/name="'.$field.'".*?\r\n\r\n'.preg_quote($value, '/').'\r\n/s', $options['body'], $field);
        }
    }

    public function testAPageCarriesOneHeaderAndOneFooterAtMost(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('one header at most');

        new GotenbergPdfRenderer(new MockHttpClient(new MockResponse('%PDF-1.7', ['http_code' => 200])), self::URL)
            ->render('<template data-pdf="header">a</template><template data-pdf="header">b</template>');
    }

    public function testTheFirstPageComesBackAsAPictureAtTheSizeAndMarginsOfThePrintedPage(): void
    {
        $png = "\x89PNG\r\n\x1a\n picture";
        $response = new MockResponse($png, ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);

        $picture = new GotenbergPdfRenderer(new MockHttpClient($response), self::URL)->firstPage('<html><head><title>x</title></head><body><h1>FA-1</h1></body></html>');

        self::assertSame($png, $picture);
        self::assertSame(['POST', self::URL.'/forms/chromium/screenshot/html'], [$response->getRequestMethod(), $response->getRequestUrl()]);
        $options = $response->getRequestOptions();
        self::assertIsString($options['body']);
        // A4 at 96 dots an inch, printed as a page is printed, inside the PDF's own margins.
        foreach (['width' => '794', 'height' => '1123', 'format' => 'png', 'emulatedMediaType' => 'print'] as $field => $value) {
            self::assertMatchesRegularExpression('/name="'.$field.'".*?\r\n\r\n'.preg_quote($value, '/').'\r\n/s', $options['body'], $field);
        }
        self::assertStringContainsString('<head><style>html { padding: 0.4in; }</style><title>x</title>', $options['body']);

        $this->expectException(PdfRenderingFailed::class);
        new GotenbergPdfRenderer(new MockHttpClient(new MockResponse('%PDF-1.7', ['http_code' => 200])), self::URL)->firstPage('<p>x</p>');
    }

    public function testSeveralPdfsAreMergedInTheOrderGivenWhichGotenbergReadsFromTheirNames(): void
    {
        $response = new MockResponse('%PDF-1.4 merged', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/pdf']]);
        $pdfs = array_map(static fn (int $page): string => "%PDF-1.7 page $page", range(1, 11));

        $merged = new GotenbergPdfRenderer(new MockHttpClient($response), self::URL)->merge($pdfs);

        self::assertSame('%PDF-1.4 merged', $merged);
        self::assertSame(['POST', self::URL.'/forms/pdfengines/merge'], [$response->getRequestMethod(), $response->getRequestUrl()]);
        $options = $response->getRequestOptions();
        self::assertIsString($options['body']);
        preg_match_all('/name="files"; filename="([^"]+)".*?\r\n\r\n(%PDF-1\.7 page \d+)\r\n/s', $options['body'], $parts);
        // Gotenberg merges by file name, whatever order the parts arrive in: the eleventh is not put between the first two.
        self::assertSame(['001.pdf', '002.pdf', '003.pdf', '004.pdf', '005.pdf', '006.pdf', '007.pdf', '008.pdf', '009.pdf', '010.pdf', '011.pdf'], $parts[1]);
        self::assertSame($pdfs, $parts[2]);
    }

    public function testOnePdfIsItsOwnMergeAndNoneIsNoPdf(): void
    {
        $unused = new MockHttpClient(static fn (): never => throw new \LogicException('Gotenberg was asked.'));

        self::assertSame('%PDF-1.7 alone', new GotenbergPdfRenderer($unused, self::URL)->merge(['%PDF-1.7 alone']));

        $this->expectException(\InvalidArgumentException::class);
        new GotenbergPdfRenderer($unused, self::URL)->merge([]);
    }

    public function testAMergeThatComesBackAsAnythingButAPdfFails(): void
    {
        $this->expectException(PdfRenderingFailed::class);
        new GotenbergPdfRenderer(new MockHttpClient(new MockResponse('{"error":"x"}', ['http_code' => 200])), self::URL)->merge(['%PDF-1', '%PDF-2']);
    }

    public function testARefusalAnUnreachableServerOrAnythingButAPdfFailsTheRendering(): void
    {
        $failed = [];
        foreach ([
            'refused' => new MockResponse('Bad Request', ['http_code' => 400]),
            'unreachable' => new MockResponse('', ['error' => 'Connection refused']),
            'not a pdf' => new MockResponse('<html>oops</html>', ['http_code' => 200]),
        ] as $case => $response) {
            try {
                new GotenbergPdfRenderer(new MockHttpClient($response), self::URL)->render('<p>x</p>');
                self::fail("$case rendered");
            } catch (PdfRenderingFailed) {
                $failed[] = $case;
            }
        }

        self::assertSame(['refused', 'unreachable', 'not a pdf'], $failed);
    }
}
