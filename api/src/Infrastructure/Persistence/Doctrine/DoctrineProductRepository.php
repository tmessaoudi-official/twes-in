<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PricedBy;
use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Product\Product;
use Twes\Domain\Product\ProductRepository;
use Twes\Domain\Shared\Identifier;
use Twes\Domain\Shared\RoundingMode;
use Twes\Infrastructure\Tenancy\TenantContext;

/**
 * The PostgreSQL adapter for {@see ProductRepository}.
 *
 * DBAL rather than the ORM, matching every other repository here. There is no parent/child rewrite to justify it
 * this time — a product is a single row — so the honest reason is uniformity with {@see DoctrineClientRepository}
 * and {@see DoctrineInvoiceRepository}: one persistence idiom in the tier, and a mapping whose job is
 * `doctrine:schema:validate` rather than writes.
 *
 * **BOTH METHODS REFUSE OUTSIDE A TRANSACTION, and for the READ that is the point rather than symmetry.** The
 * tenant binding row-level security compares against is written by `TenantBindingMiddleware` on
 * `beginTransaction()` and is TRANSACTION-LOCAL (`set_config(…, true)`), so outside one the connection is bound
 * to no tenant, an unbound session sees NOTHING, and a read would report "no such product" for a product that
 * exists. `CLAUDE.md` § Gotchas 2026-08-07 records that exact downgrade — a fail-closed tenancy refusal turned
 * into a silently wrong answer — costing three commits and being invisible to two reasonable fixtures.
 *
 * ## Only the AUTHORED price field is written, and it is written EXACTLY
 *
 * F4 rules that the typed field is stored as entered and never recomputed. So `save()` asks the pricing which
 * field was authored, writes that one, and writes NULL for the other — which is what the migration's
 * `product_stores_only_the_authored_field` CHECK requires.
 *
 * **`RoundingMode::Unnecessary` IS THE LOAD-BEARING ARGUMENT HERE, not a default.** Asking `ProductPricing` for
 * the field it was AUTHORED with is a lookup rather than a derivation, so no rounding can be needed and
 * `Unnecessary` cannot throw. Passing any other mode would make this call site silently capable of persisting a
 * ROUNDED copy of the typed value — which is precisely the millime-deleting defect F4 exists to prevent, arriving
 * through the one door that ruling did not think to lock. If this ever throws, the aggregate has been asked for a
 * value it did not author and the bug is upstream of here.
 */
final readonly class DoctrineProductRepository implements ProductRepository
{
    private const string PRODUCT = 'product';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {}

    public function save(Product $product): void
    {
        $tenant = $this->currentTenant('write a product');
        $this->requireTransaction('write a product');

        $pricing = $product->pricing();
        $authored = $pricing->authoredBy();

        // AN UNGUARDED UPSERT, unlike the document one. That statement guards a write-once number on a legal
        // document a client already holds; a catalogue entry is MEANT to be edited, and an issued document is
        // protected from those edits by VALUE — `DocumentLine` carries a `Money` and a `Rate` and holds no
        // product id, so there is nothing here for a later edit to reach.
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO %s (company_id, id, name, sku, currency, cost_amount, authored_by, profit_rate, '
                . 'net_price_amount, vat_rate) '
                . 'VALUES (:tenant, :id, :name, :sku, :currency, :cost, :authoredBy, :profitRate, :netPrice, '
                . ':vatRate) '
                . 'ON CONFLICT (company_id, id) DO UPDATE SET '
                . 'name = EXCLUDED.name, '
                . 'sku = EXCLUDED.sku, '
                . 'currency = EXCLUDED.currency, '
                . 'cost_amount = EXCLUDED.cost_amount, '
                . 'authored_by = EXCLUDED.authored_by, '
                . 'profit_rate = EXCLUDED.profit_rate, '
                . 'net_price_amount = EXCLUDED.net_price_amount, '
                . 'vat_rate = EXCLUDED.vat_rate',
                self::PRODUCT,
            ),
            [
                'tenant' => $tenant,
                'id' => $product->id(),
                'name' => $product->name(),
                'sku' => $product->sku(),
                'currency' => $product->cost()->currency()->code(),
                'cost' => $product->cost()->amount(),
                'authoredBy' => $authored->value,
                'profitRate' => PricedBy::ProfitRate === $authored
                    ? self::authoredRate($pricing)->fraction()
                    : null,
                'netPrice' => PricedBy::NetPrice === $authored
                    ? $pricing->netPrice(RoundingMode::Unnecessary)->amount()
                    : null,
                'vatRate' => $product->vatRate()->fraction(),
            ],
        );
    }

    public function find(string $id): ?Product
    {
        // VALIDATED BEFORE IT REACHES A QUERY, by the type that owns the rule. An ill-formed id reaching
        // `WHERE id = :id` makes PostgreSQL raise `invalid input syntax for type uuid`, which is a 500 where the
        // caller should see a 404.
        if (!Identifier::isWellFormed($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'A product id must be a canonical lowercase-hyphenated UUID, got "%s". Refused here rather than '
                . 'passed to the database, which would raise a type error and turn a missing product into a 500.',
                $id,
            ));
        }

        $tenant = $this->currentTenant('read a product');
        $this->requireTransaction('read a product');

        /**
         * @var array{
         *     name: string,
         *     sku: null|string,
         *     currency: string,
         *     cost_amount: string,
         *     authored_by: string,
         *     profit_rate: null|string,
         *     net_price_amount: null|string,
         *     vat_rate: string
         * }|false $row
         */
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT name, sku, currency, cost_amount, authored_by, profit_rate, net_price_amount, vat_rate '
                . 'FROM %s WHERE company_id = :tenant AND id = :id',
                self::PRODUCT,
            ),
            ['tenant' => $tenant, 'id' => $id],
        );

        if (false === $row) {
            // NOT FOUND AND NOT YOURS ARE THE SAME ANSWER, deliberately. Distinguishing them would make another
            // tenant's product id answerable, which is an existence oracle over every tenant's catalogue.
            return null;
        }

        return Product::fromPersistedState(
            $id,
            $row['name'],
            $row['sku'],
            self::pricingFrom($row),
            Rate::fromFraction($row['vat_rate']),
        );
    }

    /**
     * Rebuild the pricing from the ONE authored column, in the currency the row carries.
     *
     * **THE DISCRIMINATOR DECIDES, and a row that disagrees with itself is refused rather than guessed at.** The
     * `product_stores_only_the_authored_field` CHECK makes such a row unwritable through this database, so
     * reaching the refusal below means the constraint was dropped, the row predates it, or another writer
     * bypassed it — every one of which is a corrupt row and OUR fault. It is a `\LogicException` so it becomes a
     * 500 (`error.internal`) and never a 404 that would hide it: `CLAUDE.md` § Gotchas records a hydration
     * failure being reported as "no such document" while the row demonstrably existed.
     *
     * The trailing `default` is unreachable while `PricedBy` has two cases and exists so that adding a third
     * fails HERE rather than silently taking a branch written for a different world.
     *
     * @param array{
     *     currency: string,
     *     cost_amount: string,
     *     authored_by: string,
     *     profit_rate: null|string,
     *     net_price_amount: null|string
     * } $row
     */
    private static function pricingFrom(array $row): ProductPricing
    {
        $currency = Currency::of($row['currency']);
        $cost = Money::of($row['cost_amount'], $currency);
        $authored = PricedBy::tryFrom($row['authored_by']);

        if (null === $authored) {
            throw new \LogicException(\sprintf(
                'Stored product authorship "%s" is not a known pricing field. The '
                . 'product_authored_by_is_known CHECK makes this unwritable, so the row is corrupt rather than '
                . 'the caller being wrong.',
                $row['authored_by'],
            ));
        }

        return match ($authored) {
            PricedBy::ProfitRate => ProductPricing::fromProfitRate(
                $cost,
                Rate::fromFraction(self::required($row['profit_rate'], 'profit_rate', $authored)),
            ),
            PricedBy::NetPrice => ProductPricing::fromNetPrice(
                $cost,
                Money::of(self::required($row['net_price_amount'], 'net_price_amount', $authored), $currency),
            ),
        };
    }

    /**
     * @throws \LogicException if the column the discriminator names is absent
     */
    private static function required(?string $value, string $column, PricedBy $authored): string
    {
        if (null === $value) {
            throw new \LogicException(\sprintf(
                'A product authored by "%s" has no %s. Exactly that column must be present, which the '
                . 'product_stores_only_the_authored_field CHECK enforces — so this row is corrupt rather than '
                . 'the caller being wrong, and it must surface as a server error rather than as a missing '
                . 'product.',
                $authored->value,
                $column,
            ));
        }

        return $value;
    }

    /**
     * The profit rate a rate-authored pricing was built with.
     *
     * `profitRate()` is nullable because it is UNDEFINED for a net-authored product whose cost is zero — a
     * division by zero, which F4 rules must show as empty rather than as `0` or an error. That case cannot arise
     * here: this is only called when authorship IS the rate, in which case the value is the typed one and a
     * lookup rather than a derivation.
     *
     * @throws \LogicException if it is null anyway, which would mean the aggregate lost the field it authored
     */
    private static function authoredRate(ProductPricing $pricing): Rate
    {
        $rate = $pricing->profitRate(RoundingMode::Unnecessary);

        if (null === $rate) {
            throw new \LogicException(
                'A rate-authored product has no profit rate. The typed field is never derived, so this is not a '
                . 'zero-cost division — it means the aggregate lost the value it was constructed with.',
            );
        }

        return $rate;
    }

    /**
     * @throws \RuntimeException if no tenant is bound
     */
    private function currentTenant(string $attempted): string
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to %s with no tenant bound. This is the boundary rule `CLAUDE.md` § Gotchas '
                . '2026-07-31 states: no tenant-less path may hydrate a domain aggregate. Under row-level '
                . 'security an unbound read sees nothing, which is indistinguishable from a tenant that has no '
                . 'such product — so answering it would turn a tenancy refusal into a silently wrong answer.',
                $attempted,
            ));
        }

        return $this->tenantContext->tenantId()->toString();
    }

    /**
     * @throws \RuntimeException if there is no active transaction
     */
    private function requireTransaction(string $attempted): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to %s outside a transaction. The tenant binding this table is policed by is written '
                . 'on beginTransaction() and is transaction-local, so outside one the connection is bound to no '
                . 'tenant: a read would return zero rows for a product that exists, and a write would be refused '
                . 'by the policy. The transaction is what makes the binding present rather than assumed.',
                $attempted,
            ));
        }
    }
}
