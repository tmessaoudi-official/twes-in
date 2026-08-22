<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Twes\Domain\Pricing\PricedBy;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Product\Product;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;

/**
 * `product` — the catalogue, and the last table Wave 1's `In:` line owes.
 *
 * **HAND-WRITTEN, not `doctrine:migrations:diff`**, for the reason every migration here gives: a diff produces
 * the `CREATE TABLE` and none of the row-level-security statements a tenant-owned table needs, and those are the
 * half that matters. The policy SQL comes from `policySqlFor()` rather than being spelled out, so this migration
 * and `scripts/gates/schema-tenancy.php` cannot disagree about what a canonical policy is.
 *
 * ## Only the AUTHORED price field is stored, and a CHECK enforces which one
 *
 * F4's ruling (`docs/plans/pricing-and-documents.plan.md`): the typed field is stored exactly as entered and is
 * **never recomputed**; the other is derived for display, with no authority. So `profit_rate` and
 * `net_price_amount` are both nullable and exactly ONE is present, matched to `authored_by`.
 *
 * **Storing both would be the defect this ruling exists to prevent.** Two columns that must agree are two
 * columns that eventually do not, and the one that gets rebuilt is the typed one — which is precisely the
 * millime-deleting failure F4 records: a cost of `10 000.000` with a typed price of `10 000.001` implies a rate
 * needing seven decimals, and rebuilding the price from a rate rounded to six DELETED the millime. Here the
 * typed value is the only one on disk, so there is nothing to rebuild it from.
 *
 * ## ONE currency column, not one per amount
 *
 * `CLAUDE.md` § Architecture requires a companion `currency` beside every persisted amount, because a bare
 * `NUMERIC` cannot reconstitute a `Money` — that argument was made for THIS table. It is satisfied by a single
 * column here rather than two, because {@see \Twes\Domain\Pricing\ProductPricing} refuses a net price in a
 * different currency from the cost, so two columns could only ever hold the same value or contradict each other.
 * A second column would be a second statement of one fact.
 *
 * ## Bounds and shapes are DERIVED from the domain, never typed out
 *
 * `NUMERIC(27, 12)` for both rates is `Rate::MAX_INTEGER_DIGITS + Rate::FRACTION_SCALE`, matching
 * `document_line.vat_rate`; the SKU's width is `Product::MAX_SKU_LENGTH`; `authored_by`'s is
 * `PricedBy::MAX_BACKED_VALUE_LENGTH`, which exists for this column and is pinned by `PricedByTest` — written
 * BEFORE this table rather than after, because `CLAUDE.md` § Gotchas records the mapping and the migration
 * disagreeing about `default_vat_rounding_point` with no detector able to see it.
 *
 * ## Deliberately NOT constrained
 *
 * **The SKU is not unique.** Uniqueness needs an answer for what a collision returns to a caller and whether it
 * is scoped to non-deleted rows, and inventing that rule as a side effect of creating a catalogue is how a
 * contract acquires a shape nobody argued for. It is added in the change that decides those questions — with a
 * `UNIQUE (company_id, sku)`, tenant column first, for the reason below.
 *
 * **EVERY KEY INCLUDES THE TENANT COLUMN.** Uniqueness and foreign-key checks run with row security BYPASSED, so
 * a key omitting the tenant is enforced across every tenant at once — an existence oracle over every tenant's
 * catalogue. `schema-tenancy.php` asserts that axis, and its docblock records it being deleted once on the claim
 * that the behavioural suite covered it, with two lenses independently reproducing an oracle without it.
 */
final class Version20260822160000 extends AbstractMigration
{
    private const string PRODUCT = 'product';

    public function getDescription(): string
    {
        return 'The product catalogue: cost, the authored price field, and the item VAT rate.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration issues PostgreSQL row-level-security statements. twes-in targets PostgreSQL only '
            . '— see CLAUDE.md § Architecture — so running it elsewhere would create the table WITHOUT the '
            . 'isolation that makes it safe to put tenant data in, which is worse than not creating it.',
        );

        $this->addSql(
            \sprintf(
                'CREATE TABLE %s ('
                . '%s UUID NOT NULL, '
                . 'id UUID NOT NULL, '
                . 'name TEXT NOT NULL, '
                . 'sku VARCHAR(%d) DEFAULT NULL, '
                // `CHAR(3)` AS A LITERAL, matching `document.currency`, and the literal is right here where it
                // would be wrong for a length we chose: ISO 4217 fixes alpha-3, so it is not a bound that can
                // drift with a domain constant. `Currency` exposes no width for the same reason.
                . 'currency CHAR(3) NOT NULL, '
                . 'cost_amount NUMERIC(19, 4) NOT NULL, '
                . 'authored_by VARCHAR(%d) NOT NULL, '
                . 'profit_rate NUMERIC(%d, %d) DEFAULT NULL, '
                . 'net_price_amount NUMERIC(19, 4) DEFAULT NULL, '
                . 'vat_rate NUMERIC(%d, %d) NOT NULL, '
                . 'PRIMARY KEY (%s, id))',
                self::PRODUCT,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
                Product::MAX_SKU_LENGTH,
                PricedBy::MAX_BACKED_VALUE_LENGTH,
                Rate::MAX_INTEGER_DIGITS + Rate::FRACTION_SCALE,
                Rate::FRACTION_SCALE,
                Rate::MAX_INTEGER_DIGITS + Rate::FRACTION_SCALE,
                Rate::FRACTION_SCALE,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
            ),
        );

        // THE NAME IS PRESENT AND BOUNDED, matching `Product::validatedName()` on both halves. `btrim` rather
        // than `<> ''` because the domain TRIMS before refusing, so a name of nothing but spaces is blank to it
        // and would otherwise be storable here — the two statements of one rule have to agree about whitespace
        // or the CHECK is not the same check.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_name_is_present CHECK (btrim(name) <> \'\')',
            self::PRODUCT,
        ));

        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_name_is_bounded CHECK (char_length(name) <= %d)',
            self::PRODUCT,
            Product::MAX_NAME_LENGTH,
        ));

        // A STORED SKU IS NEVER BLANK. `Product::validatedSku()` normalises a blank one to NULL, so a row
        // holding `''` or `'  '` is one the domain cannot produce and cannot faithfully read back — a product
        // with a blank SKU and one with none would be the same product wearing two spellings.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_sku_is_not_blank CHECK (sku IS NULL OR btrim(sku) <> \'\')',
            self::PRODUCT,
        ));

        // THE CURRENCY IS ALPHA-3 UPPERCASE. `Currency::of()` owns which codes actually EXIST and what scale
        // each one has; this is only the shape, so a lowercase or numeric code cannot be written by a hand-rolled
        // INSERT. Deliberately not a list of ISO 4217 codes: that list changes, and a CHECK is the wrong place
        // to learn about a new currency.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_currency_is_alpha_3 CHECK (currency ~ \'^[A-Z]{3}$\')',
            self::PRODUCT,
        ));

        // THE AUTHORED FIELD IS ONE OF THE ENUM'S OWN CASES, derived from `PricedBy::cases()` rather than
        // written out. Adding a third way to price a product then fails HERE, at the migration that would have
        // to widen the column, rather than at an INSERT nobody runs until production.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_authored_by_is_known CHECK (authored_by IN (%s))',
            self::PRODUCT,
            implode(', ', array_map(
                static fn(PricedBy $case): string => '\'' . $case->value . '\'',
                PricedBy::cases(),
            )),
        ));

        // **THE ONE CHECK THIS TABLE EXISTS FOR: exactly the authored field is stored, and it is the one
        // `authored_by` names.** Without it a row can carry both — and two columns that must agree are two
        // columns that eventually do not, with the typed one being the casualty. It can also carry NEITHER,
        // which is a product with a cost and no price at all.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_stores_only_the_authored_field CHECK ('
            . '(authored_by = \'%s\' AND profit_rate IS NOT NULL AND net_price_amount IS NULL) '
            . 'OR (authored_by = \'%s\' AND net_price_amount IS NOT NULL AND profit_rate IS NULL))',
            self::PRODUCT,
            PricedBy::ProfitRate->value,
            PricedBy::NetPrice->value,
        ));

        // NEITHER AMOUNT IS NEGATIVE. `ProductPricing` refuses a negative cost and a negative typed price
        // (`InvalidCost`); the PROFIT RATE is deliberately unconstrained in sign, because F4 rules that selling
        // below cost is real — clearance, a loss-leader — and must be surfaced rather than clamped.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_cost_is_not_negative CHECK (cost_amount >= 0)',
            self::PRODUCT,
        ));

        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_net_price_is_not_negative '
            . 'CHECK (net_price_amount IS NULL OR net_price_amount >= 0)',
            self::PRODUCT,
        ));

        // ROW-LEVEL SECURITY: enabled, FORCEd, and canonically policed on both halves -- all from policySqlFor()
        // so this migration cannot invent a variant the gate would then have to accept.
        foreach (PostgresRowLevelSecurityIsolation::policySqlFor(self::PRODUCT) as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        // NOTHING REFERENCES `product`, and that is the F4 snapshot rule showing up in the schema: a
        // `document_line` carries a `Money` and a `Rate` by value and holds no product id, precisely so a later
        // catalogue edit cannot reach an issued document. So there is no child to drop first.
        $this->addSql(\sprintf('DROP TABLE IF EXISTS %s', self::PRODUCT));
    }
}
