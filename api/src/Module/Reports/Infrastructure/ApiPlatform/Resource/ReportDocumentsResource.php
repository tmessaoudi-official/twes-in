<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportDocument;
use App\Module\Reports\Infrastructure\ApiPlatform\State\Provider\ReportDocumentsProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * The documents behind one row of a report (every figure opens the documents behind
 * it), for a row no list of the app can show exactly: the payments of one day, the invoices that sold one product. Each
 * with what it brings to the figure, so the documents add up to it. Same absence as the report for a report the member may
 * not read.
 */
#[ApiResource(
    shortName: 'ReportDocuments',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/reports/{key}/documents',
            requirements: ['key' => '[a-z][a-z-]{1,39}'],
            provider: ReportDocumentsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
            parameters: [
                'from' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'The first day of the period the row was read over; none for a report of today.'),
                'to' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'The last day of that period, included; none for a report of today.'),
                'row' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'The row\'s key, as the report gave it.', required: true),
                'establishment' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'The establishment the report was narrowed to, if any.'),
            ],
        ),
    ],
)]
final class ReportDocumentsResource
{
    public const string READ = 'report_documents:read';

    /** @var list<array{kind: string, id: string, number: ?string, party: ?string, day: string, amount: string}> */
    #[ApiProperty(identifier: false, required: true, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['kind', 'id', 'number', 'party', 'day', 'amount'],
            'properties' => [
                'kind' => ['type' => 'string'],
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'number' => ['type' => ['string', 'null']],
                'party' => ['type' => ['string', 'null']],
                'day' => ['type' => 'string', 'format' => 'date'],
                'amount' => ['type' => 'string'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $documents = [];

    /** Whether the row has more documents than these, which its list or the export shows whole. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public bool $more = false;

    /** @param list<ReportDocument> $documents */
    public static function of(array $documents): self
    {
        $resource = new self();
        $resource->more = \count($documents) > DeclaresReport::DOCUMENTS_SHOWN;
        $resource->documents = array_map(static fn (ReportDocument $document): array => [
            'kind' => $document->kind,
            'id' => $document->id,
            'number' => $document->number,
            'party' => $document->party,
            'day' => $document->day,
            'amount' => $document->amount,
        ], \array_slice($documents, 0, DeclaresReport::DOCUMENTS_SHOWN));

        return $resource;
    }
}
