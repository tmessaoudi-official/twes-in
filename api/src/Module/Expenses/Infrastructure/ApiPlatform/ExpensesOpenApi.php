<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** Documents an expense's file upload and download, plain controllers API Platform does not describe (ExpenseAttachmentsController). */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class ExpensesOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $uuid = static fn (string $name, string $description): Parameter => new Parameter($name, 'path', $description, true, schema: ['type' => 'string', 'format' => 'uuid']);
        $paths = $openApi->getPaths();
        $collection = '/api/companies/{companyId}/expenses/{expenseId}/attachments';

        $listed = $paths->getPath($collection) ?? new PathItem();
        $paths->addPath($collection, $listed->withPost(new Operation(
            operationId: 'attachExpenseFile',
            tags: ['ExpenseAttachment'],
            responses: [
                '201' => new Response('The attachment', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/ExpenseAttachment-expense_attachment.read']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such expense in this company, no expense.write, or the module is switched off'),
                '422' => new Response('No file, an empty one, one too large, of a type not accepted, or one too many'),
            ],
            summary: 'Attaches a file to an expense',
            description: 'PDF, PNG, JPEG or WebP, read from the bytes; at most 10 MiB and 10 files per expense.',
            parameters: [$uuid('companyId', 'The company'), $uuid('expenseId', 'The expense')],
            requestBody: new RequestBody(
                'The file, as a multipart part named file',
                new \ArrayObject(['multipart/form-data' => new MediaType(new \ArrayObject(['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]]))]),
                true,
            ),
        )));

        $paths->addPath($collection.'/{attachmentId}/content', new PathItem(get: new Operation(
            operationId: 'expenseAttachmentContent',
            tags: ['ExpenseAttachment'],
            responses: [
                '200' => new Response('The file as it was attached', new \ArrayObject(['application/octet-stream' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such attachment on this expense, no expense.read, or the module is switched off'),
            ],
            summary: 'A file attached to an expense',
            parameters: [$uuid('companyId', 'The company'), $uuid('expenseId', 'The expense'), $uuid('attachmentId', 'The attachment')],
        )));

        return $openApi;
    }
}
