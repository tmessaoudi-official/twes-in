<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Product;

use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PricedBy;
use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\Identifier;

/**
 * A catalogue item: what it is called, what it costs, what it sells for, and at what VAT rate.
 *
 * ## It OWNS almost nothing about pricing, and that is the design
 *
 * Every rule about cost, profit rate and net price already lives in {@see ProductPricing} — which field the user
 * typed, that the typed one is never recomputed, that a cost change preserves the RATE and moves the PRICE, and
 * that a zero old cost has no rate to preserve. Those are the F4 rulings in
 * `docs/plans/pricing-and-documents.plan.md`, and they were built and tested in Wave 0. This aggregate holds a
 * `ProductPricing` and delegates; it does not re-express one line of that arithmetic.
 *
 * **A SECOND IMPLEMENTATION OF THOSE RULES HERE WOULD BE THE DEFECT, not the convenience.** `CLAUDE.md`
 * § Gotchas 2026-08-07 records a guard added to a repository that turned out to be a second copy of a rule the
 * mapper already enforced — with a *worse* message — and the mutant that killed it is what revealed the
 * duplicate. The same shape is available here and is deliberately not taken.
 *
 * ## What it adds
 *
 * Identity, a name, an optional stock-keeping unit, and the **VAT rate** — which pricing genuinely does not
 * carry. {@see ProductPricing} models cost → net; VAT is applied to the net afterwards
 * (`PriceCalculator::vat()`), and the rate to apply is a property of the ITEM (a foodstuff and a service are
 * taxed differently in the same company), so it belongs here rather than in company settings.
 *
 * ## The snapshot rule, which this aggregate exists to serve
 *
 * *"The net price is copied onto the invoice line when the line is created. A later change to the product's cost
 * or rate must never alter an already-issued document."* — F4, non-negotiable. Nothing here reaches into a
 * document, and nothing in a document holds a product id: a `DocumentLine` carries a `Money` and a `Rate`, both
 * by value. That is what makes the rule structural rather than something to remember.
 *
 * ## What is deliberately NOT a field
 *
 * **No description**, and no unit of measure. Both are plausible catalogue fields and neither has a consumer:
 * `DocumentLine` has no description, and nothing computes with a unit. Adding them now would be storage with no
 * reader, which is the same reason {@see \Twes\UI\Http\ApiResource\PostalAddressResource} has no `region` — a
 * field is added in the change that needs it, with a migration.
 */
final readonly class Product
{
    /**
     * The name's ceiling, matching every other name in this domain.
     *
     * CHARACTERS rather than bytes, so a name in Arabic is bounded the same way as one in French.
     */
    public const int MAX_NAME_LENGTH = 140;

    /**
     * The stock-keeping unit's ceiling.
     *
     * Shorter than a name because a SKU is a code somebody types and reads aloud, not a sentence. 64 is generous
     * for every scheme this product is likely to meet (EAN-13, a UPC, an internal reference).
     */
    public const int MAX_SKU_LENGTH = 64;

    /**
     * NOTHING IS PROMOTED, and that is forced rather than stylistic: `$name` and `$sku` are NORMALISED before
     * they are stored (trimmed, and a blank SKU becomes absent), and a promoted `readonly` property cannot be
     * reassigned in the constructor body. `$id`, `$pricing` and `$vatRate` are declared alongside them so that
     * one class does not mix two spellings of the same thing.
     */
    private string $id;
    private string $name;
    private ?string $sku;
    private ProductPricing $pricing;
    private Rate $vatRate;

    /**
     * @throws \InvalidArgumentException if the id is not a canonical UUID, or the name is blank or too long
     */
    private function __construct(
        string $id,
        string $name,
        ?string $sku,
        ProductPricing $pricing,
        Rate $vatRate,
    ) {
        // THE ID IS NOT NORMALISED, only refused. It is a key: accepting an uppercase spelling and storing a
        // lowercase one would make the value a caller sent and the value stored differ, and two spellings of one
        // id are two rows in any store that has not been told they are the same.
        if (!Identifier::isWellFormed($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'A product id must be a canonical lowercase-hyphenated UUID, got "%s". Uppercase and the braced '
                . 'form are refused rather than normalised, because an identifier is a key and not a display '
                . 'value.',
                $id,
            ));
        }

        $this->id = $id;
        $this->name = self::validatedName($name);
        $this->sku = self::validatedSku($sku);
        $this->pricing = $pricing;
        $this->vatRate = $vatRate;
    }

    /**
     * A new product.
     *
     * @throws \InvalidArgumentException if the id is not a canonical UUID, or the name is blank or too long
     */
    public static function create(string $id, string $name, ProductPricing $pricing, Rate $vatRate): self
    {
        return new self($id, $name, null, $pricing, $vatRate);
    }

    /**
     * Rebuild from what a repository read back.
     *
     * **IT VALIDATES EXACTLY AS `create()` DOES, and that is deliberate rather than wasteful.** A rehydration
     * path that trusts its input is how a row that should never have been written becomes an aggregate nobody
     * questions — and `CLAUDE.md` § Gotchas 2026-08-07 records the opposite direction as a real defect too: a
     * `catch (\InvalidArgumentException)` around a lookup swallowed a HYDRATION failure and reported a corrupt
     * row as a 404. A refusal here is loud, which is what lets a provider let it become a 500.
     *
     * @throws \InvalidArgumentException if the id is not a canonical UUID, or the name is blank or too long
     */
    public static function fromPersistedState(
        string $id,
        string $name,
        ?string $sku,
        ProductPricing $pricing,
        Rate $vatRate,
    ): self {
        return new self($id, $name, $sku, $pricing, $vatRate);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function pricing(): ProductPricing
    {
        return $this->pricing;
    }

    /** The VAT rate to place on a line created from this product. */
    public function vatRate(): Rate
    {
        return $this->vatRate;
    }

    /** The cost, which is the one pricing field that is always present and always authoritative. */
    public function cost(): Money
    {
        return $this->pricing->cost();
    }

    /** Which of the profit rate and the net price the user actually typed. */
    public function authoredBy(): PricedBy
    {
        return $this->pricing->authoredBy();
    }

    /**
     * @throws \InvalidArgumentException if the name is blank or too long
     */
    public function withName(string $name): self
    {
        return new self($this->id, $name, $this->sku, $this->pricing, $this->vatRate);
    }

    /**
     * @throws \InvalidArgumentException if the SKU is too long
     */
    public function withSku(?string $sku): self
    {
        return new self($this->id, $this->name, $sku, $this->pricing, $this->vatRate);
    }

    /**
     * Replace the pricing wholesale.
     *
     * **THE WHOLE `ProductPricing` RATHER THAN `withCost()` / `withProfitRate()` / `withNetPrice()` FORWARDERS,
     * and the reason is authorship.** Each of those transitions has its own semantics — a cost change preserves
     * the rate and moves the price, a typed price transfers authorship to itself — and three delegating methods
     * here would be three more places for that behaviour to be described, and eventually mis-described. The
     * caller builds the pricing it wants using `ProductPricing`'s own vocabulary, where the rules live and are
     * tested.
     */
    public function withPricing(ProductPricing $pricing): self
    {
        return new self($this->id, $this->name, $this->sku, $pricing, $this->vatRate);
    }

    public function withVatRate(Rate $vatRate): self
    {
        return new self($this->id, $this->name, $this->sku, $this->pricing, $vatRate);
    }

    /**
     * @throws \InvalidArgumentException if the name is blank or longer than {@see self::MAX_NAME_LENGTH}
     */
    private static function validatedName(string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException(
                'A product needs a name. It is what appears on the invoice line a client reads, so a nameless '
                . 'catalogue item is a charge nobody can account for.',
            );
        }

        $length = mb_strlen($name, 'UTF-8');

        if ($length > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'The product name is %d characters; at most %d are allowed. The bound counts CHARACTERS rather '
                . 'than bytes, so a name in Arabic is measured the same way as one in French.',
                $length,
                self::MAX_NAME_LENGTH,
            ));
        }

        return $name;
    }

    /**
     * @throws \InvalidArgumentException if the SKU is longer than {@see self::MAX_SKU_LENGTH}
     */
    private static function validatedSku(?string $sku): ?string
    {
        if (null === $sku) {
            return null;
        }

        $sku = trim($sku);

        // BLANK NORMALISES TO ABSENT, matching every other optional string in this domain. A product with `""`
        // for a SKU and one with none are the same product, and storing two spellings of "no SKU" makes every
        // later lookup have to know about both.
        if ('' === $sku) {
            return null;
        }

        $length = mb_strlen($sku, 'UTF-8');

        if ($length > self::MAX_SKU_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'The SKU is %d characters; at most %d are allowed. A stock-keeping unit is a code somebody types '
                . 'and reads aloud rather than a description.',
                $length,
                self::MAX_SKU_LENGTH,
            ));
        }

        return $sku;
    }
}
