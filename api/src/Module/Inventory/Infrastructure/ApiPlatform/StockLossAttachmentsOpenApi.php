<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Files\Application\AttachmentRefused;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** Documents what StockLossAttachmentsController answers, which API Platform does not describe: a loss's files going up and coming back. */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class StockLossAttachmentsOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $uuid = static fn (string $name, string $description): Parameter => new Parameter($name, 'path', $description, true, schema: ['type' => 'string', 'format' => 'uuid']);
        $paths = $openApi->getPaths();

        $collection = '/api/companies/{companyId}/stock-movements/{movementId}/attachments';
        $listed = $paths->getPath($collection) ?? new PathItem();
        $paths->addPath($collection, $listed->withPost(new Operation(
            operationId: 'attachStockLossFile',
            tags: ['StockLossAttachment'],
            responses: [
                '201' => new Response('The attachment', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/StockLossAttachment-stock_loss_attachment.read']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such loss in this company (a movement that is not a loss has no files), no stock.write, or the module is switched off'),
                '422' => new Response('No file, an empty one, one too large, of a type not accepted, or one too many'),
            ],
            summary: 'Attaches a file to a loss, such as a photo of what broke or the complaint filed for a theft',
            description: 'PDF, PNG, JPEG or WebP, read from the bytes; the same limits as an expense\'s files.',
            parameters: [$uuid('companyId', 'The company'), $uuid('movementId', 'The loss')],
            requestBody: new RequestBody(
                'The file, as a multipart part named file',
                new \ArrayObject(['multipart/form-data' => new MediaType(new \ArrayObject(['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]]))]),
                true,
            ),
        )));

        $paths->addPath($collection.'/{attachmentId}/restore', new PathItem(post: new Operation(
            operationId: 'restoreStockLossFile',
            tags: ['StockLossAttachment'],
            responses: [
                '204' => new Response('Back where it was'),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such file on this loss, no stock.write, or the module is switched off'),
                '422' => new Response(
                    'Not put back, and why: a stable code, translated by the screen, with its parameters',
                    new \ArrayObject(['application/json' => new MediaType(new \ArrayObject([
                        'type' => 'object',
                        'required' => ['code', 'params', 'message'],
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => [AttachmentRefused::TOO_MANY_FILES]],
                            'params' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'too_many_files: max.'],
                            'message' => ['type' => 'string'],
                        ],
                    ]))]),
                ),
            ],
            summary: 'Puts back a file taken off, where it was',
            description: 'What « Annuler » sends after a file is taken off. A file still there is left as it is.',
            parameters: [$uuid('companyId', 'The company'), $uuid('movementId', 'The loss'), $uuid('attachmentId', 'The attachment')],
        )));

        $paths->addPath($collection.'/{attachmentId}/content', new PathItem(get: new Operation(
            operationId: 'stockLossAttachmentContent',
            tags: ['StockLossAttachment'],
            responses: [
                '200' => new Response('The file as it was attached', new \ArrayObject(['application/octet-stream' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such file on this loss, no stock.read, or the module is switched off'),
            ],
            summary: 'A file attached to a loss',
            parameters: [$uuid('companyId', 'The company'), $uuid('movementId', 'The loss'), $uuid('attachmentId', 'The attachment')],
        )));

        return $openApi;
    }
}
