<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain\ValueObject;

use App\Module\Inventory\Domain\Exception\InvalidStockMovement;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Shared\Domain\Service\TypedText;

/**
 * What a goods receipt says about where the goods came from: the vendor, the number on the vendor's own delivery note or
 * invoice, and the day they arrived. Every part is optional, so a receipt typed at the counter with none of it is still
 * a receipt, and the vendor price view reads what was given. They stay on the stock movement whether the goods came
 * against an order to a vendor or not: the views reading them then need no second source, and a receipt typed before
 * orders existed keeps its own.
 */
final readonly class ReceiptDocument
{
    public const int REFERENCE_MAX = 60;

    public ?string $supplierReference;

    /** @throws InvalidStockMovement */
    public function __construct(public ?Vendor $vendor = null, ?string $supplierReference = null, public ?\DateTimeImmutable $receivedOn = null)
    {
        $reference = null === $supplierReference ? '' : TypedText::trimmed($supplierReference);
        if (mb_strlen($reference) > self::REFERENCE_MAX) {
            throw new InvalidStockMovement('supplierReference', \sprintf('A supplier reference is at most %d characters.', self::REFERENCE_MAX), reason: 'invalid_text', params: ['max' => self::REFERENCE_MAX]);
        }
        $this->supplierReference = '' === $reference ? null : $reference;
    }

    public function isEmpty(): bool
    {
        return null === $this->vendor && null === $this->supplierReference && null === $this->receivedOn;
    }
}
