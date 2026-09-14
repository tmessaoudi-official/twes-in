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
