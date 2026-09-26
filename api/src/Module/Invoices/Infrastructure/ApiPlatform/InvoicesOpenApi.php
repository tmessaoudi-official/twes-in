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
