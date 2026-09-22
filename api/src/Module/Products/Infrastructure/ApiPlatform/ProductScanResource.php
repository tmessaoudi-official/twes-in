<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Products\Domain\Gs1Scan;
use App\Module\Products\Domain\ProductBarcode;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What one scan names, read with product.read (docs/SPEC.md § 7, 2026-09-20 04:15, 2026-09-22 11:05, 11:10 and
 * 11:25): the one product holding the code, the code's role, how many pieces one scan of it enters, and for a GS1 scan
 * the lot, use-by date and serial it carried. 404 when no product of the company answers to it — a scan names one
 * product or none, never a choice.
 */
#[ApiResource(
    shortName: 'ProductScan',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/product-scan',
            provider: ProductScanProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'code' => new QueryParameter(
                    schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    required: true,
                    description: 'What the scanner read: a code, or a GS1 element string with or without its symbology prefix, FNC1 as ASCII 29 or the bracketed form.',
                ),
            ],
        ),
    ],
)]
final class ProductScanResource
{
    public const string READ = 'product_scan:read';

    #[ApiProperty(identifier: false, required: true)]
    #[Groups([self::READ])]
    public string $productId = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $reference = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** Whether the product is still sold; a retired one is found, so a person holding one learns why it is refused. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public bool $isActive = true;

    /** The code as the product holds it, and its role. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $code = '';

    #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['unit', 'pack', 'supplier', 'internal']])]
    #[Groups([self::READ])]
    public string $role = 'unit';

    /** How many pieces this one scan enters: 1 for a unit code, the pack's count for a pack. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public int $quantity = 1;

    /** `(10)` of a GS1 scan. */
    #[Groups([self::READ])]
    public ?string $lot = null;

    /** `(17)` of a GS1 scan, as a date. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $useBy = null;

    /** `(21)` of a GS1 scan. */
    #[Groups([self::READ])]
    public ?string $serial = null;

    public static function of(ProductBarcode $held, Gs1Scan $scan, int $thisYear): self
    {
        $product = $held->getProduct();
        $resource = new self();
        $resource->productId = $product->getId()->toRfc4122();
        $resource->reference = $product->getReference();
        $resource->name = $product->getDetails()->name;
        $resource->isActive = $product->isActive();
        $resource->code = $held->getCode();
        $resource->role = $held->getRole()->value;
        $resource->quantity = $held->getQuantity();
        $resource->lot = $scan->lot;
        $resource->useBy = $scan->expiry($thisYear);
        $resource->serial = $scan->serial;

        return $resource;
    }
}
