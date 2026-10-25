<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Module\Products\Domain\Model\ProductCostChange;
use App\Module\Products\Infrastructure\ApiPlatform\State\Provider\VendorPurchasesProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the company bought from a vendor, line by line, as its past purchases were imported into its products' cost
 * histories: the newest day first, those of no known day last, a page at a time. Read with `vendor.read` and
 * `product.cost.read`, as what a line cost is a cost; whoever lacks either is told there is no such page.
 */
#[ApiResource(
    shortName: 'VendorPurchase',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/vendors/{vendorId}/purchases',
            provider: VendorPurchasesProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            // One page at a time, with its total, which only JSON-LD carries.
            outputFormats: ['jsonld' => ['application/ld+json']],
        ),
    ],
)]
final class VendorPurchaseResource
{
    public const string READ = 'vendor_purchase:read';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public string $productId = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $productReference = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $productName = '';

    /** The day of the purchase, `YYYY-MM-DD`; none when its file gave none that can be. */
    #[ApiProperty(writable: false, openapiContext: ['format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $purchasedOn = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $invoiceNumber = null;

    /** How many were bought, three decimals; a return below zero. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $quantity = null;

    /** What one cost, four decimals, before taxes. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $unitCost = '';

    public static function of(ProductCostChange $purchase): self
    {
        $resource = new self();
        $resource->id = $purchase->getId()->toRfc4122();
        $product = $purchase->getProduct();
        $resource->productId = $product->getId()->toRfc4122();
        $resource->productReference = $product->getReference();
        $resource->productName = $product->getDetails()->name;
        $resource->purchasedOn = $purchase->getPurchasedOn()?->format('Y-m-d');
        $resource->invoiceNumber = $purchase->getInvoiceNumber();
        $resource->quantity = $purchase->getQuantity();
        $resource->unitCost = $purchase->getNewCost() ?? '';

        return $resource;
    }
}
