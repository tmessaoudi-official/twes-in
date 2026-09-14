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
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** Documents the PDF download, a plain controller API Platform does not describe (InvoicePdfController). */
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

        return $openApi;
    }
}
