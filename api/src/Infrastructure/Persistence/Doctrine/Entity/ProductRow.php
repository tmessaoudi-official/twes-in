<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Twes\Domain\Pricing\PricedBy;
use Twes\Domain\Product\Product;

/**
 * A product ROW — the mapped counterpart of {@see Product}.
 *
 * A SEPARATE class from the aggregate, for the reason `CLAUDE.md` § Architecture gives and every other `*Row`
 * here repeats: the domain types are `final readonly` with mutators returning a new instance, and Doctrine's
 * unit of work is the opposite by construction — one mutable instance per row, diffed against a snapshot.
 *
 * **WHAT THIS MAPPING IS FOR, since the repository writes with DBAL and never constructs one:**
 * `doctrine:schema:validate --skip-sync` proving the mapping agrees with itself, typed rows for anything that
 * later wants them, and a future `migrations:diff` having a truthful starting point. That last one is why a
 * width here is never a guess — `CLAUDE.md` § Gotchas records `CompanySettingsRow` declaring `length: 32`
 * against a `varchar(14)` column, and the mapping is the artefact a diff generates FROM, so an overstated width
 * propagates into a migration rather than being caught by one.
 *
 * **THE PRICING IS THREE NULLABLE COLUMNS PLUS A DISCRIMINATOR, and that is F4 rather than a modelling choice.**
 * Only the field the user TYPED is stored; the other is derived for display with no authority. So exactly one of
 * `profitRate` and `netPriceAmount` is present, matched to `authoredBy`, and the migration's
 * `product_stores_only_the_authored_field` CHECK is what stops a row carrying both or neither. Storing both is
 * the millime-deleting defect F4 exists to prevent: two columns that must agree eventually do not, and the
 * casualty is the typed one.
 *
 * **This table is TENANT-OWNED.** A catalogue with costs in it is a company's margin structure, which is exactly
 * what a competitor would want, so it carries the three RLS statements from `policySqlFor()` like every other
 * tenant-owned table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product')]
class ProductRow
{
    /**
     * The tenant, and the leading half of the primary key.
     *
     * Leading rather than merely present: uniqueness is checked with row security BYPASSED, so a key omitting
     * the tenant is enforced across every tenant at once — for a product id, an existence oracle over every
     * tenant's catalogue.
     */
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'uuid')]
    public Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    /**
     * What appears on the invoice line a client reads.
     *
     * `text` rather than `varchar(n)`: the domain bounds it at `Product::MAX_NAME_LENGTH` and the migration
     * repeats that bound as a CHECK derived from the same constant, so a column type would add nothing but a
     * second number to keep in step. `ClientRow::$name` makes the same call for the same reason.
     */
    #[ORM\Column(type: 'text')]
    public string $name;

    /**
     * The stock-keeping unit — nullable, because not every catalogue uses one.
     *
     * `varchar` here rather than `text`, unlike the name, because for a SKU the LENGTH is the whole constraint:
     * there is no format rule to express, so the width carries the entire bound and matching the domain constant
     * is what keeps the two in step.
     */
    #[ORM\Column(type: 'string', length: Product::MAX_SKU_LENGTH, nullable: true)]
    public ?string $sku;

    /**
     * ONE currency for the whole row, not one per amount.
     *
     * `CLAUDE.md` § Architecture requires a companion currency beside every persisted amount, because a bare
     * `NUMERIC` cannot reconstitute a `Money`. One column satisfies it here because `ProductPricing` refuses a
     * net price in a different currency from the cost, so a second column could only ever hold the same value or
     * contradict this one.
     */
    #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
    public string $currency;

    /**
     * What the item cost us. Always present and always authoritative — it is the one pricing field that is never
     * derived, under either authorship.
     */
    #[ORM\Column(name: 'cost_amount', type: 'decimal', precision: 19, scale: 4)]
    public string $costAmount;

    /**
     * Which of the two price fields the user typed, and therefore which one may never be recomputed.
     *
     * The width is `PricedBy::MAX_BACKED_VALUE_LENGTH`, the same constant the migration derives its
     * `varchar(n)` from — written before this column existed, precisely so this mapping and that migration
     * cannot disagree the way `CompanySettingsRow` and `Version20260820120000` did.
     */
    #[ORM\Column(name: 'authored_by', type: 'string', length: PricedBy::MAX_BACKED_VALUE_LENGTH)]
    public string $authoredBy;

    /**
     * The typed profit rate, or null when the user typed a price instead.
     *
     * `27, 12` is `Rate::MAX_INTEGER_DIGITS + Rate::FRACTION_SCALE` and `Rate::FRACTION_SCALE`, matching
     * `document_line.vat_rate`. Twelve decimals is not decoration: F4 records a cost of `10 000.000` with a
     * typed price of `10 000.001` implying a rate that needs SEVEN, and a six-decimal rate rounding it to zero
     * and deleting the millime on the next cost change.
     *
     * **DELIBERATELY UNCONSTRAINED IN SIGN**, unlike the two amounts: F4 rules that selling below cost is real —
     * clearance, a loss-leader — and must be surfaced rather than clamped to zero.
     */
    #[ORM\Column(name: 'profit_rate', type: 'decimal', precision: 27, scale: 12, nullable: true)]
    public ?string $profitRate;

    /** The typed selling price, or null when the user typed a rate instead. */
    #[ORM\Column(name: 'net_price_amount', type: 'decimal', precision: 19, scale: 4, nullable: true)]
    public ?string $netPriceAmount;

    /**
     * The VAT rate to place on a line created from this product.
     *
     * On the ITEM rather than in company settings, because a foodstuff and a service are taxed differently
     * inside one company. It is copied onto a document line by value, which is what makes the F4 snapshot rule
     * structural: a later change here cannot reach an issued document, because the document holds no product id.
     */
    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 27, scale: 12)]
    public string $vatRate;

    /**
     * **EVERY COLUMN IS A CONSTRUCTOR PARAMETER, and that is a rule this project learned rather than a style.**
     *
     * `phpstan.neon.dist` turns on `checkUninitializedProperties` specifically for these row entities: they were
     * once built by bare assignments, so a forgotten column was silent at the call site and surfaced as
     * `must not be accessed before initialization` from inside `flush()`, with a stack trace pointing at
     * Doctrine rather than at the code that forgot it. A constructor moves that failure to the call site and to
     * compile time.
     *
     * The two nullable pricing parameters have NO defaults, deliberately. A default would let a caller construct
     * a row with neither the profit rate nor the net price and satisfy the type system — a product with a cost
     * and no price at all, which the migration's `product_stores_only_the_authored_field` CHECK then refuses at
     * the database with a constraint name instead of a message. Requiring both to be passed makes "which one is
     * null" a decision the caller states out loud.
     */
    public function __construct(
        Uuid $companyId,
        Uuid $id,
        string $name,
        ?string $sku,
        string $currency,
        string $costAmount,
        string $authoredBy,
        ?string $profitRate,
        ?string $netPriceAmount,
        string $vatRate,
    ) {
        $this->companyId = $companyId;
        $this->id = $id;
        $this->name = $name;
        $this->sku = $sku;
        $this->currency = $currency;
        $this->costAmount = $costAmount;
        $this->authoredBy = $authoredBy;
        $this->profitRate = $profitRate;
        $this->netPriceAmount = $netPriceAmount;
        $this->vatRate = $vatRate;
    }
}
