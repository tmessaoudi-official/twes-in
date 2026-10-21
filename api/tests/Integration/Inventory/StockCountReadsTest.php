<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Inventory\Application\Location\ManageStockLocations;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\Repository\StockMovementRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * What an import of counts asks the stock before it writes, read in plain SQL, which the company filter does not reach:
 * only the company's own movements answer, whatever another company's row names. Nothing ties a movement's product to
 * its company in the schema, so a row naming another company's product is a corrupt write or a restore away.
 */
final class StockCountReadsTest extends KernelTestCase
{
    private Company $company;
    private Company $globex;
    private Product $screws;
    private StockLocation $shelf;

    protected function setUp(): void
    {
        self::bootKernel();
        $provision = static::getContainer()->get(ProvisionCompany::class);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        foreach ([$this->company, $this->globex] as $company) {
            $this->em()->persist($company);
            $this->em()->flush();
            $provision->handle($company);
        }
        $this->screws = $this->product($this->company);
        $this->shelf = $this->placeOf($this->company);
    }

    public function testAnotherCompanysMovementNamingTheProductIsNoMovementSinceTheCount(): void
    {
        $this->save(StockMovement::count($this->screws, $this->shelf, '10', '0', null, $this->at('2026-09-01 10:00')));
        $this->foreignRow(StockMovement::receipt($this->product($this->globex), $this->placeOf($this->globex), '4', null, $this->at('2026-09-02 10:00')));

        self::assertFalse($this->movements()->movedSinceLastCount($this->screws->getId(), $this->shelf->getId(), $this->company->getId()));

        $this->save(StockMovement::receipt($this->screws, $this->shelf, '4', null, $this->at('2026-09-03 10:00')));
        self::assertTrue($this->movements()->movedSinceLastCount($this->screws->getId(), $this->shelf->getId(), $this->company->getId()));
    }

    public function testAnotherCompanysCountAfterTheLastCountDoesNotHideAMovement(): void
    {
        $this->save(StockMovement::count($this->screws, $this->shelf, '10', '0', null, $this->at('2026-09-01 10:00')));
        $this->save(StockMovement::receipt($this->screws, $this->shelf, '4', null, $this->at('2026-09-02 10:00')));
        $this->foreignRow(StockMovement::count($this->product($this->globex), $this->placeOf($this->globex), '1', '0', null, $this->at('2026-09-03 10:00')));

        self::assertTrue($this->movements()->movedSinceLastCount($this->screws->getId(), $this->shelf->getId(), $this->company->getId()));
    }

    public function testAnotherCompanysCountIsNoCountOfThePlace(): void
    {
        $this->foreignRow(StockMovement::count($this->product($this->globex), $this->placeOf($this->globex), '1', '0', null, $this->at('2026-09-03 10:00')));

        self::assertFalse($this->movements()->countedSince($this->screws->getId(), $this->shelf->getId(), $this->at('2026-09-01 00:00'), Uuid::v7(), $this->company->getId()));

        $this->save(StockMovement::count($this->screws, $this->shelf, '10', '0', null, $this->at('2026-09-04 10:00')));
        self::assertTrue($this->movements()->countedSince($this->screws->getId(), $this->shelf->getId(), $this->at('2026-09-01 00:00'), Uuid::v7(), $this->company->getId()));
    }

    /** Another company's movement, rewritten to name this company's product and place. */
    private function foreignRow(StockMovement $movement): void
    {
        $this->save($movement);
        $this->em()->getConnection()->executeStatement(
            'UPDATE stock_movement SET product_id = ?, location_id = ? WHERE id = ?',
            [$this->screws->getId()->toRfc4122(), $this->shelf->getId()->toRfc4122(), $movement->getId()->toRfc4122()],
        );
    }

    private function movements(): StockMovementRepository
    {
        return static::getContainer()->get(StockMovementRepository::class);
    }

    private function product(Company $company): Product
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $product = Product::create($company, 'VIS-04', new ProductDetails('Vis', null, ProductKind::Goods, '1'), $unit, null, [], new \DateTimeImmutable('2026-01-01'));
        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }

    private function placeOf(Company $company): StockLocation
    {
        $shop = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($company->getId())[0];

        return static::getContainer()->get(ManageStockLocations::class)->defaultOf($shop);
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    private function save(StockMovement $movement): void
    {
        $this->em()->persist($movement);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
