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

/**
 * Documents what plain controllers answer, which API Platform does not describe: an expense's file upload and download
 * (ExpenseAttachmentsController) and the month's TEJ declaration (TejDeclarationController).
 */
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

        $paths->addPath('/api/companies/{companyId}/withholding-declarations/tej/{year}-{month}', new PathItem(get: new Operation(
            operationId: 'tejWithholdingDeclaration',
            tags: ['Expense'],
            responses: [
                '200' => new Response('The DeclarationsRS file (TEJDeclarationRS_v1.0.xsd), named [MATRICULE]-[YYYY]-[MM]-0.xml in Content-Disposition', new \ArrayObject(['application/xml' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such company, no expense.read, the expenses module is switched off, or no such month'),
                '422' => new Response(
                    'No file, and why: a stable code, translated by the screen, with its parameters',
                    new \ArrayObject(['application/json' => new MediaType(new \ArrayObject([
                        'type' => 'object',
                        'required' => ['code', 'params', 'message', 'expenses'],
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => ['not_declared_to_tej', 'company_matricule_missing', 'company_matricule_unreadable', 'nothing_to_declare', 'incomplete_expenses']],
                            'params' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']], 'description' => 'preset; year and month; count.'],
                            'message' => ['type' => 'string'],
                            'expenses' => [
                                'type' => 'array',
                                'description' => 'With incomplete_expenses: each payment of the month that lacks what the platform asks for.',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['expenseId', 'paidOn', 'description', 'reference', 'vendorName', 'problems'],
                                    'properties' => [
                                        'expenseId' => ['type' => 'string', 'format' => 'uuid'],
                                        'paidOn' => ['type' => 'string', 'format' => 'date'],
                                        'description' => ['type' => 'string'],
                                        'reference' => ['type' => ['string', 'null']],
                                        'vendorName' => ['type' => ['string', 'null']],
                                        'problems' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => [
                                            'vendor_missing', 'vendor_matricule_missing', 'vendor_matricule_unreadable', 'vendor_category_unknown', 'vendor_address_missing',
                                            'vendor_email_missing', 'vendor_email_unaccepted', 'vendor_phone_missing', 'operation_code_missing', 'withholding_rate_too_precise', 'tax_rate_too_precise',
                                        ]]],
                                    ],
                                ],
                            ],
                        ],
                    ]))]),
                ),
            ],
            summary: 'The month\'s withholdings for the TEJ platform',
            description: 'Tunisia: one certificate per expense paid in the month with a withholding or a TEJ operation code, every amount in millimes. The file is an initial filing (acte 0). Nothing is written while a payment lacks its operation code or its supplier\'s matricule, address, email or phone: each such payment is listed instead.',
            parameters: [
                $uuid('companyId', 'The company'),
                new Parameter('year', 'path', 'The year the payments were made in', true, schema: ['type' => 'string', 'pattern' => '^20[0-9]{2}$']),
                new Parameter('month', 'path', 'The month, two digits', true, schema: ['type' => 'string', 'pattern' => '^(0[1-9]|1[0-2])$']),
            ],
        )));

        return $openApi;
    }
}
