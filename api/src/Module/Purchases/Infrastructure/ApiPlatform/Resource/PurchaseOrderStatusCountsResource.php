<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider\PurchaseOrderStatusCountsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/** What each status chip of « Commandes fournisseurs » would list: under the same words and vendors as the list, the status left aside. */
#[ApiResource(
    shortName: 'PurchaseOrderStatusCounts',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/purchase-order-status-counts',
            provider: PurchaseOrderStatusCountsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'The list\'s own words.'),
                'vendorId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], description: 'Several vendors, OR\'d; a single `vendorId=…` still works.', constraints: []),
                'issueDate[from]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Sent on or after this day.'),
                'issueDate[to]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Sent on or before this day.'),
                'expectedOn[from]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Expected on or after this day.'),
                'expectedOn[to]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Expected on or before this day.'),
            ],
        ),
    ],
)]
final class PurchaseOrderStatusCountsResource
{
    public const string READ = 'purchase_order_status_counts:read';
    private const array COUNT = ['type' => 'integer', 'minimum' => 0];

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public int $all = 0;

    /** @var array<string, int> */
    #[ApiProperty(schema: [
        'type' => 'object',
        'required' => PurchaseOrderResource::STATUSES,
        'properties' => [
            'draft' => self::COUNT,
            'sent' => self::COUNT,
            'partly_received' => self::COUNT,
            'received' => self::COUNT,
            'closed' => self::COUNT,
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
