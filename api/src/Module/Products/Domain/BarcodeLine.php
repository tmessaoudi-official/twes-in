<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Module\Vendors\Domain\Vendor;

/**
 * A code as a product sheet writes it: its role, the code, how many pieces one scan of it enters, and for a
 * supplier's code the supplier who prints it (docs/SPEC.md § 7, 2026-09-22 11:05).
 */
final readonly class BarcodeLine
{
    /** A pallet of a million pieces is already beyond anything scanned as one; a typo past it is refused. */
    public const int QUANTITY_MAX = 1_000_000;

    /** @throws InvalidProduct */
    public function __construct(
        public BarcodeRole $role,
        public Barcode $barcode,
        public int $quantity,
        public ?Vendor $supplier = null,
    ) {
        if ($quantity < 1 || $quantity > self::QUANTITY_MAX) {
            throw new InvalidProduct('quantity', \sprintf('One scan enters 1 to %d pieces.', self::QUANTITY_MAX));
        }
        if (BarcodeRole::Unit === $role && 1 !== $quantity) {
            throw new InvalidProduct('quantity', 'A unit code enters one piece; a code that enters several is a pack.');
        }
        if (BarcodeRole::Pack === $role && 1 === $quantity) {
            throw new InvalidProduct('quantity', 'A pack enters more than one piece; a code that enters one is a unit code.');
        }
        if (BarcodeRole::Supplier === $role && null === $supplier) {
            throw new InvalidProduct('supplierId', 'A supplier\'s code names the supplier who prints it.');
        }
        if (BarcodeRole::Supplier !== $role && null !== $supplier) {
            throw new InvalidProduct('supplierId', 'Only a supplier\'s code names a supplier.');
        }
    }
}
