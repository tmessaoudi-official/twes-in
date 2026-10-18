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
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Infrastructure\ApiPlatform\State\Provider\ReportCatalogueProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * The reports the signed-in member may read in the company, in the order the Reports area offers them (docs/SPEC.md § 7,
 * 2026-09-24 12:05): each with its columns for this member and whether it can be narrowed to an establishment. Read with
 * company.read; each report is offered only to a role holding its subject's permission, in a module switched on.
 */
#[ApiResource(
    shortName: 'ReportCatalogue',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/reports',
            provider: ReportCatalogueProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class ReportCatalogueResource
{
    public const string READ = 'report_catalogue:read';

    public const array COLUMNS = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'kind', 'params'],
            'properties' => [
                'key' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['label', 'choice', 'day', 'amount', 'quantity', 'count', 'days']],
                'params' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            ],
        ],
    ];

    /** @var list<array{key: string, byEstablishment: bool, periodic: bool, columns: list<array{key: string, kind: string, params: object}>}> */
    #[ApiProperty(identifier: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'byEstablishment', 'periodic', 'columns'],
            'properties' => [
                'key' => ['type' => 'string'],
                'byEstablishment' => ['type' => 'boolean'],
                'periodic' => ['type' => 'boolean'],
                'columns' => self::COLUMNS,
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $reports = [];

    /** @param list<DeclaresReport> $reports */
    public static function of(array $reports, ReportContext $context): self
    {
        $resource = new self();
        $resource->reports = array_map(static fn (DeclaresReport $report): array => [
            'key' => $report->key(),
            'byEstablishment' => $report->byEstablishment(),
            'periodic' => $report->periodic(),
            'columns' => ReportResource::columnsOf($report->columns($context)),
        ], $reports);

        return $resource;
    }
}
