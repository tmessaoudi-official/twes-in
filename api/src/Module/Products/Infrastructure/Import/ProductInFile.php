<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\Product;

/**
 * A product as a file writes it, column by column, the way the import reads it back: what the export writes, and what
 * an upsert compares before and after it writes a row (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 5). Where its goods
 * lie and its stock are the inventory's to say, so they are not among its cells.
 */
final class ProductInFile
{
    /** @param array<string, string> $taxCodes by tax id */
    public static function cell(Product $product, string $column, array $taxCodes): string
    {
        $details = $product->getDetails();

        return match (true) {
            'reference' === $column => $product->getReference(),
            'name' === $column => $details->name,
            'kind' === $column => $details->kind->value,
            'description' === $column => $details->description ?? '',
            'unit_code' === $column => $product->getUnit()->getCode(),
            'category' === $column => $product->getCategory()?->getName() ?? '',
            'unit_price_net' === $column => $details->unitPriceNet,
            'cost_price' === $column => $details->costPrice ?? '',
            'barcode' === $column => self::unitBarcode($product),
            'default_tax_codes' === $column => implode(',', array_map(static fn (string $id): string => $taxCodes[$id] ?? '', $product->getDefaultTaxComponentIds())),
            'active' === $column => $product->isActive() ? 'yes' : 'no',
            str_starts_with($column, ProductImport::CUSTOM_PREFIX) => self::custom($product->getCustomFields()[substr($column, \strlen(ProductImport::CUSTOM_PREFIX))] ?? null),
            default => '',
        };
    }

    private static function unitBarcode(Product $product): string
    {
        foreach ($product->getBarcodes() as $barcode) {
            if (BarcodeRole::Unit === $barcode->getRole()) {
                return $barcode->getCode();
            }
        }

        return '';
    }

    private static function custom(string|int|float|bool|null $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
