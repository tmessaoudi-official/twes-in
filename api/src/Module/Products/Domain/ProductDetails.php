<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * What a product says about itself: its name and description, whether it is goods or a service, its net unit price
 * and cost price. Its codes are rows of their own (`ProductBarcode`). Prices are decimal strings at four decimals, NUMERIC(14,4) (docs/SPEC.md § Money):
 * a unit price may be finer than the currency, the amounts computed from it are rounded to the currency's scale.
 * A price is never negative; a correction is a credit note.
 */
final readonly class ProductDetails
{
    public const int NAME_MAX = 200;
    public const int DESCRIPTION_MAX = 5000;
    public const int PRICE_DECIMALS = 4;
    private const string PRICE = '/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$/';

    public string $name;
    public ?string $description;
    public string $unitPriceNet;
    public ?string $costPrice;

    /** @throws InvalidProduct */
    public function __construct(
        string $name,
        ?string $description,
        public ProductKind $kind,
        string $unitPriceNet,
        ?string $costPrice = null,
    ) {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidProduct('name', \sprintf('A product is named in 1 to %d characters.', self::NAME_MAX));
        }
        $this->name = $name;
        $description = trim($description ?? '');
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidProduct('description', \sprintf('A product is described in at most %d characters.', self::DESCRIPTION_MAX));
        }
        $this->description = '' === $description ? null : $description;
        $this->unitPriceNet = self::price('unitPriceNet', $unitPriceNet);
        $costPrice = trim($costPrice ?? '');
        $this->costPrice = '' === $costPrice ? null : self::price('costPrice', $costPrice);
    }

    /** The same product at another cost: what a writer who may not read costs sends is given the stored one. */
    public function withCostPrice(?string $costPrice): self
    {
        return new self($this->name, $this->description, $this->kind, $this->unitPriceNet, $costPrice);
    }

    /** @return list<string> the fields whose values differ from the other's, in the order a form shows them */
    public function differencesFrom(self $other): array
    {
        $mine = $this->values();
        $theirs = $other->values();

        return array_values(array_filter(array_keys($mine), static fn (string $field): bool => $mine[$field] !== $theirs[$field]));
    }

    /** @return array<string, string|null> */
    private function values(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind->value,
            'unitPriceNet' => $this->unitPriceNet,
            'costPrice' => $this->costPrice,
        ];
    }

    private static function price(string $field, string $price): string
    {
        $price = trim($price);
        if (1 !== preg_match(self::PRICE, $price)) {
            throw new InvalidProduct($field, 'A price is a decimal number from 0 to 9999999999.9999, with at most four decimals.');
        }
        [$units, $decimals] = [...explode('.', $price), ''];

        return $units.'.'.str_pad($decimals, self::PRICE_DECIMALS, '0');
    }
}
