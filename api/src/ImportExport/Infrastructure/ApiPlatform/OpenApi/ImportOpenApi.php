<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\ApiPlatform\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\ImportExport\Application\Undo\ImportNotUndoable;
use App\ImportExport\Application\UnreadableImport;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documents the import, its template and « Annuler cet import », plain controllers API Platform does not describe
 * (ImportController, which reads a multipart upload, ImportTemplateController, which answers bytes, and
 * ImportRunController). The guide beside them is a resource.
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
            'required' => ['committed', 'created', 'updated', 'unchanged', 'rejected', 'changes', 'notes', 'alreadyImportedAt', 'run'],
            'properties' => [
                'committed' => ['type' => 'boolean', 'description' => 'Whether the file was stored: never for a preview, nor while a row is rejected.'],
                'created' => $lines + ['description' => 'The file’s own line numbers, empty lines counted.'],
                'updated' => $lines + ['description' => 'The lines found again whose record changes.'],
                'unchanged' => $lines + ['description' => 'The lines found again whose record the row leaves as it is: nothing is written for them.'],
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
                'changes' => ['type' => 'array', 'description' => 'What each line updated changes, cell by cell, as the file writes each value.', 'items' => [
                    'type' => 'object',
                    'required' => ['line', 'column', 'before', 'after'],
                    'properties' => [
                        'line' => ['type' => 'integer'],
                        'column' => ['type' => 'string'],
                        'before' => ['type' => 'string', 'description' => 'The value held, empty when there was none.'],
                        'after' => ['type' => 'string'],
                    ],
                ]],
                'notes' => ['type' => 'array', 'description' => 'What a row imported was noted for, such as the reference its new product was given; a note never stops the file.', 'items' => [
                    'type' => 'object',
                    'required' => ['line', 'column', 'code', 'params'],
                    'properties' => [
                        'line' => ['type' => 'integer'],
                        'column' => ['type' => ['string', 'null']],
                        'code' => ['type' => 'string', 'description' => 'A stable code, translated by the screen as `import.notes.<code>`.'],
                        'params' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']]],
                    ],
                ]],
                'alreadyImportedAt' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When this very file (the same bytes) was last imported into this subject, committed; null when it never was.'],
                'run' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'The import kept, which `POST …/import-runs/{id}/undo` takes back within its day; null for a preview or a file stopped on rejected rows.'],
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
                '403' => new Response('`step_up_required`: the file sets or changes what asks who is at the screen (a vendor\'s IBAN or BIC) and the proof is not recent; nothing was stored. A preview never asks', $json(['type' => 'object', 'properties' => ['error' => ['type' => 'string', 'enum' => ['step_up_required']]]])),
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
                    'switches' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The keys of the subject’s switches ticked, as its guide lists them; any other is ignored.'],
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

        $openApi->getPaths()->addPath('/api/companies/{companyId}/import-runs', new PathItem(get: new Operation(
            operationId: 'importRuns',
            tags: ['ImportGuide'],
            responses: [
                '200' => new Response('« Imports récents »: the imports of the last day into the subjects the person may write, the latest first', $json(['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['id', 'subject', 'at', 'by', 'created', 'updated', 'undoableUntil', 'undoneAt'],
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'subject' => ['type' => 'string', 'description' => 'What was imported, such as `customers`.'],
                        'at' => ['type' => 'string', 'format' => 'date-time'],
                        'by' => ['type' => ['string', 'null'], 'description' => 'Who ran it, by the name the person goes by; null for an account gone since.'],
                        'created' => ['type' => 'integer'],
                        'updated' => ['type' => 'integer'],
                        'undoableUntil' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Until when « Annuler cet import » takes it back.'],
                        'undoneAt' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When it was taken back; null while it stands, the bin\'s restore included.'],
                    ],
                ]])),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such company'),
            ],
            summary: 'The imports « Annuler cet import » may still take back',
            parameters: [$company],
        )));

        $kept = ['type' => 'object', 'required' => ['reference', 'location', 'reason'], 'properties' => [
            'reference' => ['type' => 'string'],
            'location' => ['type' => 'string', 'description' => 'The stock location\'s code.'],
            'reason' => ['type' => 'string', 'enum' => ['counted_since', 'below_zero'], 'description' => 'Counted there since, or goods gone out since so that taking it back would leave less than nothing.'],
        ]];
        $openApi->getPaths()->addPath('/api/companies/{companyId}/import-runs/{runId}/undo', new PathItem(post: new Operation(
            operationId: 'undoImport',
            tags: ['ImportGuide'],
            responses: [
                '200' => new Response('What the undo took back and what it kept', $json([
                    'type' => 'object',
                    'required' => ['removal', 'removed', 'kept', 'rowsKept', 'stockTakenBack', 'stockKept', 'updated'],
                    'properties' => [
                        'removal' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'The delete « Récemment supprimés » restores whole; null when no record went.'],
                        'removed' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'label'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'label' => ['type' => 'string']]]],
                        'kept' => ['type' => 'array', 'description' => 'The records created that something uses since, which stay.', 'items' => ['type' => 'object', 'required' => ['id', 'label', 'uses'], 'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'label' => ['type' => 'string'],
                            'uses' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['table', 'column', 'count'], 'properties' => ['table' => ['type' => 'string'], 'column' => ['type' => 'string'], 'count' => ['type' => 'integer']]]],
                        ]]],
                        'rowsKept' => ['type' => 'array', 'description' => 'The links the file added that stay, such as a vendor still holding a code of the product.', 'items' => ['type' => 'object', 'required' => ['label', 'reason'], 'properties' => [
                            'label' => ['type' => 'string'],
                            'reason' => ['type' => 'string', 'enum' => ['vendor_has_code', 'product_merged']],
                        ]]],
                        'stockTakenBack' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['reference', 'location', 'quantity'], 'properties' => [
                            'reference' => ['type' => 'string'],
                            'location' => ['type' => 'string'],
                            'quantity' => ['type' => 'string', 'description' => 'What the file had brought, signed, in the unit\'s own decimals.'],
                        ]]],
                        'stockKept' => ['type' => 'array', 'items' => $kept],
                        'updated' => ['type' => 'integer', 'description' => 'The records the file only changed, which stay as changed.'],
                    ],
                ])),
                '401' => new Response('Not signed in'),
                '403' => new Response('`step_up_required`: the proof given again is not recent; nothing was taken back', $json(['type' => 'object', 'properties' => ['error' => ['type' => 'string', 'enum' => ['step_up_required']]]])),
                '404' => new Response('No such import, its module switched off, or no permission to write its subject'),
                '409' => new Response('`import_undone` once taken back, `import_final` once its day is over', $json(['type' => 'object', 'required' => ['error'], 'properties' => ['error' => ['type' => 'string', 'enum' => [ImportNotUndoable::UNDONE, ImportNotUndoable::FINAL]]]])),
            ],
            summary: '« Annuler cet import »',
            parameters: [$company, new Parameter('runId', 'path', 'The import', true, schema: ['type' => 'string', 'format' => 'uuid'])],
        )));

        return $openApi;
    }
}
