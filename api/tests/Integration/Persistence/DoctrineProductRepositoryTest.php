<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PricedBy;
use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Product\Product;
use Twes\Domain\Shared\RoundingMode;
use Twes\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\Tenancy\MigratedProbeDatabase;

/**
 * The product repository against a REAL migrated schema, which is the only place most of this can be proven.
 *
 * The headline case is {@see self::testTheTypedMillimeSurvivesTheRoundTrip()}: F4's whole `authored_by` ruling
 * exists because a typed price of `10 000.001` against a cost of `10 000.000` implies a rate needing SEVEN
 * decimals, and a six-decimal rate rounded it to zero and DELETED the millime on the next cost change. Nothing
 * short of real `NUMERIC` columns can demonstrate that the storage layer preserves it.
 */
#[CoversClass(DoctrineProductRepository::class)]
final class DoctrineProductRepositoryTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_product_repository_probe';
    private const TENANT_A = '0199a5b2-0000-7000-8000-0000000003aa';
    private const TENANT_B = '0199a5b2-0000-7000-8000-0000000003bb';
    private const PRODUCT = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private static ?Connection $connection = null;

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection = null;
        self::dropProbeDatabase(self::DATABASE);
    }

    protected function setUp(): void
    {
        // TRUNCATE, NOT DELETE. The table is `FORCE ROW LEVEL SECURITY`, so the owner is policed too and a
        // DELETE issued with no tenant bound matches ZERO rows — the near-vacuous cleanup `CLAUDE.md` § Gotchas
        // 2026-08-22 records finding in the client suite, where it left one tenant's rows standing.
        self::connection()->executeStatement('TRUNCATE product');
    }

    public function testARateAuthoredProductSurvivesARoundTrip(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        $product = Product::create(self::PRODUCT, 'Café moulu', self::ratePricing(), Rate::fromPercentage('19'))
            ->withSku('CAFE-500G');

        self::inTransaction(static fn() => $repository->save($product));
        $restored = self::inTransaction(static fn(): ?Product => $repository->find(self::PRODUCT));

        self::assertNotNull($restored);
        self::assertSame('Café moulu', $restored->name());
        self::assertSame('CAFE-500G', $restored->sku());
        self::assertSame(PricedBy::ProfitRate, $restored->authoredBy());
        self::assertTrue($restored->cost()->equals(Money::of('100.000', self::tnd())));
        self::assertTrue($restored->vatRate()->equals(Rate::fromPercentage('19')));

        $rate = $restored->pricing()->profitRate(RoundingMode::Unnecessary);
        self::assertNotNull($rate);
        self::assertTrue($rate->equals(Rate::fromPercentage('30')), 'the typed rate comes back as typed');
    }

    /** A product with no SKU comes back with none, rather than with an empty string. */
    public function testAProductWithoutASkuSurvivesARoundTrip(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        $product = Product::create(self::PRODUCT, 'Café moulu', self::ratePricing(), Rate::fromPercentage('19'));

        self::inTransaction(static fn() => $repository->save($product));
        $restored = self::inTransaction(static fn(): ?Product => $repository->find(self::PRODUCT));

        self::assertNotNull($restored);
        self::assertNull($restored->sku(), 'an absent SKU comes back absent, not empty');
    }

    public function testANetPriceAuthoredProductKeepsItsAuthorship(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        $pricing = ProductPricing::fromNetPrice(
            Money::of('100.000', self::tnd()),
            Money::of('130.000', self::tnd()),
        );

        self::inTransaction(static fn() => $repository->save(
            Product::create(self::PRODUCT, 'Café moulu', $pricing, Rate::fromPercentage('19')),
        ));
        $restored = self::inTransaction(static fn(): ?Product => $repository->find(self::PRODUCT));

        self::assertNotNull($restored);
        self::assertSame(
            PricedBy::NetPrice,
            $restored->authoredBy(),
            'authorship IS the F4 ruling — a product read back as rate-authored would have its typed price '
            . 'rebuilt from a rounded rate on the next cost change',
        );
        self::assertTrue($restored->pricing()->netPrice(RoundingMode::Unnecessary)->equals(
            Money::of('130.000', self::tnd()),
        ));
    }

    /**
     * **THE CASE F4 EXISTS FOR: one millime of profit on a ten-thousand-dinar cost, through real columns.**
     *
     * `pricing-and-documents.plan.md` § F4: cost `10 000.000`, typed price `10 000.001`. The implied rate is
     * `0.0000001` — SEVEN decimals — and the original six-decimal rate rounded it to zero, so the form displayed
     * `0.0000 %` for a product sold above cost and the next cost change DELETED the millime. Two fixes together:
     * twelve decimals on the rate, and storing which field was authored.
     *
     * This asserts the storage half. The price is written to `net_price_amount` verbatim because it is the
     * AUTHORED field, and comes back byte-equal — no rate is consulted in either direction, which is what makes
     * the millime structurally safe rather than merely precise enough.
     */
    public function testTheTypedMillimeSurvivesTheRoundTrip(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        $typed = Money::of('10000.001', self::tnd());
        $pricing = ProductPricing::fromNetPrice(Money::of('10000.000', self::tnd()), $typed);

        self::inTransaction(static fn() => $repository->save(
            Product::create(self::PRODUCT, 'Something expensive', $pricing, Rate::fromPercentage('19')),
        ));
        $restored = self::inTransaction(static fn(): ?Product => $repository->find(self::PRODUCT));

        self::assertNotNull($restored);
        self::assertTrue(
            $restored->pricing()->netPrice(RoundingMode::Unnecessary)->equals($typed),
            'the typed price must survive storage exactly — a millime lost here is a millime lost forever',
        );

        // AND THE DERIVED RATE STILL CARRIES IT. Twelve decimals is what makes the implied rate representable at
        // all; at six it rounds to zero and the product looks as though it is sold at cost.
        $rate = $restored->pricing()->profitRate(RoundingMode::Unnecessary);
        self::assertNotNull($rate);
        self::assertFalse(
            $rate->isZero(),
            'the implied rate needs seven decimals; at six it rounds to zero and the margin disappears',
        );
    }

    public function testResavingUpdatesTheProductRatherThanDuplicatingIt(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        $product = Product::create(self::PRODUCT, 'Café moulu', self::ratePricing(), Rate::fromPercentage('19'));

        self::inTransaction(static fn() => $repository->save($product));
        self::inTransaction(static fn() => $repository->save($product->withName('Café en grains')));

        self::assertSame(1, (int) self::connection()->fetchOne('SELECT count(*) FROM product'));

        $restored = self::inTransaction(static fn(): ?Product => $repository->find(self::PRODUCT));
        self::assertNotNull($restored);
        self::assertSame('Café en grains', $restored->name());
    }

    /**
     * **CHANGING AUTHORSHIP CLEARS THE OTHER COLUMN**, which the CHECK would otherwise refuse.
     *
     * A rate-authored product re-saved with a typed price must end with `profit_rate` NULL. An upsert that only
     * wrote the newly-authored column would leave the old one behind and violate
     * `product_stores_only_the_authored_field` — so this case is the one that proves the `save()` writes NULL
     * explicitly rather than omitting the column.
     */
    public function testChangingAuthorshipClearsTheColumnThatIsNoLongerAuthored(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        $product = Product::create(self::PRODUCT, 'Café moulu', self::ratePricing(), Rate::fromPercentage('19'));
        self::inTransaction(static fn() => $repository->save($product));

        $repriced = $product->withPricing(ProductPricing::fromNetPrice(
            Money::of('100.000', self::tnd()),
            Money::of('140.000', self::tnd()),
        ));
        self::inTransaction(static fn() => $repository->save($repriced));

        $row = self::connection()->fetchAssociative('SELECT profit_rate, net_price_amount FROM product');
        self::assertIsArray($row);
        self::assertNull($row['profit_rate'], 'the rate must be cleared when the price becomes the authored field');
        self::assertNotNull($row['net_price_amount']);
    }

    public function testAnotherTenantsProductIsNotFound(): void
    {
        $ofA = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $ofA->save(
            Product::create(self::PRODUCT, 'Café moulu', self::ratePricing(), Rate::fromPercentage('19')),
        ));

        $ofB = self::repositoryFor(self::TENANT_B);

        self::assertNull(
            self::inTransaction(static fn(): ?Product => $ofB->find(self::PRODUCT)),
            'another tenant\'s product is indistinguishable from one that does not exist',
        );
    }

    public function testAnUnknownProductIsNotFound(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        self::assertNull(
            self::inTransaction(static fn(): ?Product => $repository->find('11111111-1111-4111-8111-111111111111')),
        );
    }

    public function testAnIllFormedIdIsRefusedBeforeItReachesTheDatabase(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/canonical lowercase-hyphenated UUID/');

        $repository->find('not-a-uuid');
    }

    public function testReadingOutsideATransactionIsRefused(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside a transaction/');

        $repository->find(self::PRODUCT);
    }

    public function testWritingOutsideATransactionIsRefused(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside a transaction/');

        $repository->save(
            Product::create(self::PRODUCT, 'Café moulu', self::ratePricing(), Rate::fromPercentage('19')),
        );
    }

    public function testReadingWithNoTenantBoundIsRefused(): void
    {
        $repository = new DoctrineProductRepository(self::connection(), new InMemoryTenantContext());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tenant bound/');

        $repository->find(self::PRODUCT);
    }

    /**
     * **`product_stores_only_the_authored_field` IS PROVEN TO FIRE, rather than merely never violated.**
     *
     * No route through the domain can write a row carrying both price fields or neither: `ProductPricing` has
     * exactly two named constructors and each sets one. So the CHECK is satisfied by every row this suite
     * writes, and a polarity error in it would be invisible with everything green — the vacuous-control shape
     * `CLAUDE.md` § Gotchas records four separate times, and the same gap found on `client_address_is_whole`.
     *
     * Both branches are exercised, and both directions of the error: a row carrying BOTH, and a row carrying
     * NEITHER. Raw INSERTs, deliberately — the constraint exists precisely for the writer that is not the
     * repository: a migration, a `psql` session, a future importer.
     */
    public function testARowCarryingBothOrNeitherPriceFieldIsRefusedByTheSchema(): void
    {
        self::repositoryFor(self::TENANT_A);

        foreach ([
            'both' => ['profit_rate' => '0.3', 'net_price_amount' => '130.0000'],
            'neither' => ['profit_rate' => null, 'net_price_amount' => null],
            'the wrong one for its authorship' => ['profit_rate' => null, 'net_price_amount' => '130.0000'],
        ] as $why => $pricing) {
            self::assertRefusedBy(
                'product_stores_only_the_authored_field',
                'INSERT INTO product (company_id, id, name, currency, cost_amount, authored_by, profit_rate, '
                . 'net_price_amount, vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    self::TENANT_A,
                    self::PRODUCT,
                    'Café moulu',
                    'TND',
                    '100.0000',
                    // ALWAYS RATE-AUTHORED, so the third case is a row whose discriminator and its columns
                    // disagree — the shape `pricingFrom()` refuses as a corrupt row rather than guessing at.
                    PricedBy::ProfitRate->value,
                    $pricing['profit_rate'],
                    $pricing['net_price_amount'],
                    '0.19',
                ],
                $why,
            );
        }
    }

    /**
     * `product_sku_is_not_blank` is proven the same way, and for the same reason: `Product::validatedSku()`
     * normalises a blank SKU to NULL, so no route through the domain can produce the row this refuses.
     */
    public function testABlankSkuIsRefusedByTheSchema(): void
    {
        self::repositoryFor(self::TENANT_A);

        self::assertRefusedBy(
            'product_sku_is_not_blank',
            'INSERT INTO product (company_id, id, name, sku, currency, cost_amount, authored_by, profit_rate, '
            . 'vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::TENANT_A,
                self::PRODUCT,
                'Café moulu',
                '   ',
                'TND',
                '100.0000',
                PricedBy::ProfitRate->value,
                '0.3',
                '0.19',
            ],
            'a SKU of nothing but whitespace',
        );
    }

    /**
     * **`product_vat_rate_is_not_negative` IS PROVEN TO FIRE, and it is the THIRD statement of one rule.**
     *
     * `Product` refuses a negative VAT rate at the use site (because `DocumentLine` does, so a product carrying
     * one could never be put on a line), `NewProductInput` mirrors that at the edge so the answer is a 422, and
     * this is the level a hand-written `INSERT` cannot avoid. No route through the domain can produce the row
     * below, which is exactly what would have made the constraint vacuous-by-construction.
     *
     * **A NEGATIVE `profit_rate` IS DELIBERATELY NOT REFUSED**, and the asymmetry is asserted rather than
     * assumed: F4 rules that selling below cost is real, so that column is where a negative rate legitimately
     * lives. A CHECK covering both would be a schema quietly overruling a product decision.
     */
    public function testANegativeVatRateIsRefusedByTheSchemaWhileANegativeProfitRateIsNot(): void
    {
        self::repositoryFor(self::TENANT_A);

        self::assertRefusedBy(
            'product_vat_rate_is_not_negative',
            'INSERT INTO product (company_id, id, name, currency, cost_amount, authored_by, profit_rate, '
            . 'vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::TENANT_A,
                self::PRODUCT,
                'Café moulu',
                'TND',
                '100.0000',
                PricedBy::ProfitRate->value,
                '0.3',
                '-0.19',
            ],
            'a negative VAT rate',
        );

        // THE OTHER HALF, in the same case so the two cannot drift apart: a clearance product priced BELOW cost
        // stores a negative profit rate and the schema accepts it.
        self::connection()->executeStatement(
            'INSERT INTO product (company_id, id, name, currency, cost_amount, authored_by, profit_rate, '
            . 'vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::TENANT_A,
                '11111111-1111-4111-8111-111111111111',
                'Clearance item',
                'TND',
                '100.0000',
                PricedBy::ProfitRate->value,
                '-0.2',
                '0.19',
            ],
        );

        self::assertSame(
            1,
            (int) self::connection()->fetchOne('SELECT count(*) FROM product WHERE profit_rate < 0'),
            'a negative PROFIT rate is legitimate — F4 refuses to clamp a loss-leader',
        );
    }

    /**
     * Assert that a statement is refused BY A NAMED CONSTRAINT, not merely that it fails.
     *
     * The name is asserted rather than the failure, because `CLAUDE.md` § Gotchas 2026-07-29 records a meta-gate
     * reporting 33/33 for a gate that detected nothing: a crash and a detection are indistinguishable from an
     * exit code alone. Here the difference is real — a typo'd column, a row-level-security refusal and any of
     * the six CHECKs on this table all raise a `Doctrine\DBAL\Exception`.
     *
     * @param list<null|string> $parameters
     */
    private static function assertRefusedBy(
        string $constraint,
        string $sql,
        array $parameters,
        string $why,
    ): void {
        try {
            self::connection()->executeStatement($sql, $parameters);
        } catch (\Doctrine\DBAL\Exception $refusal) {
            self::assertStringContainsString(
                $constraint,
                $refusal->getMessage(),
                \sprintf('%s must be refused by %s specifically, not by something else', $why, $constraint),
            );

            return;
        }

        self::fail(\sprintf('the schema accepted %s, which %s exists to refuse', $why, $constraint));
    }

    private static function ratePricing(): ProductPricing
    {
        return ProductPricing::fromProfitRate(Money::of('100.000', self::tnd()), Rate::fromPercentage('30'));
    }

    private static function tnd(): Currency
    {
        return Currency::of('TND');
    }

    /**
     * A repository bound to one tenant, with the session GUC set to match.
     *
     * SESSION-scoped here (`set_config(..., false)`) rather than transaction-local, matching the other
     * repository suites: these cases open several short transactions against one connection. Production must use
     * the transaction-local form — a session-scoped value leaks to whoever gets the pooled connection next,
     * which is what `PostgresRowLevelSecurityIsolation::bind()` exists to avoid. Legitimate here only because
     * this connection is not pooled and is discarded with the class.
     */
    private static function repositoryFor(string $tenant): DoctrineProductRepository
    {
        self::connection()->executeStatement(
            \sprintf("SELECT set_config('%s', ?, false)", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
            [$tenant],
        );

        return new DoctrineProductRepository(
            self::connection(),
            InMemoryTenantContext::forTenant(TenantId::fromString($tenant)),
        );
    }

    /**
     * The OWNER connection. `product` is `FORCE ROW LEVEL SECURITY`, so the owner is policed too — the binding
     * above is what makes anything visible. The owner rather than the runtime role because the probe database is
     * created fresh and the runtime role's per-database grants are provisioned only for `twes_in_test`; tenant
     * ISOLATION is `BehaviouralIsolationTest`'s subject and is deliberately not re-proven here.
     */
    private static function connection(): Connection
    {
        if (null === self::$connection) {
            try {
                self::$connection = DriverManager::getConnection([
                    'driver' => 'pdo_pgsql',
                    'host' => self::host(),
                    'port' => (int) self::port(),
                    'dbname' => self::DATABASE,
                    'user' => self::ownerRole(),
                    'password' => self::ownerPassword(),
                ]);
                self::$connection->executeQuery('SELECT 1');
            } catch (\Doctrine\DBAL\Exception $exception) {
                self::fail('Could not connect to the probe database: ' . $exception->getMessage());
            }
        }

        return self::$connection;
    }

    /**
     * Run one callable inside a real transaction, so the repository's own refusal is satisfied.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private static function inTransaction(callable $work): mixed
    {
        $connection = self::connection();
        $connection->beginTransaction();

        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }
    }
}
