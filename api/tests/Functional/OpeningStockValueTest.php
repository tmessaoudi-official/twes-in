<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261006090100;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Stock held before movements carried a cost had no value, and the first sale after it was valued alone, so the
 * product read as worth less than nothing with all of it on the shelf (audit 2026-10-06, E-6). The migration values
 * what was held at the product's cost price, as an opening value.
 */
final class OpeningStockValueTest extends ApiTestCase
{
    public function testStockHeldBeforeValuationIsValuedAtTheCostPriceSoASaleLeavesTheRestWorthWhatItIs(): void
    {
        $company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($company);
        $vise = $this->product($company, 'ART-001', '10');
        $bare = $this->product($company, 'ART-002', null);
        $site = $this->site($company);
        foreach ([$vise, $bare] as $product) {
            $this->em()->persist(StockMovement::receipt($product, $site, '100', null, new \DateTimeImmutable('2026-09-01')));
        }
        $this->em()->flush();
        // As every movement written before its cost existed: nothing on it.
        $this->em()->getConnection()->executeStatement('UPDATE stock_movement SET unit_cost = NULL WHERE company_id = ?', [$company->getId()->toRfc4122()]);
        $this->em()->clear();

        $this->migrate();
        $vise = $this->em()->find(Product::class, $vise->getId());
        $site = $this->em()->find(StockLocation::class, $site->getId());
        self::assertNotNull($vise);
        self::assertNotNull($site);
        static::getContainer()->get(StockMovementRepository::class)->save(StockMovement::sale($vise, $site, '10', Uuid::v7(), new \DateTimeImmutable()));

        $values = [];
        foreach (static::getContainer()->get(StockMovementRepository::class)->valuation($company->getId()) as $value) {
            $values[$value->productId->toRfc4122()] = [$value->quantity, $value->value, $value->unvaluedQuantity];
        }
        self::assertSame(['90.000', '900.0000000', '0.000'], $values[$vise->getId()->toRfc4122()], 'not -100 with 100 unvalued');
        self::assertSame(['100.000', '0.0000000', '100.000'], $values[$bare->getId()->toRfc4122()], 'no cost price, nothing to open at');
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM stock_movement WHERE cost_typed AND company_id = ?', [$company->getId()->toRfc4122()]), 'an opening value is nobody\'s typed cost');
    }

    private function migrate(): void
    {
        // Doctrine loads migrations from their directory, not the autoloader.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261006090100.php';
        $connection = $this->em()->getConnection();
        $migration = new Version20261006090100($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            $connection->executeStatement($query->getStatement());
        }
    }

    private function product(Company $company, string $reference, ?string $costPrice): Product
    {
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($piece);
        $product = Product::create($company, $reference, new ProductDetails($reference, null, ProductKind::Goods, '20', $costPrice), $piece, null, [], new \DateTimeImmutable());
        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }

    private function site(Company $company): StockLocation
    {
        $establishments = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($company->getId());
        self::assertNotEmpty($establishments);

        return static::getContainer()->get(ManageStockLocations::class)->defaultOf($establishments[0]);
    }
}
