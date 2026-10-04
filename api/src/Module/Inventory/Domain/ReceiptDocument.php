<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Module\Vendors\Domain\Vendor;

/**
 * What a goods receipt says about where the goods came from: the vendor, the number on the vendor's own delivery note or
 * invoice, and the day they arrived. Every part is optional, so a receipt typed at the counter with none of it is still
 * a receipt, and the vendor price view reads what was given. The goods receipt of the purchase module will carry the
 * same three facts; these are kept on the stock movement until then and migrated into it.
 */
final readonly class ReceiptDocument
{
    public const int REFERENCE_MAX = 60;

    public ?string $supplierReference;

    /** @throws InvalidStockMovement */
    public function __construct(public ?Vendor $vendor = null, ?string $supplierReference = null, public ?\DateTimeImmutable $receivedOn = null)
    {
        $reference = null === $supplierReference ? '' : trim($supplierReference);
        if (mb_strlen($reference) > self::REFERENCE_MAX) {
            throw new InvalidStockMovement('supplierReference', \sprintf('A supplier reference is at most %d characters.', self::REFERENCE_MAX));
        }
        $this->supplierReference = '' === $reference ? null : $reference;
    }

    public function isEmpty(): bool
    {
        return null === $this->vendor && null === $this->supplierReference && null === $this->receivedOn;
    }
}
