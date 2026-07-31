<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document;

use Twes\Domain\Money\Money;

/**
 * An absolute document-scope charge — Tunisia's stamp duty being the worked example.
 *
 * **Not a special case anywhere in the domain.** The stamp duty is `0.100 TND`, configured per company, and
 * it is modelled as one instance of a generic charge so that any other jurisdiction's equivalent works with
 * no domain change. `pricing-and-documents.plan.md` § "The charge model" rules this explicitly: a document
 * carries N charges, each with a scope and a type, rather than the domain carrying a `stamp_duty` column.
 *
 * **It is in the total and in NO VAT base.** Taxing it would be a silent overcharge on every single invoice,
 * and it is the kind of error nobody notices for a year — so `DocumentTotalsTest` asserts the exclusion
 * directly rather than inferring it from a total that happens to match.
 *
 * `0.100 TND` is also unrepresentable in a two-decimal currency, which is why it appears in this domain's
 * tests from day one rather than as a late edge case.
 */
final readonly class FixedCharge
{
    /**
     * @param string $label a stable identifier for the charge, e.g. `stamp_duty` — NOT display text, which
     *                      is the translation layer's job; a document rendered in Arabic must not carry a
     *                      French label baked into its stored figures
     *
     * @throws \InvalidArgumentException if the label is empty or the amount is negative
     */
    public function __construct(
        private string $label,
        private Money $amount,
    ) {
        if ('' === trim($label)) {
            throw new \InvalidArgumentException(
                'A fixed charge needs a stable label. An unlabelled charge on an invoice is a figure '
                . 'nobody can explain to a customer or an auditor.',
            );
        }

        if ($amount->isNegative()) {
            throw new \InvalidArgumentException(\sprintf(
                'Fixed charge "%s" is negative (%s). A reduction is a discount, which is a different kind '
                . 'of charge with a different application order — not a negative fixed charge.',
                $label,
                $amount->amount(),
            ));
        }
    }

    public function label(): string
    {
        return $this->label;
    }

    public function amount(): Money
    {
        return $this->amount;
    }
}
