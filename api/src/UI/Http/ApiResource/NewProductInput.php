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
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Twes\Domain\Product\Product;

/**
 * The body of `POST /api/products`. **Input only** — the response is a {@see ProductResource}.
 *
 * ## Exactly one of `profitRate` and `netPrice`, and that is F4 rather than a form convenience
 *
 * A product is priced by a typed profit rate OR a typed selling price. The one the user typed is stored exactly
 * and never recomputed; the other is derived for display with no authority. That ruling exists because the
 * alternative deleted money: a cost of `10 000.000` with a typed price of `10 000.001` implies a rate needing
 * SEVEN decimals, and rebuilding the price from a six-decimal rate silently dropped the millime.
 *
 * **SO SENDING BOTH IS REFUSED, NOT MERGED.** A payload carrying both is a caller asserting two things that can
 * disagree, and picking one would make the API decide an authorship question the user is the only one who can
 * answer. Sending neither is refused for the plainer reason that a product with a cost and no price is not
 * priced. {@see \Twes\Domain\Pricing\ProductPricing} has exactly two named constructors and no way to build one
 * without choosing, so the choice has to be made HERE, at the only layer that knows which field arrived.
 *
 * ## Every monetary and rate field is a decimal STRING
 *
 * Never a JSON number, in both directions. JSON has one number type and it is a double, so `0.1` is not
 * representable and `0.100 TND` stops being exact — and the rate is worse, needing ten decimal places as its
 * CANONICAL form so three tiers can compare it as a string. A JSON number here is answered with a 422 naming
 * the field, which needed `COLLECT_DENORMALIZATION_ERRORS` on the operation.
 *
 * ## Validation
 *
 * Structural, and deliberately mirroring the domain's bounds rather than substituting for them — the same rule
 * {@see NewClientInput} states. The DECIMAL SHAPES are checked here because `Money::of()` and
 * `Rate::fromPercentage()` own what is representable in a given currency and at a given scale, which a regex
 * cannot know; what the regex buys is a 422 naming the field instead of a domain message about a string.
 */
#[Assert\Callback('assertExactlyOnePriceFieldWasTyped')]
final readonly class NewProductInput
{
    /**
     * A decimal, optionally signed, with an optional fractional part.
     *
     * Deliberately PERMISSIVE about how many decimals: how many are representable depends on the currency (TND
     * has three, EUR two) and on the field (a rate carries twelve), and those rules live in `Money` and `Rate`.
     * This only rejects what is not a decimal at all — `1,5`, `1e3`, `abc` — which is the class of error a
     * client can fix from a message naming the field.
     */
    private const string DECIMAL = '/^-?\d+(\.\d+)?$/D';

    public function __construct(
        /** What appears on the invoice line a client reads. Required. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Product::MAX_NAME_LENGTH)]
        public string $name,
        /** ISO 4217 alpha-3, e.g. `TND`, `EUR`. `Currency::of()` owns which codes exist and their scales. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(exactly: 3)]
        #[Assert\Regex(pattern: '/^[A-Z]{3}$/D')]
        public string $currency,
        /** What the item cost, as a decimal string in `currency`. Negative is refused by the domain. */
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::DECIMAL)]
        public string $cost,
        /**
         * The VAT rate as a PERCENTAGE — `19`, not `0.19`.
         *
         * Required, because a product exists to be put on an invoice line and a line needs one. There is no
         * company-wide default to fall back to: a foodstuff and a service are taxed differently inside one
         * company, which is exactly why the rate is on the item.
         */
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::DECIMAL)]
        public string $vatRate,
        /** The stock-keeping unit. Optional, and deliberately not unique. */
        #[Assert\Length(max: Product::MAX_SKU_LENGTH)]
        public ?string $sku = null,
        /**
         * The profit rate as a PERCENTAGE. Send this OR `netPrice`, never both.
         *
         * **A NEGATIVE RATE IS ALLOWED**, and that is a ruling rather than an oversight: F4 states that selling
         * below cost is real — clearance, a loss-leader — and must be surfaced rather than clamped to zero.
         */
        #[Assert\Regex(pattern: self::DECIMAL)]
        public ?string $profitRate = null,
        /** The selling price before VAT, as a decimal string in `currency`. Send this OR `profitRate`. */
        #[Assert\Regex(pattern: self::DECIMAL)]
        public ?string $netPrice = null,
    ) {}

    /**
     * **THE EXACTLY-ONE RULE, as a callback rather than an `Assert\Expression`.**
     *
     * `Assert\Expression` would express it in one line and needs `symfony/expression-language`, which is not a
     * dependency — adding a package to state a two-branch condition is not a trade this project makes, and
     * `LICENSING.md` requires every dependency to be argued and recorded. A callback is core validator.
     *
     * **THE VIOLATION IS ATTACHED TO A FIELD PATH, not to the object.** A message with no path leaves a form
     * unable to highlight anything, which is the difference between an error a user can act on and one they
     * cannot. Both fields are named for the "both" case, since either one is the one to remove.
     */
    public function assertExactlyOnePriceFieldWasTyped(ExecutionContextInterface $context): void
    {
        $rateGiven = null !== $this->profitRate;
        $priceGiven = null !== $this->netPrice;

        if ($rateGiven === $priceGiven) {
            $message = $rateGiven
                ? 'Send a profit rate or a net price, not both: they can disagree, and which one the user typed '
                    . 'is what decides which is authoritative.'
                : 'Send a profit rate or a net price. A product with a cost and neither is not priced.';

            foreach (['profitRate', 'netPrice'] as $field) {
                $context->buildViolation($message)->atPath($field)->addViolation();
            }
        }
    }
}
