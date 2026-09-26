<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What each status chip of « Dépenses » would list (docs/SPEC.md § 7, 2026-09-26): under the same words, vendor and
 * category as the list, the status left aside.
 */
#[ApiResource(
    shortName: 'ExpenseStatusCounts',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/expense-status-counts',
            provider: ExpenseStatusCountsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'The list\'s own words.'),
                'vendorId' => new QueryParameter(schema: self::ID),
                'categoryId' => new QueryParameter(schema: self::ID),
            ],
        ),
    ],
)]
final class ExpenseStatusCountsResource
{
    public const string READ = 'expense_status_counts:read';
    private const array ID = ['type' => 'string', 'format' => 'uuid'];
    private const array COUNT = ['type' => 'integer', 'minimum' => 0];

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public int $all = 0;

    /** @var array<string, int> */
    #[ApiProperty(schema: [
        'type' => 'object',
        'required' => ['draft', 'recorded', 'paid'],
        'properties' => [
            'draft' => self::COUNT,
            'recorded' => self::COUNT,
            'paid' => self::COUNT,
        ],
    ])]
    #[Groups([self::READ])]
    public array $statuses = [];

    /** @param array{all: int, statuses: array<string, int>} $counts */
    public static function of(array $counts): self
    {
        $resource = new self();
        $resource->all = $counts['all'];
        $resource->statuses = $counts['statuses'];

        return $resource;
    }
}
