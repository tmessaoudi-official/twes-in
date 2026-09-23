<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Module\Products\Application\BarcodeInput;
use App\Module\Products\Domain\Product;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The codes a product answers to, written as one list with product.write (docs/SPEC.md § 7, 2026-09-22 11:05): a
 * save makes them exactly these, so a code can move from one row of the product to another in one go. Kept out of
 * the product's own PUT, like its homes and its defaults, so the product form never writes codes it does not show.
 *
 * A code another product of the company holds, in any role, answers 409 naming the row and that product
 * (`barcodes.<index>.code: <code> is already a code of <reference>.`); a malformed row answers 422 naming it.
 *
 * A caller without product.cost.read neither reads nor writes a supplier's code (docs/SPEC.md § 7, 2026-09-23 09:45):
 * one in what they send answers 422 on its `role`, and the ones stored are kept through their save.
 */
#[ApiResource(
    shortName: 'ProductBarcodes',
    operations: [
        new Put(
            uriTemplate: '/companies/{companyId}/products/{productId}/barcodes',
            processor: ReplaceProductBarcodesProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class ProductBarcodesResource
{
    public const string READ = 'product_barcodes:read';
    public const string WRITE = 'product_barcodes:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $productId = null;

    /** @var list<ProductBarcodeRow> */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(max: 50, groups: [self::WRITE])]
    #[Assert\Valid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $barcodes = [];

    public static function of(Product $product, bool $withCosts): self
    {
        $resource = new self();
        $resource->productId = $product->getId()->toRfc4122();
        $resource->barcodes = ProductBarcodeRow::listOf($product, $withCosts);

        return $resource;
    }

    /** @return list<BarcodeInput> */
    public function input(): array
    {
        return array_map(static fn (ProductBarcodeRow $row): BarcodeInput => $row->input(), $this->barcodes);
    }
}
