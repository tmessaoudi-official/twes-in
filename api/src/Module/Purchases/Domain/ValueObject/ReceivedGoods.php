<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\ValueObject;

use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Shared\Domain\Service\TypedText;
use Symfony\Component\Uid\Uuid;

/**
 * What a receipt says arrived of one ordered line in one place: the quantity as typed, checked against the line's unit
 * when the order records it, the lot on the goods with the date they are used by when the label gives one, and what one
 * unit cost when it is known. Whether the product carries lots, and whether the place holds its stock, is the stock's to
 * say when it puts the goods there.
 */
final readonly class ReceivedGoods
{
    /** A lot as a label carries it: the stock's own rule, restated because the stock is another module. */
    public const int LOT_CODE_MAX = 40;

    public ?string $lotCode;
    public ?string $unitCost;

    /** @throws InvalidPurchaseOrder */
    public function __construct(public Uuid $orderLineId, public Uuid $locationId, public string $quantity, ?string $lotCode = null, public ?\DateTimeImmutable $lotExpiresOn = null, ?string $unitCost = null)
    {
        $code = TypedText::trimmed($lotCode ?? '');
        if (mb_strlen($code) > self::LOT_CODE_MAX) {
            throw new InvalidPurchaseOrder('lotCode', \sprintf('A lot code is at most %d characters.', self::LOT_CODE_MAX), 'lot_code', ['max' => self::LOT_CODE_MAX]);
        }
        if ('' === $code && null !== $lotExpiresOn) {
            throw new InvalidPurchaseOrder('lotExpiresOn', 'A use-by date belongs to a lot: name the lot.', 'expiry_needs_lot');
        }
        $this->lotCode = '' === $code ? null : $code;
        $this->unitCost = null === $unitCost || '' === TypedText::trimmed($unitCost) ? null : PurchaseOrderLineDetails::price(TypedText::trimmed($unitCost));
    }
}
