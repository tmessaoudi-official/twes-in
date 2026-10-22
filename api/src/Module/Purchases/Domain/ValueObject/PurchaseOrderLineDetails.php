<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\ValueObject;

use App\Fiscal\Domain\Model\Unit;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Shared\Domain\Service\TypedText;
use Symfony\Component\Uid\Uuid;

/**
 * One line as it is written: which product is ordered, as it read then, how much of it in its unit, and the price the
 * vendor is expected to ask, net, when it is known. A quantity is positive and never finer than its unit counts; it is
 * kept with three decimals, the price with four. Only goods are ordered here: every line names a product, which is what
 * a receipt puts on the shelves.
 */
final readonly class PurchaseOrderLineDetails
{
    public const int DESCRIPTION_MAX = 5000;
    public const int REFERENCE_MAX = 64;
    public const int QUANTITY_DECIMALS = 3;
    public const int PRICE_DECIMALS = 4;
    private const string QUANTITY = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';
    private const string PRICE = '/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$/';

    public string $productReference;
    public string $description;
    public string $quantity;
    public ?string $unitPriceNet;

    /** @throws InvalidPurchaseOrder */
    public function __construct(public Uuid $productId, string $productReference, string $description, string $quantity, public Unit $unit, ?string $unitPriceNet)
    {
        $this->productReference = mb_substr(TypedText::trimmed($productReference), 0, self::REFERENCE_MAX);
        $description = TypedText::trimmed($description);
        if ('' === $description || mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidPurchaseOrder('description', \sprintf('A line says what it orders in 1 to %d characters.', self::DESCRIPTION_MAX));
        }
        $this->description = $description;
        $this->quantity = self::quantity(TypedText::trimmed($quantity), $unit);
        $this->unitPriceNet = null === $unitPriceNet || '' === TypedText::trimmed($unitPriceNet) ? null : self::price(TypedText::trimmed($unitPriceNet));
    }

    /** @return array{string, string, string, string, string, string|null} what two lines are compared on */
    public function values(): array
    {
        return [$this->productId->toRfc4122(), $this->productReference, $this->description, $this->quantity, $this->unit->getId()->toRfc4122(), $this->unitPriceNet];
    }

    /**
     * A positive quantity at three decimals, never finer than the unit counts.
     *
     * @throws InvalidPurchaseOrder
     */
    public static function quantity(string $quantity, Unit $unit): string
    {
        if (1 !== preg_match(self::QUANTITY, $quantity)) {
            throw new InvalidPurchaseOrder('quantity', 'A quantity is a decimal number with at most three decimals.');
        }
        [$units, $decimals] = [...explode('.', $quantity), ''];
        if ('' === trim($units.$decimals, '0')) {
            throw new InvalidPurchaseOrder('quantity', 'A line orders a positive quantity.');
        }
        if (\strlen(rtrim($decimals, '0')) > $unit->getDecimals()) {
            throw new InvalidPurchaseOrder('quantity', \sprintf('The unit %s counts with %d decimals.', $unit->getCode(), $unit->getDecimals()));
        }

        return $units.'.'.str_pad($decimals, self::QUANTITY_DECIMALS, '0');
    }

    /**
     * A price at four decimals, zero allowed: goods a vendor gives are still received.
     *
     * @throws InvalidPurchaseOrder
     */
    public static function price(string $price): string
    {
        if (1 !== preg_match(self::PRICE, $price)) {
            throw new InvalidPurchaseOrder('unitPriceNet', 'A price is a decimal number with at most four decimals.');
        }
        [$units, $decimals] = [...explode('.', $price), ''];

        return $units.'.'.str_pad($decimals, self::PRICE_DECIMALS, '0');
    }
}
