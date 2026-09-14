<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\Unit;
use App\Module\Products\Domain\Product;
use Symfony\Component\Uid\Uuid;

/**
 * One line as it is written: what is sold, how much of it in which unit, its net unit price, a discount rate reducing
 * its tax base, and the taxes charged on it. A quantity is positive and never finer than its unit counts; the quantity
 * is kept with three decimals, the price with four, the discount as a percentage with three. Only a line tax sits on a
 * line, each at most once. A line drafted from a delivery note names the delivery note line it invoices.
 */
final readonly class InvoiceLineDetails
{
    public const int DESCRIPTION_MAX = 5000;
    public const int QUANTITY_DECIMALS = 3;
    public const int PRICE_DECIMALS = 4;
    private const string QUANTITY = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';
    private const string PRICE = '/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$/';
    private const string RATE = '/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,3})?$/';

    public string $description;
    public string $quantity;
    public string $unitPriceNet;
    /** A percentage with three decimals; null for no discount. */
    public ?string $discountRate;
    /** @var list<TaxComponent> */
    public array $taxes;

    /**
     * @param list<TaxComponent> $taxes
     * @param Uuid|null          $sourceDeliveryNoteLineId the delivery note line it invoices; null for a line written by hand
     *
     * @throws InvalidInvoice
     */
    public function __construct(public ?Product $product, string $description, string $quantity, public Unit $unit, string $unitPriceNet, ?string $discountRate, array $taxes, public ?Uuid $sourceDeliveryNoteLineId = null)
    {
        $description = trim($description);
        if ('' === $description || mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidInvoice('description', \sprintf('A line says what it sells in 1 to %d characters.', self::DESCRIPTION_MAX));
        }
        $this->description = $description;
        $this->quantity = self::quantity(trim($quantity), $unit);
        $this->unitPriceNet = self::price(trim($unitPriceNet));
        $this->discountRate = self::rate(trim($discountRate ?? ''));

        $ids = [];
        foreach ($taxes as $tax) {
            if (TaxKind::PercentageLine !== $tax->getKind()) {
                throw new InvalidInvoice('taxComponentIds', \sprintf('%s is charged on a document, not on a line.', $tax->getCode()));
            }
            $id = $tax->getId()->toRfc4122();
            if (isset($ids[$id])) {
                throw new InvalidInvoice('taxComponentIds', \sprintf('A line carries %s once.', $tax->getCode()));
            }
            $ids[$id] = true;
        }
        $this->taxes = $taxes;
    }

    /** @return array{string|null, string, string, string, string, string|null, list<string>, string|null} what two lines are compared on */
    public function values(): array
    {
        return [
            $this->product?->getId()->toRfc4122(),
            $this->description,
            $this->quantity,
            $this->unit->getId()->toRfc4122(),
            $this->unitPriceNet,
            $this->discountRate,
            array_map(static fn (TaxComponent $tax): string => $tax->getId()->toRfc4122(), $this->taxes),
            $this->sourceDeliveryNoteLineId?->toRfc4122(),
        ];
    }

    private static function quantity(string $quantity, Unit $unit): string
    {
        if (1 !== preg_match(self::QUANTITY, $quantity)) {
            throw new InvalidInvoice('quantity', 'A quantity is a decimal number with at most three decimals.');
        }
        [$units, $decimals] = [...explode('.', $quantity), ''];
        if ('' === trim($units.$decimals, '0')) {
            throw new InvalidInvoice('quantity', 'A line sells a positive quantity.');
        }
        if (\strlen(rtrim($decimals, '0')) > $unit->getDecimals()) {
            throw new InvalidInvoice('quantity', \sprintf('The unit %s counts with %d decimals.', $unit->getCode(), $unit->getDecimals()));
        }

        return $units.'.'.str_pad($decimals, self::QUANTITY_DECIMALS, '0');
    }

    private static function price(string $price): string
    {
        if (1 !== preg_match(self::PRICE, $price)) {
            throw new InvalidInvoice('unitPriceNet', 'A price is a decimal number from 0 to 9999999999.9999, with at most four decimals.');
        }
        [$units, $decimals] = [...explode('.', $price), ''];

        return $units.'.'.str_pad($decimals, self::PRICE_DECIMALS, '0');
    }

    private static function rate(string $rate): ?string
    {
        if ('' === $rate) {
            return null;
        }
        if (1 !== preg_match(self::RATE, $rate) || Decimal::of($rate)->compare(100) > 0) {
            throw new InvalidInvoice('discountRate', 'A discount is a percentage from 0 to 100 with at most three decimals.');
        }

        return Decimal::format(Decimal::of($rate), 3);
    }
}
