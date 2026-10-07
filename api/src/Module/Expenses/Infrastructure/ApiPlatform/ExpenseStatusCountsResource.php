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
                'paymentMethod[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['transfer', 'cash', 'check', 'card', 'other']]], description: 'Several ways of paying, OR\'d; a single `paymentMethod=cash` still works. An expense not paid yet has none and is left out by any.', constraints: []),
                'withheld' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['yes', 'no']], description: '`yes`: something was withheld at the source when it was paid; `no`: nothing was, which includes what is not paid yet.'),
                'vendorId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => self::ID], description: 'Several vendors, OR\'d; a single `vendorId=…` still works.', constraints: []),
                'categoryId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => self::ID], description: 'Several categories, OR\'d, each with every category under it at any depth; a single `categoryId=…` still works.', constraints: []),
                'date[from]' => new QueryParameter(schema: self::DAY, description: 'On or after this day.'),
                'date[to]' => new QueryParameter(schema: self::DAY, description: 'On or before this day.'),
                'amountGross[min]' => new QueryParameter(schema: self::AMOUNT, description: 'Taxes included, at least this amount.'),
                'amountGross[max]' => new QueryParameter(schema: self::AMOUNT, description: 'Taxes included, at most this amount.'),
            ],
        ),
    ],
)]
final class ExpenseStatusCountsResource
{
    public const string READ = 'expense_status_counts:read';
    private const array DAY = ['type' => 'string', 'format' => 'date'];
    /** A decimal string, as every amount is: a float would round it. */
    private const array AMOUNT = ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]{1,4})?$'];
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
