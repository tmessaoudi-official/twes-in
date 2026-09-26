<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\PdfRenderingFailed;
use App\Shared\Infrastructure\Pdf\GotenbergFacturXPdf;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GotenbergFacturXPdfTest extends TestCase
{
    private const string URL = 'http://gotenberg:3000';

    public function testThePdfAndTheXmlGoToTheFacturXRouteAtTheEn16931LevelAsPdfA3b(): void
    {
        $response = new MockResponse('%PDF-1.7 factur-x', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/pdf']]);

        $pdf = new GotenbergFacturXPdf(new MockHttpClient($response), self::URL.'/')->embed('%PDF-1.7 issued', '<rsm:CrossIndustryInvoice/>');

        self::assertSame('%PDF-1.7 factur-x', $pdf);
        self::assertSame(['POST', self::URL.'/forms/pdfengines/factur-x'], [$response->getRequestMethod(), $response->getRequestUrl()]);
        $options = $response->getRequestOptions();
        self::assertIsString($options['body']);
        self::assertMatchesRegularExpression('/Content-Type: application\/pdf\r\n[^\r]*\r\nContent-Disposition: form-data; name="files"; filename="invoice\.pdf"\r\n\r\n%PDF-1\.7 issued\r\n/', $options['body']);
        self::assertMatchesRegularExpression('/Content-Type: application\/xml\r\n[^\r]*\r\nContent-Disposition: form-data; name="facturxXml"; filename="factur-x\.xml"\r\n\r\n<rsm:CrossIndustryInvoice\/>\r\n/', $options['body']);
        foreach (['facturxConformanceLevel' => 'EN 16931', 'pdfa' => 'PDF/A-3b'] as $field => $value) {
            self::assertMatchesRegularExpression('/name="'.$field.'".*?\r\n\r\n'.preg_quote($value, '/').'\r\n/s', $options['body'], $field);
        }
        self::assertIsArray($options['headers']);
        self::assertMatchesRegularExpression('/^Content-Type: multipart\/form-data; boundary=/mi', implode("\n", array_filter($options['headers'], is_string(...))));
    }

    public function testARefusalAnUnreachableServerOrAnythingButAPdfFails(): void
    {
        $failed = [];
        foreach ([
            'refused' => new MockResponse('Invalid form data', ['http_code' => 400]),
            'unreachable' => new MockResponse('', ['error' => 'Connection refused']),
            'not a pdf' => new MockResponse('<html>oops</html>', ['http_code' => 200]),
        ] as $case => $response) {
            try {
                new GotenbergFacturXPdf(new MockHttpClient($response), self::URL)->embed('%PDF-1.7', '<x/>');
                self::fail("$case was taken for a Factur-X PDF");
            } catch (PdfRenderingFailed) {
                $failed[] = $case;
            }
        }

        self::assertSame(['refused', 'unreachable', 'not a pdf'], $failed);
    }
}
