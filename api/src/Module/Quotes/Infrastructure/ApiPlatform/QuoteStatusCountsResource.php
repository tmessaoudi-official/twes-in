<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/** What each status chip of « Devis » would list: under the same words and customer as the list, the status left aside. */
#[ApiResource(
    shortName: 'QuoteStatusCounts',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/quote-status-counts',
            provider: QuoteStatusCountsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'The list\'s own words.'),
                'customerId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], description: 'Several customers, OR\'d; a single `customerId=…` still works.', constraints: []),
                'issueDate[from]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Sent on or after this day.'),
                'issueDate[to]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Sent on or before this day.'),
            ],
        ),
    ],
)]
final class QuoteStatusCountsResource
{
    public const string READ = 'quote_status_counts:read';
    private const array COUNT = ['type' => 'integer', 'minimum' => 0];

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public int $all = 0;

    /** @var array<string, int> */
    #[ApiProperty(schema: [
        'type' => 'object',
        'required' => ['draft', 'sent', 'accepted', 'refused', 'cancelled'],
        'properties' => [
            'draft' => self::COUNT,
            'sent' => self::COUNT,
            'accepted' => self::COUNT,
            'refused' => self::COUNT,
            'cancelled' => self::COUNT,
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
