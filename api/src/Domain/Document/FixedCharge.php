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
     * The longest a label may be — a **derived** bound, flagged as such because no plan rules it.
     *
     * Added when the first write path landed, and for the reason round 14 recorded against `quantity`: this was the
     * only persisted string in this domain with **no bound at either end**, and a write endpoint is where an
     * unbounded client-supplied value becomes a real one rather than a theoretical one.
     *
     * Sixty-four, because this field is an IDENTIFIER and not prose — `stamp_duty`, `late_fee`, `eco_tax`. Display
     * text is the translation layer's, which is the whole point of the docblock below. If the developer needs more,
     * this is one constant.
     *
     * **NO MIGRATION FOLLOWS FROM IT, and that is the difference from `DocumentLine::MAX_SCALE`.** That bound
     * existed because `NUMERIC(21,6)` would REJECT a value the domain accepted — the domain-admits-what-persistence-
     * rejects mismatch. Here the column is `TEXT`, which accepts everything the domain accepts, so the bound is a
     * refusal we impose and not one the schema imposes on us. [Verified: `label TEXT NOT NULL` in
     * `migrations/Version20260801120000.php`.]
     */
    public const int MAX_LABEL_LENGTH = 64;

    /** A stable identifier, trimmed. See the constructor for why trimming happens on store. */
    private string $label;

    /**
     * @param string $label a stable identifier for the charge, e.g. `stamp_duty` — NOT display text, which
     *                      is the translation layer's job; a document rendered in Arabic must not carry a
     *                      French label baked into its stored figures
     *
     * @throws \InvalidArgumentException if the label is empty, longer than {@see self::MAX_LABEL_LENGTH}, or the
     *                                   amount is negative
     */
    public function __construct(
        string $label,
        private Money $amount,
    ) {
        // TRIMMED ON STORE, not only on validate. The first version validated `trim($label)` and stored
        // `$label`, so `'  stamp_duty  '` was accepted and kept its padding — and this docblock calls the label
        // "a stable identifier", of which `' stamp_duty'` and `'stamp_duty'` are two.
        $label = trim($label);
        $this->label = $label;

        if ('' === $label) {
            throw new \InvalidArgumentException(
                'A fixed charge needs a stable label. An unlabelled charge on an invoice is a figure '
                . 'nobody can explain to a customer or an auditor.',
            );
        }

        // MEASURED IN CHARACTERS, NOT BYTES. `strlen()` would make the bound depend on the alphabet — a 64-character
        // Arabic label is 128 bytes in UTF-8 — and a label refused for being in the wrong script is a defect, not a
        // bound. `mb_strlen` with an explicit encoding rather than the ambient default, which is configuration.
        if (mb_strlen($label, 'UTF-8') > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'Fixed charge label "%s" is %d characters; at most %d are allowed. This field is a stable '
                . 'IDENTIFIER that a translation layer resolves into display text, not the display text itself — '
                . 'so a label long enough to be a sentence is a sign it is being used as the wrong thing.',
                mb_substr($label, 0, self::MAX_LABEL_LENGTH, 'UTF-8') . '…',
                mb_strlen($label, 'UTF-8'),
                self::MAX_LABEL_LENGTH,
            ));
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
