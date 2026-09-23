<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Fiscal\Domain\Unit;
use App\Module\DeliveryNotes\Domain\DeliveredQuantity;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Application\ModuleStates;
use App\ModuleRegistry\Domain\ModuleState;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryModuleStates;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryStockLocations;
use App\Tests\Support\InMemoryStockLots;
use App\Tests\Support\InMemoryStockMovements;
use App\Tests\Support\RecordingLiveChanges;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class MoveStockForDeliveryNotesTest extends TestCase
{
    private MockClock $clock;
    private InMemoryStockMovements $movements;
    private InMemoryStockLocations $locations;
    private InMemorySettings $settings;
    private InMemoryModuleStates $states;
    private FakeTransactions $transactions;
    private MoveStockForDeliveryNotes $move;
    private Company $company;
    private Establishment $depot;
    private Unit $piece;
    private Unit $kilogram;
    private Product $laptop;
    private Product $flour;
    private Product $support;
    private Product $untracked;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $now = $this->clock->now();
        $this->movements = new InMemoryStockMovements();
        $this->locations = new InMemoryStockLocations();
        $this->settings = new InMemorySettings();
        $this->states = new InMemoryModuleStates();
        $this->transactions = new FakeTransactions();
        $this->movements->transactions = $this->transactions;
        $establishments = new InMemoryEstablishments();
        $products = new InMemoryProducts();
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $establishments->save(Establishment::create($this->company, '000', 'Siège', true, $now));
        $this->depot = Establishment::create($this->company, '001', 'Dépôt de Sfax', false, $now);
        $establishments->save($this->depot);
        $this->piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, $now);
        $this->kilogram = Unit::create($this->company, 'KGM', 'Kilogramme', 3, 2, $now);
        $this->laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250'), $this->piece, null, [], $now);
        $this->flour = Product::create($this->company, 'ART-002', new ProductDetails('Farine', null, ProductKind::Goods, '2'), $this->kilogram, null, [], $now);
        $this->support = Product::create($this->company, 'SRV-001', new ProductDetails('Assistance', null, ProductKind::Service, '50'), $this->piece, null, [], $now);
        $this->untracked = Product::create($this->company, 'ART-009', new ProductDetails('Carton', null, ProductKind::Goods, '1'), $this->piece, null, [], $now);
        foreach ([$this->laptop, $this->flour, $this->support, $this->untracked] as $product) {
            $products->save($product);
        }
        $this->settings->save(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->settings->save(new Setting(SettingAddress::product($this->company, $this->untracked->getId()), 'article.stock_tracking', false, $now));
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings()]), $this->settings));
        $manage = new ManageStockLocations($this->locations, $this->movements, $establishments, new InMemoryAuditTrail($this->transactions), $this->clock, $this->transactions);
        $keep = new KeepStock($this->movements, new InMemoryStockLots(), $this->locations, $products, $read, $this->transactions, $this->clock, new RecordingLiveChanges());
        $modules = new ModuleStates(new ModuleCatalog([new ProductsModule(), new InventoryModule()]), $this->states);
        $this->move = new MoveStockForDeliveryNotes($this->movements, $manage, $establishments, $products, $keep, $modules, $this->transactions, $this->clock);
    }

    public function testAValidatedNoteTakesEachTrackedProductOutOfItsEstablishmentsDefaultLocationOnce(): void
    {
        $noteId = Uuid::v7();
        $lines = [
            $this->line($this->laptop, '2.000', $this->piece),
            $this->line(null, '3.000', $this->piece),
            $this->line($this->flour, '1.500', $this->kilogram),
            $this->line($this->support, '1.000', $this->piece),
            $this->line($this->untracked, '4.000', $this->piece),
            $this->line($this->laptop, '1.000', $this->piece),
        ];

        $skipped = $this->move->validated($noteId, $this->company->getId(), $this->depot->getId(), $lines);
        $again = $this->move->validated($noteId, $this->company->getId(), $this->depot->getId(), $lines);

        self::assertSame([[], []], [$skipped, $again]);
        self::assertSame([['ART-001', '001', 'out', '-3.000'], ['ART-002', '001', 'out', '-1.500']], $this->written());
        self::assertTrue($this->movements->movements[0]->getSourceId()?->equals($noteId));
        self::assertTrue($this->movements->movements[0]->getLocation()->isDefault());
        self::assertSame(1, $this->transactions->committed);
        $default = $this->movements->movements[0]->getLocation()->getId()->toRfc4122();
        $locks = array_values(array_filter($this->movements->calls, static fn (string $call): bool => str_starts_with($call, 'lock ')));
        $expected = array_map(static fn (Product $p): string => 'lock '.$p->getId()->toRfc4122().' '.$default.' in transaction', [$this->laptop, $this->flour]);
        sort($expected);
        self::assertSame($expected, $locks, 'each product is locked once, in a fixed order, before its movement is written');
    }

    public function testALineInAnotherUnitThanItsProductCountsIsLeftOutAndSaid(): void
    {
        $skipped = $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [
            $this->line($this->laptop, '2.500', $this->kilogram),
            $this->line($this->flour, 'beaucoup', $this->kilogram),
            $this->line($this->flour, '1.000', $this->kilogram),
        ]);

        self::assertCount(2, $skipped);
        self::assertStringContainsString('ART-001', $skipped[0]);
        self::assertStringContainsString('ART-002', $skipped[1], 'a quantity that is not a number moves nothing');
        self::assertSame([['ART-002', '001', 'out', '-1.000']], $this->written());
    }

    public function testAProductTrackedByLotOrSerialIsLeftOutAndSaidUntilDeliveriesPickLots(): void
    {
        $this->laptop->track(ProductTracking::Serial, $this->clock->now());

        $skipped = $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [
            $this->line($this->laptop, '1.000', $this->piece),
            $this->line($this->flour, '1.000', $this->kilogram),
        ]);

        self::assertCount(1, $skipped);
        self::assertStringContainsString('ART-001', $skipped[0]);
        self::assertStringContainsString('serial', $skipped[0]);
        self::assertSame([['ART-002', '001', 'out', '-1.000']], $this->written());
    }

    public function testNothingMovesWhileTheCompanyHasInventoryOff(): void
    {
        $this->states->save(ModuleState::of($this->company, InventoryModule::KEY, false, $this->clock->now()));

        $skipped = $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '1.000', $this->piece)]);

        self::assertSame([[], [], []], [$skipped, $this->movements->movements, $this->locations->locations]);
    }

    public function testACancelledNoteReturnsWhatItsValidationTookOutOnlyOnce(): void
    {
        $noteId = Uuid::v7();
        $this->move->validated($noteId, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '2.000', $this->piece), $this->line($this->flour, '0.250', $this->kilogram)]);
        $this->settings->removeAt(SettingAddress::company($this->company));
        $this->states->save(ModuleState::of($this->company, InventoryModule::KEY, false, $this->clock->now()));

        $this->move->cancelled($noteId, $this->company->getId());
        $this->move->cancelled($noteId, $this->company->getId());
        $this->move->cancelled(Uuid::v7(), $this->company->getId());

        self::assertSame([
            ['ART-001', '001', 'out', '-2.000'],
            ['ART-002', '001', 'out', '-0.250'],
            ['ART-001', '001', 'in', '2.000'],
            ['ART-002', '001', 'in', '0.250'],
        ], $this->written(), 'what was taken out comes back even once tracking and the module are off');
        self::assertSame(['0.000', '0.000'], array_map(static fn ($level) => $level->quantity, $this->movements->levels($this->company->getId())));
    }

    private function line(?Product $product, string $quantity, Unit $unit): DeliveredQuantity
    {
        return new DeliveredQuantity($product?->getId(), $quantity, $unit->getId());
    }

    /** @return list<array{string, string, string, string}> */
    private function written(): array
    {
        return array_map(static fn (StockMovement $m) => [$m->getProduct()->getReference(), $m->getLocation()->getCode(), $m->getKind()->value, $m->getQuantity()], $this->movements->movements);
    }
}
