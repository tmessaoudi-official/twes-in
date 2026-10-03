<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Domain\ProductCostChange;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What a product has cost the company over time, the latest changes first, as many as `ManageProducts::COST_HISTORY_LIMIT`.
 * Read with `product.cost.read`: whoever may not see a cost is told there is no such page.
 */
#[ApiResource(
    shortName: 'ProductCostChange',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/products/{productId}/cost-history',
            provider: ProductCostHistoryProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            paginationEnabled: false,
        ),
    ],
)]
final class ProductCostChangeResource
{
    public const string READ = 'product_cost_change:read';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    /** The cost before, none when the product had none. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $oldCost = null;

    /** The cost after, none when it was taken away. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $newCost = null;

    /** @var 'created'|'edited'|'receipt' */
    #[ApiProperty(writable: false, openapiContext: ['enum' => ['created', 'edited', 'receipt']])]
    #[Groups([self::READ])]
    public string $source = 'edited';

    /** The receipt that applied the cost, for a change that came from one. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $sourceId = null;

    /** The person who made the change, by identifier; none for a change nobody made (an import by the system). */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $changedBy = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public \DateTimeImmutable $at;

    public function __construct()
    {
        $this->at = new \DateTimeImmutable('@0');
    }

    public static function of(ProductCostChange $change): self
    {
        $resource = new self();
        $resource->id = $change->getId()->toRfc4122();
        $resource->oldCost = $change->getOldCost();
        $resource->newCost = $change->getNewCost();
        $resource->source = $change->getSource()->value;
        $resource->sourceId = $change->getSourceId()?->toRfc4122();
        $resource->changedBy = $change->getChangedBy()?->toRfc4122();
        $resource->at = $change->getAt();

        return $resource;
    }
}
