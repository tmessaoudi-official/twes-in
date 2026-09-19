<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\ImportExport\Application\UnreadableImport;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documents the import and its template, two plain controllers API Platform does not describe (ImportController, which
 * reads a multipart upload, and ImportTemplateController, which answers bytes). The guide beside them is a resource.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class ImportOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $company = new Parameter('companyId', 'path', 'The company', true, schema: ['type' => 'string', 'format' => 'uuid']);
        $subject = new Parameter('subject', 'path', 'What is imported, such as `customers`', true, schema: ['type' => 'string', 'pattern' => '^[a-z-]+$']);
        $json = static fn (array $schema): \ArrayObject => new \ArrayObject(['application/json' => new MediaType(new \ArrayObject($schema))]);
        $lines = ['type' => 'array', 'items' => ['type' => 'integer']];
        $report = [
            'type' => 'object',
            'required' => ['committed', 'created', 'updated', 'rejected'],
            'properties' => [
                'committed' => ['type' => 'boolean', 'description' => 'Whether the file was stored: never for a preview, nor while a row is rejected.'],
                'created' => $lines + ['description' => 'The file’s own line numbers, empty lines counted.'],
                'updated' => $lines,
                'rejected' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['line', 'column', 'code', 'params', 'message'],
                    'properties' => [
                        'line' => ['type' => 'integer'],
                        'column' => ['type' => ['string', 'null'], 'description' => 'The column at fault, when there is one.'],
                        'code' => ['type' => 'string', 'description' => 'A stable reason, translated by the screen as `import.rejections.<code>`.'],
                        'params' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']], 'description' => 'What the translation names, such as the group the file gave.'],
                        'message' => ['type' => 'string', 'description' => 'The reason in English, for a code the screen does not know yet.'],
                    ],
                ]],
            ],
        ];
        $refused = [
            'type' => 'object',
            'required' => ['error'],
            'properties' => [
                'error' => ['type' => 'string', 'enum' => ['invalid_request', UnreadableImport::UNREADABLE, UnreadableImport::EMPTY, UnreadableImport::UNKNOWN_COLUMNS, UnreadableImport::DUPLICATE_COLUMNS, UnreadableImport::MISSING_COLUMNS, UnreadableImport::TOO_MANY_ROWS]],
                'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The columns the reason names.'],
                'limit' => ['type' => ['integer', 'null'], 'description' => 'The row cap, for `too_many_rows`.'],
            ],
        ];

        $import = $openApi->getPaths()->getPath('/api/companies/{companyId}/imports/{subject}') ?? new PathItem();
        $openApi->getPaths()->addPath('/api/companies/{companyId}/imports/{subject}', $import->withPost(new Operation(
            operationId: 'importFile',
            tags: ['ImportGuide'],
            responses: [
                '200' => new Response('A preview, or a file stored whole', $json($report)),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such subject, its module switched off, or no permission to write it'),
                '422' => new Response('The file refused whole, or an import stopped by rejected rows, in which case nothing was stored', $json(['oneOf' => [$report, $refused]])),
            ],
            summary: 'Import a CSV or .xlsx file, or preview what it would do',
            parameters: [$company, $subject],
            requestBody: new RequestBody('The file and how to read it', new \ArrayObject(['multipart/form-data' => new MediaType(new \ArrayObject([
                'type' => 'object',
                'required' => ['file'],
                'properties' => [
                    'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'A .csv or .xlsx file, its first non-empty row the column keys.'],
                    'mode' => ['type' => 'string', 'enum' => ['create', 'upsert'], 'default' => 'create'],
                    'dryRun' => ['type' => 'boolean', 'default' => false, 'description' => 'A preview: the same run, rolled back.'],
                ],
            ]))]), true),
        )));

        $openApi->getPaths()->addPath('/api/companies/{companyId}/import-templates/{subject}.{format}', new PathItem(get: new Operation(
            operationId: 'importTemplate',
            tags: ['ImportGuide'],
            responses: [
                '200' => new Response('One header row, the column keys', new \ArrayObject([
                    SpreadsheetFormat::Csv->mediaType() => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary'])),
                    SpreadsheetFormat::Xlsx->mediaType() => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary'])),
                ])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such subject, its module switched off, or no permission to write it'),
                '503' => new Response('The file could not be prepared'),
            ],
            summary: 'The empty file to fill in',
            parameters: [$company, $subject, new Parameter('format', 'path', 'The file format', true, schema: ['type' => 'string', 'enum' => ['csv', 'xlsx']])],
        )));

        return $openApi;
    }
}
