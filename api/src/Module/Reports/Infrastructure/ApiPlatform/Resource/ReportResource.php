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
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportResult;
use App\Module\Reports\Infrastructure\ApiPlatform\State\Provider\ReportProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * One report over a period, each row beside the same row of the period compared, and the totals of both.
 * A report the member may not read, one of a module switched off and a key
 * nobody declared all answer 404, so asking by name learns nothing.
 */
#[ApiResource(
    shortName: 'Report',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/reports/{key}',
            requirements: ['key' => '[a-z][a-z-]{1,39}'],
            provider: ReportProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
            parameters: [
                'from' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'The first day read, in the company\'s own calendar: required by a report of a period, refused by a report of today (the catalogue\'s `periodic`).'),
                'to' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'The last day read, included; at most 366 days after the first. Required and refused as `from` is.'),
                'compare' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['none', 'previous', 'year']], description: 'What the period is read against: nothing (the default), the period before (whole months from the first against as many months before, any other span against as many days just before), or the same days a year earlier.'),
                'establishment' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'One of the company\'s establishments. Refused (422) by a report that cannot be narrowed to one; the catalogue says which can.'),
            ],
        ),
    ],
)]
final class ReportResource
{
    public const string READ = 'report:read';

    private const array CELLS = ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer', 'null']]];

    #[ApiProperty(identifier: false, required: true)]
    #[Groups([self::READ])]
    public string $key = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $from = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $to = '';

    #[ApiProperty(required: true, schema: ['type' => ['string', 'null']])]
    #[Groups([self::READ])]
    public ?string $comparisonFrom = null;

    #[ApiProperty(required: true, schema: ['type' => ['string', 'null']])]
    #[Groups([self::READ])]
    public ?string $comparisonTo = null;

    /** @var list<array{key: string, kind: string, params: object}> */
    #[ApiProperty(required: true, schema: ReportCatalogueResource::COLUMNS)]
    #[Groups([self::READ])]
    public array $columns = [];

    /**
     * Each row: its key, its cells, the same row's cells in the period compared (null with no comparison, or where that
     * period had no such row), and where its figures lead: a list and its filters, or the report's own documents (a null
     * list), or nowhere (null).
     *
     * @var list<array{key: string, cells: array<string, string|int|null>, previous: ?array<string, string|int|null>, drill: ?array{list: ?string, filters: array<string, string|list<string>>}}>
     */
    #[ApiProperty(required: true, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'cells', 'previous', 'drill'],
            'properties' => [
                'key' => ['type' => 'string'],
                'cells' => self::CELLS,
                'previous' => ['anyOf' => [self::CELLS, ['type' => 'null']]],
                'drill' => ['anyOf' => [[
                    'type' => 'object',
                    'required' => ['list', 'filters'],
                    'properties' => [
                        'list' => ['type' => ['string', 'null']],
                        'filters' => ['type' => 'object', 'additionalProperties' => ['anyOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]]]],
                    ],
                ], ['type' => 'null']]],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $rows = [];

    /** @var array<string, string|int> */
    #[ApiProperty(required: true, schema: ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']]])]
    #[Groups([self::READ])]
    public array $totals = [];

    /** @var ?array<string, string|int> */
    #[ApiProperty(required: true, schema: ['anyOf' => [['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']]], ['type' => 'null']]])]
    #[Groups([self::READ])]
    public ?array $previousTotals = null;

    public static function of(ReportResult $result): self
    {
        $resource = new self();
        $resource->key = $result->key;
        $resource->from = $result->period->from->format('Y-m-d');
        $resource->to = $result->period->to->format('Y-m-d');
        $resource->comparisonFrom = $result->comparison?->from->format('Y-m-d');
        $resource->comparisonTo = $result->comparison?->to->format('Y-m-d');
        $resource->columns = self::columnsOf($result->columns);
        $resource->rows = array_map(static fn (array $line): array => [
            'key' => $line['row']->key,
            'cells' => $line['row']->cells,
            'previous' => $line['previous'],
            'drill' => self::drillOf($line['row']->drill),
        ], $result->rows);
        $resource->totals = $result->totals;
        $resource->previousTotals = $result->previousTotals;

        return $resource;
    }

    /**
     * @param list<ReportColumn> $columns
     *
     * @return list<array{key: string, kind: string, params: object}>
     */
    public static function columnsOf(array $columns): array
    {
        return array_map(static fn (ReportColumn $column): array => ['key' => $column->key, 'kind' => $column->kind->value, 'params' => (object) $column->params], $columns);
    }

    /** @return ?array{list: ?string, filters: array<string, string|list<string>>} */
    private static function drillOf(?ReportDrill $drill): ?array
    {
        return null === $drill ? null : ['list' => $drill->list, 'filters' => $drill->filters];
    }
}
