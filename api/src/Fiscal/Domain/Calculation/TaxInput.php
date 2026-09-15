<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

use App\Fiscal\Domain\TaxKind;

/** One tax component as a document carries it: a snapshot of the rate or amount at the time of the document. */
final readonly class TaxInput
{
    private function __construct(
        public string $code,
        public TaxKind $kind,
        public ?Rate $rate,
        public ?string $amount,
        public ?string $threshold,
        public bool $entersVatBase,
    ) {
    }

    /** A rate on each line's net; a levy that enters the VAT base is computed first and joins the others' base. */
    public static function percentage(string $code, Rate $rate, bool $entersVatBase = false): self
    {
        return new self($code, TaxKind::PercentageLine, $rate, null, null, $entersVatBase);
    }

    public static function fixed(string $code, string $amount): self
    {
        if (Decimal::of($amount)->compare(0) < 0) {
            throw new InvalidDocument(\sprintf('The fixed charge %s is never negative; a credit note carries the sign.', $code));
        }

        return new self($code, TaxKind::FixedDocument, null, $amount, null, false);
    }

    public static function withholding(string $code, Rate $rate, string $threshold): self
    {
        if (Decimal::of($threshold)->compare(0) < 0) {
            throw new InvalidDocument(\sprintf('The threshold of %s is never negative.', $code));
        }

        return new self($code, TaxKind::WithholdingTotal, $rate, null, $threshold, false);
    }

    /**
     * A withholding already charged by the document being corrected: it applies whatever this document's own total
     * comes to, so the corrections of a document add up to it (docs/SPEC.md § 7, 2026-09-15).
     */
    public static function withholdingAsCharged(string $code, Rate $rate): self
    {
        return new self($code, TaxKind::WithholdingTotal, $rate, null, '0', false);
    }

    public function sameAs(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->entersVatBase === $other->entersVatBase
            && (null === $this->rate ? null === $other->rate : null !== $other->rate && $this->rate->equals($other->rate))
            && $this->amount === $other->amount
            && $this->threshold === $other->threshold;
    }
}
