<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of an invoice being created. **Input only** — the response side is {@see InvoiceLineResource}.
 *
 * A SEPARATE class from the output resource rather than the same one reused, because the two are genuinely
 * different shapes: the output carries `position`, `net` and `vat`, all of which are COMPUTED. A single class would
 * either accept computed figures from a client — which is a client dictating a tax figure — or declare them
 * optional and leave a reader unable to tell which fields are inputs.
 *
 * **EVERY NUMBER IS DECLARED `string`, AND THAT DECLARATION IS THE ENFORCEMENT.** A client sending
 * `"quantity": 1.5` — a JSON number — is refused by the Serializer rather than silently coerced, because JSON's
 * only number type is a double and `0.1` is not representable in one. `CLAUDE.md` records money-is-never-a-float as
 * unfixable-later; this is where the rule is applied at the boundary, and `InvoiceWriteSurfaceTest` pins the
 * refusal rather than trusting the declaration.
 *
 * **REQUIRED-NESS IS THE CONSTRUCTOR'S JOB, not a validator's.** Every field is a promoted constructor parameter
 * with no default, so a payload omitting one fails deserialization with a message naming the parameter — which is a
 * better error than a validator's "this value should not be blank" on a field the client never sent. The
 * constraints below are then only about the VALUE.
 *
 * **WHAT IS VALIDATED HERE AND WHAT IS DELIBERATELY NOT.** `CLAUDE.md` § "The Symfony ecosystem is the ONLY
 * vocabulary" rules that edge validation and the domain's own refusal are *both* required, never either: "validation
 * at the edge is a message-quality feature; the invariant lives in the value object". So the constraint below is
 * STRUCTURAL — is this a decimal at all — and every real bound (scale, magnitude, negativity, and the
 * representability of `quantity × unitNet`) stays in {@see \Twes\Domain\Document\DocumentLine}, which is the only
 * place that knows the currency's scale and the product's bound.
 *
 * The message is Symfony's own default on purpose. Symfony ships `validators.fr.xlf` and `validators.ar.xlf`, so a
 * French or Arabic caller gets a translated message for free; a custom message would need our own catalogue in the
 * `validators` domain, and `CLAUDE.md` § "Translation keys" records that nothing resolves that catalogue yet.
 * Carrying keys on domain refusals is its own deliverable — it needs a typed exception per refusal first, because a
 * dozen distinct keyed refusals currently share a bare `\InvalidArgumentException`.
 */
final readonly class NewInvoiceLineInput
{
    /**
     * A plain decimal, with an optional sign and an optional fractional part.
     *
     * Deliberately permissive about *how many* decimals: that bound is `DocumentLine::MAX_SCALE`, defined where the
     * column type is derived from it, and duplicating it here would be a second copy of one rule — the shape this
     * repository has recorded drifting four times. What it does refuse is text that is not a decimal at all: `abc`,
     * `1e5` (exponent notation, which `Decimal::isWellFormed()` also refuses), `1,5`, and an empty string.
     *
     * `/D` for the same reason `DocumentIdentity` needs it: without it PCRE's `$` also matches before a final
     * newline, so `"2\n"` would pass a check meant to accept only `2`.
     */
    private const string DECIMAL = '/^-?\d+(\.\d+)?$/D';

    public function __construct(
        /** How many. Fractional is normal — 2.5 hours, 0.750 kg — which is why it is a decimal and not an int. */
        #[Assert\Regex(pattern: self::DECIMAL)]
        public string $quantity,
        /** The net unit price, in the invoice's currency. Bounds and negativity belong to `Money` and `DocumentLine`. */
        #[Assert\Regex(pattern: self::DECIMAL)]
        public string $unitNet,
        /**
         * The VAT rate as a PERCENTAGE, not a fraction: `19` means 19%, never 1900%.
         *
         * `0` is legitimate and must not be treated as absent — an exempt or zero-rated line is ordinary in both
         * French and Tunisian invoicing. That is why nothing here uses `NotBlank`, which considers `'0'` empty:
         * absence is the constructor's business and `'0'` is a value.
         */
        #[Assert\Regex(pattern: self::DECIMAL)]
        public string $vatRate,
    ) {}
}
