<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Inventory\Application\ReceiptCost;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What a receipt of a product would do to its cost, asked before it is saved: the mode the company chose, the cost now,
 * the average the receipt would leave and the latest cost somebody typed on a receipt. Nothing is written. Read with
 * `product.cost.read` AND `stock.read`: whoever lacks either is told there is no such page.
 */
#[ApiResource(
    shortName: 'ReceiptCost',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/stock-options/receipt-cost',
            provider: ReceiptCostProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'productId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'The product received. One that does not exist in the company is a 404.',
                    required: true,
                ),
                'quantity' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 20],
                    description: 'The quantity of the receipt being typed. Left out or not a positive number, the average is the current one.',
                ),
                'unitCost' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 20],
                    description: 'The cost typed on the receipt. Left out or not a number of zero or more, the average is the current one.',
                ),
            ],
        ),
    ],
)]
final class ReceiptCostResource
{
    public const string READ = 'receipt_cost:read';

    /** @var 'suggest'|'average'|'last'|'manual' */
    #[ApiProperty(identifier: false, writable: false, openapiContext: ['enum' => ['suggest', 'average', 'last', 'manual']])]
    #[Groups([self::READ])]
    public string $mode = 'suggest';

    /** What the product costs now, four decimals; none when it has no cost. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $costNow = null;

    /** The weighted average as the receipt would leave it, four decimals; none when there is nothing to average. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $average = null;

    /** The cost on the latest receipt that came with one typed. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $lastCost = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?\DateTimeImmutable $lastAt = null;

    public static function of(ReceiptCost $cost): self
    {
        $resource = new self();
        $resource->mode = $cost->mode->value;
        $resource->costNow = $cost->costNow;
        $resource->average = $cost->average;
        $resource->lastCost = $cost->lastCost;
        $resource->lastAt = $cost->lastAt;

        return $resource;
    }
}
