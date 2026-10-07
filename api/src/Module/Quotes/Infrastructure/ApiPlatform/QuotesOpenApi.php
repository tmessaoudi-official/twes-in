<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documents what plain controllers answer, which API Platform does not describe: a quote's PDF (QuotePdfController)
 * and its files' upload and download (QuoteAttachmentsController).
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class QuotesOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $uuid = static fn (string $name, string $description): Parameter => new Parameter($name, 'path', $description, true, schema: ['type' => 'string', 'format' => 'uuid']);
        $paths = $openApi->getPaths();

        $paths->addPath('/api/companies/{companyId}/quotes/{quoteId}/pdf', new PathItem(get: new Operation(
            operationId: 'quotePdf',
            tags: ['Quote'],
            responses: [
                '200' => new Response('The quote as a PDF', new \ArrayObject(['application/pdf' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such quote in this company, no quote.read, or the module is switched off'),
                '503' => new Response('The PDF could not be rendered; try again shortly'),
            ],
            summary: 'A quote as a PDF',
            description: 'A sent quote prints what sending kept; a draft prints across « BROUILLON », a cancelled one across « ANNULÉ ». Rendered on request, never stored.',
            parameters: [$uuid('companyId', 'The company'), $uuid('quoteId', 'The quote')],
        )));

        $collection = '/api/companies/{companyId}/quotes/{quoteId}/attachments';
        $listed = $paths->getPath($collection) ?? new PathItem();
        $paths->addPath($collection, $listed->withPost(new Operation(
            operationId: 'attachQuoteFile',
            tags: ['QuoteAttachment'],
            responses: [
                '201' => new Response('The attachment', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/QuoteAttachment-quote_attachment.read']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such quote in this company, no quote.write, or the module is switched off'),
                '422' => new Response('No file, an empty one, one too large, of a type not accepted, or one too many'),
            ],
            summary: 'Attaches a file to a quote, such as its signed copy',
            description: 'PDF, PNG, JPEG or WebP, read from the bytes; the same limits as an expense\'s files.',
            parameters: [$uuid('companyId', 'The company'), $uuid('quoteId', 'The quote')],
            requestBody: new RequestBody(
                'The file, as a multipart part named file',
                new \ArrayObject(['multipart/form-data' => new MediaType(new \ArrayObject(['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]]))]),
                true,
            ),
        )));

        $paths->addPath($collection.'/{attachmentId}/content', new PathItem(get: new Operation(
            operationId: 'quoteAttachmentContent',
            tags: ['QuoteAttachment'],
            responses: [
                '200' => new Response('The file as it was attached', new \ArrayObject(['application/octet-stream' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such attachment on this quote, no quote.read, or the module is switched off'),
            ],
            summary: 'A file attached to a quote',
            parameters: [$uuid('companyId', 'The company'), $uuid('quoteId', 'The quote'), $uuid('attachmentId', 'The attachment')],
        )));

        return $openApi;
    }
}
