<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductBarcode;
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\BarcodeRole;

/**
 * A product as a file writes it, column by column, the way the import reads it back: what the export writes, and what
 * an upsert compares before and after it writes a row (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 5). Where its goods
 * lie and its stock are the inventory's to say, so they are not among its cells.
 */
final class ProductInFile
{
    /**
     * A product has several packs and several suppliers' codes, and a row of a file names one of each: the cells of a
     * pack and of a supplier's code are those of the code `$pack` and `$supplierCode` name, blank when the product does
     * not hold it, so a code a row adds shows as a change. Named by nothing, as an export is, they are the first held.
     * A supplier named without a code is one the product is bought from: its number when the product is linked to it,
     * blank when not; an export with no supplier's code writes the first such supplier.
     *
     * @param array<string, string> $taxCodes by tax id
     */
    public static function cell(Product $product, string $column, array $taxCodes, ?string $pack = null, ?string $supplierCode = null, ?string $supplierNumber = null): string
    {
        $details = $product->getDetails();

        return match (true) {
            'reference' === $column => $product->getReference(),
            'name' === $column => $details->name,
            'kind' === $column => $details->kind->value,
            'tracking' === $column => $product->getTracking()->value,
            'description' === $column => $details->description ?? '',
            'substitution_group' === $column => $details->substitutionGroup ?? '',
            'unit_code' === $column => $product->getUnit()->getCode(),
            'category' === $column => $product->getCategory()?->getName() ?? '',
            'unit_price_net' === $column => $details->unitPriceNet,
            'cost_price' === $column => $details->costPrice ?? '',
            'barcode' === $column => self::unitBarcode($product),
            'pack_barcode' === $column => self::held($product, BarcodeRole::Pack, $pack)?->getCode() ?? '',
            'pack_quantity' === $column => (string) (self::held($product, BarcodeRole::Pack, $pack)?->getQuantity() ?? ''),
            'supplier_number' === $column => self::supplierNumber($product, $supplierCode, $supplierNumber),
            'supplier_code' === $column => self::held($product, BarcodeRole::Supplier, $supplierCode)?->getCode() ?? '',
            'default_tax_codes' === $column => implode(',', array_map(static fn (string $id): string => $taxCodes[$id] ?? '', $product->getDefaultTaxComponentIds())),
            'active' === $column => $product->isActive() ? 'yes' : 'no',
            // The note a person left; a mark without one writes nothing, which the import reads as « keep the mark ».
            'to_check' === $column => $product->getCheckMark()->note ?? '',
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

    private static function supplierNumber(Product $product, ?string $supplierCode, ?string $supplierNumber): string
    {
        $code = self::held($product, BarcodeRole::Supplier, $supplierCode);
        if (null !== $supplierCode || null !== $code && null === $supplierNumber) {
            return $code?->getSupplier()?->getNumber() ?? '';
        }
        foreach ($product->getVendors() as $vendor) {
            if (null === $supplierNumber || $supplierNumber === $vendor->getNumber()) {
                return $vendor->getNumber();
            }
        }

        return '';
    }

    /** The code of this role the product holds under `$named`, or its first of the role when nothing is named. */
    public static function held(Product $product, BarcodeRole $role, ?string $named): ?ProductBarcode
    {
        $key = null === $named ? null : Barcode::keyOf($named);
        foreach ($product->getBarcodes() as $barcode) {
            if ($role === $barcode->getRole() && (null === $key || $key === $barcode->getMatchKey())) {
                return $barcode;
            }
        }

        return null;
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
