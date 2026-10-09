<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Module\Invoices\Application\FacturX\FacturXRefused;
use App\Shared\Domain\DocumentDesign;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** Documents the PDF and Factur-X downloads, plain controllers API Platform does not describe (InvoicePdfController, FacturXController). */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class InvoicesOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $uuid = static fn (string $name, string $description): Parameter => new Parameter($name, 'path', $description, true, schema: ['type' => 'string', 'format' => 'uuid']);

        $openApi->getPaths()->addPath('/api/companies/{companyId}/invoice-design-preview', new PathItem(get: new Operation(
            operationId: 'invoiceDesignPreview',
            tags: ['Invoice'],
            responses: [
                '200' => new Response(
                    'The company\'s latest invoice in the design, its first page as a picture, across a preview watermark',
                    new \ArrayObject(['image/png' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))]),
                ),
                '401' => new Response('Not signed in'),
                '403' => new Response('company.settings without invoice.read'),
                '404' => new Response('No such company, no company.settings, the module is switched off, or `nothing_to_preview`: the company has no invoice yet'),
                '422' => new Response('A layout or an accent that is not one'),
                '503' => new Response('The renderer could not produce the picture'),
            ],
            summary: 'A document design tried on the company\'s latest invoice',
            parameters: [
                $uuid('companyId', 'The company'),
                new Parameter('layout', 'query', 'classic, modern or compact; the company\'s own when left out', false, schema: ['type' => 'string', 'enum' => ['classic', 'modern', 'compact']]),
                new Parameter('accent', 'query', 'A colour written #rrggbb; the company\'s own when left out', false, schema: ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$']),
                new Parameter('logoWidth', 'query', 'The logo\'s printed width, in millimetres; the company\'s own when left out', false, schema: ['type' => 'integer', 'minimum' => DocumentDesign::LOGO_WIDTH_MM_MIN, 'maximum' => DocumentDesign::LOGO_WIDTH_MM_MAX]),
                new Parameter('logoHeight', 'query', 'The logo\'s printed height, in millimetres; the company\'s own when left out', false, schema: ['type' => 'integer', 'minimum' => DocumentDesign::LOGO_HEIGHT_MM_MIN, 'maximum' => DocumentDesign::LOGO_HEIGHT_MM_MAX]),
                new Parameter('logoProportions', 'query', 'keep: the logo fits that size in its own proportions; free: it is stretched to it; the company\'s own when left out', false, schema: ['type' => 'string', 'enum' => ['keep', 'free']]),
            ],
        )));

        $openApi->getPaths()->addPath('/api/companies/{companyId}/invoices/{invoiceId}/pdf', new PathItem(get: new Operation(
            operationId: 'invoicePdf',
            tags: ['Invoice'],
            responses: [
                '200' => new Response(
                    'The PDF: an issued document as it was issued; a draft or a cancelled draft rendered across a watermark',
                    new \ArrayObject(['application/pdf' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))]),
                ),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such invoice in this company, no invoice.read, or the module is switched off'),
                '503' => new Response('The renderer could not produce the PDF'),
            ],
            summary: 'An invoice or a credit note as a PDF',
            parameters: [$uuid('companyId', 'The company'), $uuid('invoiceId', 'The invoice or credit note')],
        )));

        $openApi->getPaths()->addPath('/api/companies/{companyId}/invoices/{invoiceId}/pdf/{kind}', new PathItem(get: new Operation(
            operationId: 'invoicePdfCopy',
            tags: ['Invoice'],
            responses: [
                '200' => new Response(
                    'A duplicate (the document as issued, marked DUPLICATA with the day it was reprinted) or an up-to-date copy (marked COPIE, stamped paid or partly paid), printed on request and never stored',
                    new \ArrayObject(['application/pdf' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))]),
                ),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such invoice in this company, a kind other than duplicate or current, no invoice.read, or the module is switched off'),
                '409' => new Response('A draft or a cancelled draft has no original to copy'),
                '503' => new Response('The renderer could not produce the PDF'),
            ],
            summary: 'A duplicate or an up-to-date copy of an issued invoice or credit note as a PDF',
            parameters: [$uuid('companyId', 'The company'), $uuid('invoiceId', 'The invoice or credit note'), new Parameter('kind', 'path', 'duplicate or current', true, schema: ['type' => 'string', 'enum' => ['duplicate', 'current']])],
        )));

        $openApi->getPaths()->addPath('/api/companies/{companyId}/customers/{customerId}/statement/pdf', new PathItem(get: new Operation(
            operationId: 'customerStatementPdf',
            tags: ['Customer'],
            responses: [
                '200' => new Response(
                    'The statement of account as a PDF, worked out on the day it is asked for and never stored',
                    new \ArrayObject(['application/pdf' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))]),
                ),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such customer in this company, no customer.read or invoice.read, or the module is switched off'),
                '422' => new Response('A day not written YYYY-MM-DD, or a period ending before it starts'),
                '503' => new Response('The renderer could not produce the PDF'),
            ],
            summary: 'A customer\'s statement of account as a PDF',
            parameters: [
                $uuid('companyId', 'The company'),
                $uuid('customerId', 'The customer'),
                new Parameter('from', 'query', 'First day of the period, YYYY-MM-DD; the company year to date when absent', false, schema: ['type' => 'string', 'format' => 'date']),
                new Parameter('to', 'query', 'Last day of the period, YYYY-MM-DD; today when absent', false, schema: ['type' => 'string', 'format' => 'date']),
            ],
        )));

        $refused = new \ArrayObject(['application/json' => new MediaType(new \ArrayObject([
            'type' => 'object',
            'required' => ['code', 'params', 'message', 'gaps'],
            'properties' => [
                'code' => ['type' => 'string', 'enum' => FacturXRefused::REASONS],
                'params' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']], 'description' => 'status; preset; count.'],
                'message' => ['type' => 'string'],
                'gaps' => [
                    'type' => 'array',
                    'description' => 'With incomplete_document: each datum EN 16931 asks for and the document or its company lacks.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['code', 'params'],
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => FacturXRefused::GAPS],
                            'params' => ['type' => 'object', 'description' => 'line, code, codes, regime, rate, missing (the address fields left empty).'],
                        ],
                    ],
                ],
            ],
        ]))]);
        $facturX = static fn (string $operationId, string $type, string $what, string $summary): PathItem => new PathItem(get: new Operation(
            operationId: $operationId,
            tags: ['Invoice'],
            responses: [
                '200' => new Response($what, new \ArrayObject([$type => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such invoice in this company, no invoice.read, or the module is switched off'),
                '409' => new Response('A draft or a cancelled draft: only an issued document is written as Factur-X', $refused),
                '422' => new Response('No file, and why: a stable code and, for an incomplete document, every gap, translated by the screen', $refused),
                '503' => new Response('The PDF engine could not write the file'),
            ],
            summary: $summary,
            description: 'France: the UN/CEFACT Cross Industry Invoice (D16B) of the Factur-X EN 16931 profile, written from what issuing fixed; a credit note is type 381 and names the invoice it corrects. A company on another preset is refused.',
            parameters: [$uuid('companyId', 'The company'), $uuid('invoiceId', 'The issued invoice or credit note')],
        ));
        $openApi->getPaths()->addPath('/api/companies/{companyId}/invoices/{invoiceId}/factur-x.xml', $facturX('invoiceFacturXXml', 'application/xml', 'The Cross Industry Invoice, named after the document\'s number in Content-Disposition', 'An issued invoice or credit note as its Factur-X XML'));
        $openApi->getPaths()->addPath('/api/companies/{companyId}/invoices/{invoiceId}/factur-x.pdf', $facturX('invoiceFacturXPdf', 'application/pdf', 'The PDF the document was issued with, as PDF/A-3b, embedding its Cross Industry Invoice as factur-x.xml with the Factur-X XMP metadata', 'An issued invoice or credit note as its Factur-X PDF'));

        return $openApi;
    }
}
