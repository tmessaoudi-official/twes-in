<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Inventory\Application\Location\ManageStockLocations;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\ValueObject\StockLocationKind;
use App\Module\Inventory\Domain\ValueObject\StockLossReason;
use App\Module\Inventory\Infrastructure\Reports\DeadStockReport;
use App\Module\Inventory\Infrastructure\Reports\StockMovementsReport;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportComparison;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportResult;
use App\Module\Reports\Application\RunReport;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The Inventory module's reports as the database sums the movements: each column one reason goods moved, the days the
 * company's own, and the dead stock cut where the company says a product has stopped selling.
 */
final class StockReportsTest extends KernelTestCase
{
    private Company $company;
    private Company $globex;
    private Establishment $shop;
    private Establishment $depot;
    private StockLocation $shelf;
    private StockLocation $yard;

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
        $this->shop = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0];
        $this->depot = Establishment::create($this->company, '001', 'Dépôt', false, new \DateTimeImmutable('2026-01-01'));
        $this->em()->persist($this->depot);
        $this->em()->flush();
        $locations = static::getContainer()->get(ManageStockLocations::class);
        $this->shelf = $locations->defaultOf($this->shop);
        $this->yard = $locations->defaultOf($this->depot);
    }

    public function testEachProductsStockOverThePeriodIsItsOpeningEachReasonItMovedAndItsClosing(): void
    {
        $drill = $this->product('PER-08', 'Perceuse');
        $screws = $this->product('VIS-04', 'Vis');
        $this->product('BOU-01', 'Boulon');
        $this->save(StockMovement::receipt($drill, $this->shelf, '10', null, $this->at('2026-08-20 10:00')));
        $this->save(StockMovement::receipt($screws, $this->shelf, '5', null, $this->at('2026-07-01 10:00')));
        // Half past midnight in Tunis on the 1st is still August in UTC: it belongs to the period.
        $this->save(StockMovement::receipt($drill, $this->shelf, '4', null, $this->at('2026-08-31 23:30')));
        $sale = $this->save(StockMovement::sale($drill, $this->shelf, '3', Uuid::v7(), $this->at('2026-09-05 10:00')));
        $delivery = $this->save(StockMovement::delivery($drill, $this->shelf, '2', Uuid::v7(), $this->at('2026-09-06 10:00')));
        $this->save(StockMovement::returnOf($delivery, $this->at('2026-09-07 10:00')));
        $this->save(StockMovement::delivery($drill, $this->shelf, '1', Uuid::v7(), $this->at('2026-09-07 11:00')));
        $this->save(StockMovement::loss($drill, $this->shelf, '1', StockLossReason::Broken, null, null, $this->at('2026-09-08 10:00')));
        $this->save(StockMovement::count($drill, $this->shelf, '10', '9', null, $this->at('2026-09-09 10:00')));
        $this->save(StockMovement::returnOfSales([$sale], '1', Uuid::v7(), Uuid::v7(), $this->at('2026-09-10 10:00')));
        // From the shelf to the back room of the same shop: in no column, and the closing is as it was.
        $backRoom = StockLocation::create($this->shop, $this->shelf, StockLocationKind::Zone, 'R1', 'Réserve', new \DateTimeImmutable('2026-01-01'));
        $this->em()->persist($backRoom);
        foreach (StockMovement::move($drill, $this->shelf, $backRoom, '2', null, $this->at('2026-09-11 10:00')) as $moved) {
            $this->save($moved);
        }
        $this->save(StockMovement::receipt($drill, $this->yard, '2', null, $this->at('2026-09-12 10:00')));
        // Half past midnight on 1 October in Tunis is past the period, though still September in UTC.
        $this->save(StockMovement::receipt($drill, $this->shelf, '1', null, $this->at('2026-09-30 23:30')));
        $theirs = $this->product('PER-08', 'Perceuse', $this->globex);
        $this->save(StockMovement::receipt($theirs, static::getContainer()->get(ManageStockLocations::class)->defaultOf($this->establishmentOf($this->globex)), '99', null, $this->at('2026-09-05 10:00')));
        $this->em()->clear();

        $result = $this->report(StockMovementsReport::class, '2026-09-01', '2026-09-30');

        self::assertSame(['product', 'reference', 'unit', 'opening', 'received', 'sold', 'returned', 'lost', 'counted', 'closing'], array_map(static fn ($column): string => $column->key, $result->columns));
        self::assertSame([
            ['Perceuse', 'PER-08', 'C62', '10.000', '6.000', '4.000', '1.000', '1.000', '1.000', '13.000'],
            ['Vis', 'VIS-04', 'C62', '5.000', '0.000', '0.000', '0.000', '0.000', '0.000', '5.000'],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows), 'a product that held stock is a row though it did not move, one that never held any is not');
        self::assertSame('stock-movements', $result->rows[0]['row']->drill?->list);
        self::assertSame(['product' => $drill->getId()->toRfc4122(), 'movedAt.from' => '2026-09-01', 'movedAt.to' => '2026-09-30'], $result->rows[0]['row']->drill->filters);

        $shop = $this->report(StockMovementsReport::class, '2026-09-01', '2026-09-30', $this->shop);
        self::assertSame(['10.000', '4.000', '4.000', '1.000', '1.000', '1.000', '11.000'], \array_slice(array_values($shop->rows[0]['row']->cells), 3), 'the depot\'s receipt is not the shop\'s');
        self::assertSame($this->shelf->getId()->toRfc4122(), $shop->rows[0]['row']->drill?->filters['location'] ?? null, 'the list is opened on the establishment\'s top location');
        $depot = $this->report(StockMovementsReport::class, '2026-09-01', '2026-09-30', $this->depot);
        self::assertSame([['Perceuse', '0.000', '2.000', '2.000']], array_map(static fn (array $line): array => [$line['row']->cells['product'], $line['row']->cells['opening'], $line['row']->cells['received'], $line['row']->cells['closing']], $depot->rows));
    }

    public function testDeadStockIsWhatIsOnHandAndUnsoldForTheCompanysOwnDaysQuietestFirst(): void
    {
        $drill = $this->product('PER-08', 'Perceuse');
        $screws = $this->product('VIS-04', 'Vis');
        $bolts = $this->product('BOU-01', 'Boulon');
        $keys = $this->product('CLE-10', 'Clé');
        $gone = $this->product('GAN-01', 'Gants');
        $hammers = $this->product('MAR-01', 'Marteau');
        $this->save(StockMovement::receipt($drill, $this->shelf, '10', null, $this->at('2026-08-01 10:00'), unitCost: '10'));
        $this->save(StockMovement::delivery($drill, $this->shelf, '2', Uuid::v7(), $this->at('2026-09-06 10:00')));
        // Never sold: its days run from the day it came in.
        $this->save(StockMovement::receipt($screws, $this->shelf, '5', null, $this->at('2026-07-01 10:00'), unitCost: '2'));
        $this->save(StockMovement::receipt($bolts, $this->yard, '2', null, $this->at('2026-12-01 10:00'), unitCost: '1'));
        $this->save(StockMovement::receipt($keys, $this->shelf, '3', null, $this->at('2026-08-01 10:00'), unitCost: '5'));
        $this->save(StockMovement::sale($keys, $this->shelf, '1', Uuid::v7(), $this->at('2026-12-20 10:00')));
        // Its only delivery note was cancelled: never sold, so its days run from the day it came in.
        $this->save(StockMovement::receipt($hammers, $this->shelf, '4', null, $this->at('2026-08-01 10:00'), unitCost: '3'));
        $cancelled = $this->save(StockMovement::delivery($hammers, $this->shelf, '1', Uuid::v7(), $this->at('2026-12-20 10:00')));
        $this->save(StockMovement::returnOf($cancelled, $this->at('2026-12-21 10:00')));
        // Nothing left: not dead stock, whatever its days.
        $this->save(StockMovement::receipt($gone, $this->shelf, '1', null, $this->at('2026-01-01 10:00'), unitCost: '1'));
        $this->save(StockMovement::sale($gone, $this->shelf, '1', Uuid::v7(), $this->at('2026-01-02 10:00')));
        $this->em()->clear();

        $result = $this->report(DeadStockReport::class);

        self::assertSame(['product', 'reference', 'unit', 'on_hand', 'last_sold', 'quiet_days', 'value'], array_map(static fn ($column): string => $column->key, $result->columns));
        self::assertSame([
            ['Vis', 'VIS-04', 'C62', '5.000', null, 183, '10.000'],
            ['Marteau', 'MAR-01', 'C62', '4.000', null, 152, '12.000'],
            ['Perceuse', 'PER-08', 'C62', '8.000', '2026-09-06', 116, '80.000'],
        ], array_map(static fn (array $line): array => array_values($line['row']->cells), $result->rows), 'the default is 90 days: the bolts came in 30 days ago and the keys sold 11 days ago');
        self::assertSame(['product' => $screws->getId()->toRfc4122()], $result->rows[0]['row']->drill?->filters);
        self::assertSame(['product', 'reference', 'unit', 'on_hand', 'last_sold', 'quiet_days'], array_map(static fn ($column): string => $column->key, $this->report(DeadStockReport::class, may: false)->columns));

        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('The company is gone.');
        $this->em()->persist(new Setting(SettingAddress::company($company), 'watch.unsold_after_days', 120, new \DateTimeImmutable()));
        $this->em()->flush();
        self::assertSame(['Vis', 'Marteau'], array_map(static fn (array $line): mixed => $line['row']->cells['product'], $this->report(DeadStockReport::class)->rows), 'past 120 days the drill sold recently enough');

        $depot = $this->report(DeadStockReport::class, establishment: $this->depot);
        self::assertSame([], $depot->rows, 'the depot holds only the bolts, which are not dead yet');
    }

    /** @param class-string<DeclaresReport> $report */
    private function report(string $report, ?string $from = null, ?string $to = null, ?Establishment $establishment = null, bool $may = true): ReportResult
    {
        $declared = static::getContainer()->get($report);
        self::assertInstanceOf(DeclaresReport::class, $declared);
        $period = new ReportPeriod(new \DateTimeImmutable($from ?? '2026-12-31'), new \DateTimeImmutable($to ?? '2026-12-31'));
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('The company is gone.');
        $context = new ReportContext($company, new \DateTimeImmutable('2026-12-31'), static fn (string $permission): bool => $may, $establishment?->getId()->toRfc4122());

        return new RunReport()->run($declared, $context, $period, ReportComparison::None);
    }

    /** A moment in UTC, as the movements store it. */
    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    private function save(StockMovement $movement): StockMovement
    {
        $this->em()->persist($movement);
        $this->em()->flush();

        return $movement;
    }

    private function product(string $reference, string $name, ?Company $company = null): Product
    {
        $company ??= $this->company;
        $product = Product::create($company, $reference, new ProductDetails($name, null, ProductKind::Goods, '1'), $this->unit($company), null, [], new \DateTimeImmutable('2026-01-01'));
        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }

    private function unit(Company $company): Unit
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function establishmentOf(Company $company): Establishment
    {
        return static::getContainer()->get(EstablishmentRepository::class)->ofCompany($company->getId())[0];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
