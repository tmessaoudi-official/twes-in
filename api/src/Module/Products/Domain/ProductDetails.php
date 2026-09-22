<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * What a product says about itself: its name and description, whether it is goods or a service, its net unit price
 * and cost price, and its barcode. Prices are decimal strings at four decimals, NUMERIC(14,4) (docs/SPEC.md § Money):
 * a unit price may be finer than the currency, the amounts computed from it are rounded to the currency's scale.
 * A price is never negative; a correction is a credit note.
 */
final readonly class ProductDetails
{
    public const int NAME_MAX = 200;
    public const int DESCRIPTION_MAX = 5000;
    public const int BARCODE_MAX = 64;
    public const int PRICE_DECIMALS = 4;
    private const string PRICE = '/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$/';
    private const string BARCODE = '/^[\x21-\x7E]{1,64}$/';

    public string $name;
    public ?string $description;
    public string $unitPriceNet;
    public ?string $costPrice;
    public ?string $barcode;

    /** @throws InvalidProduct */
    public function __construct(
        string $name,
        ?string $description,
        public ProductKind $kind,
        string $unitPriceNet,
        ?string $costPrice = null,
        ?string $barcode = null,
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
        $barcode = trim($barcode ?? '');
        if ('' !== $barcode && 1 !== preg_match(self::BARCODE, $barcode)) {
            throw new InvalidProduct('barcode', \sprintf('A barcode is 1 to %d printable characters without spaces.', self::BARCODE_MAX));
        }
        if ('' !== $barcode && !self::gtinCheckDigitHolds($barcode)) {
            throw new InvalidProduct('barcode', 'This barcode has the shape of an EAN-13, EAN-8 or UPC code and its check digit does not match.');
        }
        $this->barcode = '' === $barcode ? null : $barcode;
    }

    /**
     * True unless the code CLAIMS a GTIN shape and fails it (docs/SPEC.md § 7, 2026-09-17). A code that claims
     * nothing — an internal reference, a Code 128 string, any length but 8, 12 or 13 digits — is kept as typed,
     * so this answers true for it.
     *
     * One algorithm covers all three lengths, because EAN-13, UPC-A and EAN-8 share it: weights of 3 and 1
     * alternating from the digit immediately left of the check digit, and the check digit is what brings the
     * total to a multiple of ten. Writing three of these would be three chances to get one wrong.
     *
     * Knowingly not detected: UPC-E is eight digits under a different rule, so a UPC-E code fails the EAN-8 check
     * and is refused rather than kept. That is recorded as a decision in § 7, not left as a surprise.
     */
    private static function gtinCheckDigitHolds(string $barcode): bool
    {
        if (!\in_array(\strlen($barcode), [8, 12, 13], true) || 1 !== preg_match('/^[0-9]+$/', $barcode)) {
            return true;
        }

        $digits = array_map(intval(...), str_split($barcode));
        $check = array_pop($digits);
        $sum = 0;
        // Right-aligned: the weight depends on the distance from the check digit, never on the code's length.
        foreach (array_reverse($digits) as $place => $digit) {
            $sum += $digit * (0 === $place % 2 ? 3 : 1);
        }

        return $check === (10 - $sum % 10) % 10;
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
            'barcode' => $this->barcode,
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
