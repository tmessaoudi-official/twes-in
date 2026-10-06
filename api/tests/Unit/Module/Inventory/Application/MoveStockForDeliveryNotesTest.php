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
use App\Module\Inventory\Domain\NamedLot;
use App\Module\Inventory\Domain\ProductHomeLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
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
use App\Tests\Support\InMemoryProductHomeLocations;
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
    private InMemoryProductHomeLocations $homes;
    private InMemorySettings $settings;
    private InMemoryModuleStates $states;
    private FakeTransactions $transactions;
    private MoveStockForDeliveryNotes $move;
    private KeepStock $keep;
    private ManageStockLocations $manage;
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
        $this->homes = new InMemoryProductHomeLocations();
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
        $keep = $this->keep = new KeepStock($this->movements, new InMemoryStockLots(), $this->locations, $products, $read, $this->transactions, $this->clock, new RecordingLiveChanges());
        $this->manage = $manage;
        $modules = new ModuleStates(new ModuleCatalog([new ProductsModule(), new InventoryModule()]), $this->states);
        $this->move = new MoveStockForDeliveryNotes($this->movements, $manage, $establishments, $products, $keep, $modules, $this->transactions, $this->clock, $this->homes);
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

    public function testATrackedProductLeavesFromItsFirstLotsToExpireAndComesBackToThemOnCancel(): void
    {
        $this->laptop->track(ProductTracking::Lot, $this->clock->now());
        $depot = $this->manage->defaultOf($this->depot)->getId();
        foreach ([['NOVEMBER', '2026-11-01', '5'], ['OCTOBER', '2026-10-01', '2'], ['AUGUST', '2026-08-31', '3']] as [$code, $date, $quantity]) {
            $this->keep->receive($this->company, $this->laptop->getId(), $depot, $quantity, null, new NamedLot($code, new \DateTimeImmutable($date)));
        }
        $received = \count($this->movements->movements);
        $first = Uuid::v7();

        $skipped = $this->move->validated($first, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '4.000', $this->piece)]);

        self::assertSame([], $skipped);
        self::assertSame([['OCTOBER', '-2.000'], ['NOVEMBER', '-2.000']], $this->lotsWritten($received), 'the expired August lot stays');

        $skipped = $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '6.000', $this->piece)]);
        self::assertSame([['OCTOBER', '-2.000'], ['NOVEMBER', '-2.000'], ['NOVEMBER', '-3.000']], $this->lotsWritten($received));
        self::assertCount(1, $skipped);
        self::assertStringContainsString('3.000 of ART-001', $skipped[0]);

        $this->move->cancelled($first, $this->company->getId());
        self::assertSame([['OCTOBER', '2.000'], ['NOVEMBER', '2.000']], \array_slice($this->lotsWritten($received), 3), 'each lot gets back what left it');
    }

    public function testALineNamingItsLotTakesThatLotAndOnlyTheOthersGoFirstToExpire(): void
    {
        // docs/SPEC.md § 7, 2026-09-24 12:40 row 5: validation takes the named lot, first-to-expire only for a line naming none.
        $this->laptop->track(ProductTracking::Lot, $this->clock->now());
        $depot = $this->manage->defaultOf($this->depot)->getId();
        foreach ([['NOVEMBER', '2026-11-01', '5'], ['OCTOBER', '2026-10-01', '2'], ['AUGUST', '2026-08-31', '3']] as [$code, $date, $quantity]) {
            $this->keep->receive($this->company, $this->laptop->getId(), $depot, $quantity, null, new NamedLot($code, new \DateTimeImmutable($date)));
        }
        $received = \count($this->movements->movements);
        $first = Uuid::v7();

        $skipped = $this->move->validated($first, $this->company->getId(), $this->depot->getId(), [
            $this->line($this->laptop, '1.000', $this->piece),
            $this->line($this->laptop, '3.000', $this->piece, 'november'),
        ]);

        self::assertSame([], $skipped);
        self::assertSame([['NOVEMBER', '-3.000'], ['OCTOBER', '-1.000']], $this->lotsWritten($received), 'the named lot first, whatever its case, then the first to expire for the line naming none');

        $skipped = $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [
            $this->line($this->laptop, '4.000', $this->piece, 'OCTOBER'),
            $this->line($this->laptop, '1.000', $this->piece, 'DECEMBER'),
            $this->line($this->laptop, '2.000', $this->piece, 'AUGUST'),
        ]);

        self::assertSame([['NOVEMBER', '-3.000'], ['OCTOBER', '-1.000'], ['OCTOBER', '-1.000']], $this->lotsWritten($received), 'nothing is taken from another lot than the one named');
        self::assertCount(3, $skipped);
        self::assertStringContainsString('3.000 of ART-001 lot OCTOBER', $skipped[0]);
        self::assertStringContainsString('lot DECEMBER', $skipped[1]);
        self::assertStringContainsString('was not at', $skipped[1]);
        self::assertStringNotContainsString('expired', $skipped[1], 'a lot never received is not called expired');
        self::assertStringContainsString('lot AUGUST', $skipped[2]);
        self::assertStringContainsString('expired', $skipped[2]);

        $this->move->cancelled($first, $this->company->getId());
        self::assertSame([['NOVEMBER', '3.000'], ['OCTOBER', '1.000']], \array_slice($this->lotsWritten($received), 3));
    }

    /** A place of the depot, filed under its default location, that a product can be given as a home. */
    private function shelf(string $code): StockLocation
    {
        return $this->manage->create($this->company, $this->depot->getId(), null, StockLocationKind::Rack, $code, 'Rayon '.$code, null);
    }

    /** The product's homes in the depot, the first the main one. */
    private function homesAt(Product $product, StockLocation ...$places): void
    {
        foreach (array_values($places) as $position => $place) {
            $this->homes->save(ProductHomeLocation::at($product, $place, $position, $this->clock->now()));
        }
    }

    public function testADeliveryTakesGoodsFromTheMainHomeFirstThenTheNextInOrderNeverMoreThanIsThere(): void
    {
        $r1 = $this->shelf('R1');
        $r2 = $this->shelf('R2');
        $this->homesAt($this->laptop, $r1, $r2);
        $this->keep->receive($this->company, $this->laptop->getId(), $r1->getId(), '3', null);
        $this->keep->receive($this->company, $this->laptop->getId(), $r2->getId(), '10', null);
        $before = \count($this->movements->movements);

        $skipped = $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '5.000', $this->piece)]);

        self::assertSame([], $skipped);
        self::assertSame([['ART-001', 'R1', 'out', '-3.000'], ['ART-001', 'R2', 'out', '-2.000']], \array_slice($this->written(), $before), 'the main home is emptied first, the next takes the rest, and nothing goes below zero');
    }

    /** Audit 2026-10-06, H-a29: goods in quarantine wait for a decision, so a delivery never takes them, even from a home. */
    public function testADeliveryNeverTakesGoodsFromAQuarantinePlaceEvenOneThatIsAHome(): void
    {
        $held = $this->manage->create($this->company, $this->depot->getId(), null, StockLocationKind::Quarantine, 'Q1', 'Quarantaine', null);
        $r1 = $this->shelf('R1');
        $this->homesAt($this->laptop, $held, $r1);
        $this->keep->receive($this->company, $this->laptop->getId(), $held->getId(), '5', null);
        $this->keep->receive($this->company, $this->laptop->getId(), $r1->getId(), '3', null);
        $before = \count($this->movements->movements);

        $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '2.000', $this->piece)]);

        self::assertSame([['ART-001', 'R1', 'out', '-2.000']], \array_slice($this->written(), $before));
    }

    public function testAProductWithNoHomeStillLeavesFromTheDefaultLocation(): void
    {
        $r1 = $this->shelf('R1');
        $this->keep->receive($this->company, $this->laptop->getId(), $r1->getId(), '9', null);
        $before = \count($this->movements->movements);

        $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '2.000', $this->piece)]);

        self::assertSame([['ART-001', '001', 'out', '-2.000']], \array_slice($this->written(), $before));
    }

    public function testGoodsStillAtTheDefaultLocationLeaveFromItWhenTheHomesWereSetAfterTheyArrived(): void
    {
        $r1 = $this->shelf('R1');
        $r2 = $this->shelf('R2');
        $default = $this->manage->defaultOf($this->depot);
        $this->keep->receive($this->company, $this->laptop->getId(), $default->getId(), '6', null);
        $this->homesAt($this->laptop, $r1, $r2);
        $before = \count($this->movements->movements);

        $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '4.000', $this->piece)]);

        self::assertSame([['ART-001', '001', 'out', '-4.000']], \array_slice($this->written(), $before), 'no home goes negative while the default location holds the goods');
    }

    public function testWhatNoPlaceHoldsGoesOnTheMainHomeAsOneMovement(): void
    {
        $r1 = $this->shelf('R1');
        $r2 = $this->shelf('R2');
        $this->homesAt($this->laptop, $r1, $r2);
        $this->keep->receive($this->company, $this->laptop->getId(), $r1->getId(), '3', null);
        $this->keep->receive($this->company, $this->laptop->getId(), $r2->getId(), '2', null);
        $before = \count($this->movements->movements);

        $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '8.000', $this->piece)]);

        self::assertSame([['ART-001', 'R1', 'out', '-6.000'], ['ART-001', 'R2', 'out', '-2.000']], \array_slice($this->written(), $before), 'the shortfall of three joins the main home\'s own movement');
    }

    public function testASplitDeliveryAndAnInvoiceComeBackToEachPlaceTheyLeft(): void
    {
        $r1 = $this->shelf('R1');
        $r2 = $this->shelf('R2');
        $this->homesAt($this->laptop, $r1, $r2);
        $this->keep->receive($this->company, $this->laptop->getId(), $r1->getId(), '3', null);
        $this->keep->receive($this->company, $this->laptop->getId(), $r2->getId(), '10', null);
        $note = Uuid::v7();
        $invoice = Uuid::v7();

        $this->move->validated($note, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '5.000', $this->piece)]);
        $this->move->invoiced($invoice, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '4.000', $this->piece)]);
        $this->move->cancelled($note, $this->company->getId());
        $this->move->returned(Uuid::v7(), $invoice, $this->company->getId(), [$this->line($this->laptop, '4.000', $this->piece)]);

        $levels = [];
        foreach ($this->movements->levels($this->company->getId()) as $level) {
            $levels[$level->locationId->toRfc4122()] = $level->quantity;
        }
        self::assertSame([$r1->getId()->toRfc4122() => '3.000', $r2->getId()->toRfc4122() => '10.000'], $levels, 'each place holds again what was received');
    }

    public function testEveryPlaceAProductMayLeaveFromIsLockedInOneFixedOrderBeforeAnythingIsRead(): void
    {
        $r1 = $this->shelf('R1');
        $r2 = $this->shelf('R2');
        $this->homesAt($this->laptop, $r2, $r1);
        $already = \count($this->movements->calls);

        $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '1.000', $this->piece)]);

        $locks = array_values(array_filter(\array_slice($this->movements->calls, $already), static fn (string $call): bool => str_starts_with($call, 'lock ')));
        $default = $this->manage->defaultOf($this->depot)->getId()->toRfc4122();
        $expected = array_map(fn (string $id): string => 'lock '.$this->laptop->getId()->toRfc4122().' '.$id.' in transaction', [$r1->getId()->toRfc4122(), $r2->getId()->toRfc4122(), $default]);
        sort($expected);
        self::assertSame($expected, $locks, 'the two homes and the default location, sorted, not in the order of the homes');
    }

    public function testALotAtTwoPlacesIsTakenFromTheMainHomeBeforeAnEarlierExpiringOneAtTheNextAndNeverCountedTwice(): void
    {
        $this->laptop->track(ProductTracking::Lot, $this->clock->now());
        $r1 = $this->shelf('R1');
        $r2 = $this->shelf('R2');
        $this->homesAt($this->laptop, $r1, $r2);
        $this->keep->receive($this->company, $this->laptop->getId(), $r1->getId(), '2', null, new NamedLot('NOVEMBER', new \DateTimeImmutable('2026-11-01')));
        $this->keep->receive($this->company, $this->laptop->getId(), $r2->getId(), '2', null, new NamedLot('OCTOBER', new \DateTimeImmutable('2026-10-01')));
        $this->keep->receive($this->company, $this->laptop->getId(), $r2->getId(), '1', null, new NamedLot('NOVEMBER', new \DateTimeImmutable('2026-11-01')));
        $before = \count($this->movements->movements);

        $skipped = $this->move->validated(Uuid::v7(), $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '5.000', $this->piece)]);

        self::assertSame([], $skipped);
        $taken = array_map(static fn (StockMovement $m) => [$m->getLocation()->getCode(), (string) $m->getLot()?->getCode(), $m->getQuantity()], \array_slice($this->movements->movements, $before));
        self::assertSame([['R1', 'NOVEMBER', '-2.000'], ['R2', 'OCTOBER', '-2.000'], ['R2', 'NOVEMBER', '-1.000']], $taken, 'the main home first, then first-to-expire within the next one; the November lot of R2 is not reduced by what R1 gave');
    }

    /** @return list<array{string, string}> the lot and quantity of each movement written after the first $from */
    private function lotsWritten(int $from): array
    {
        return array_map(static fn (StockMovement $m) => [(string) $m->getLot()?->getCode(), $m->getQuantity()], \array_slice($this->movements->movements, $from));
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

    public function testACreditNoteReturnsGoodsToTheLotsTheSaleTookThemFromAtTheirCostOnceOnly(): void
    {
        $this->laptop->track(ProductTracking::Lot, $this->clock->now());
        $depot = $this->manage->defaultOf($this->depot)->getId();
        foreach ([['NOVEMBER', '2026-11-01', '5'], ['OCTOBER', '2026-10-01', '2']] as [$code, $date, $quantity]) {
            $this->keep->receive($this->company, $this->laptop->getId(), $depot, $quantity, null, new NamedLot($code, new \DateTimeImmutable($date)), '700');
        }
        $invoice = Uuid::v7();
        $this->move->invoiced($invoice, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '4.000', $this->piece)]);
        $sold = \count($this->movements->movements);
        $creditNote = Uuid::v7();

        $skipped = $this->move->returned($creditNote, $invoice, $this->company->getId(), [$this->line($this->laptop, '3.000', $this->piece)]);
        $again = $this->move->returned($creditNote, $invoice, $this->company->getId(), [$this->line($this->laptop, '3.000', $this->piece)]);

        self::assertSame([[], []], [$skipped, $again]);
        self::assertSame([['OCTOBER', '2.000'], ['NOVEMBER', '1.000']], $this->lotsWritten($sold), 'back to the lots the sale left, in the order it took them, once');
        $back = \array_slice($this->movements->movements, $sold);
        self::assertSame([StockMovement::SOURCE_CREDIT_NOTE, $creditNote->toRfc4122(), $invoice->toRfc4122(), '700.0000', false], [$back[0]->getSourceType(), $back[0]->getSourceId()?->toRfc4122(), $back[0]->getReversesSourceId()?->toRfc4122(), $back[0]->getUnitCost(), $back[0]->isCostTyped()]);
    }

    public function testASaleIsReturnedForNoMoreThanItTookOutLessWhatEarlierCreditNotesReturnedAndSaysWhatCameBackShort(): void
    {
        $invoice = Uuid::v7();
        $this->move->invoiced($invoice, $this->company->getId(), $this->depot->getId(), [$this->line($this->untracked, '4.000', $this->piece), $this->line($this->laptop, '4.000', $this->piece)]);
        $sold = \count($this->movements->movements);

        $first = $this->move->returned(Uuid::v7(), $invoice, $this->company->getId(), [$this->line($this->laptop, '3.000', $this->piece)]);
        $second = $this->move->returned(Uuid::v7(), $invoice, $this->company->getId(), [$this->line($this->laptop, '3.000', $this->piece)]);
        $third = $this->move->returned(Uuid::v7(), $invoice, $this->company->getId(), [$this->line($this->laptop, '1.000', $this->piece)]);

        self::assertSame([[], 1, 1], [$first, \count($second), \count($third)]);
        self::assertStringContainsString('2.000 of ART-001', $second[0]);
        self::assertSame([['', '3.000'], ['', '1.000']], $this->lotsWritten($sold), 'the second note got back the one piece left, the third nothing');
    }

    public function testALineNamingALotOrAProductTheInvoiceNeverSoldReturnsNothingAndIsSaid(): void
    {
        $this->laptop->track(ProductTracking::Lot, $this->clock->now());
        $depot = $this->manage->defaultOf($this->depot)->getId();
        foreach ([['NOVEMBER', '2026-11-01', '5'], ['OCTOBER', '2026-10-01', '2']] as [$code, $date, $quantity]) {
            $this->keep->receive($this->company, $this->laptop->getId(), $depot, $quantity, null, new NamedLot($code, new \DateTimeImmutable($date)));
        }
        $invoice = Uuid::v7();
        $this->move->invoiced($invoice, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '4.000', $this->piece, 'NOVEMBER')]);
        $sold = \count($this->movements->movements);

        $skipped = $this->move->returned(Uuid::v7(), $invoice, $this->company->getId(), [
            $this->line($this->laptop, '1.000', $this->piece, 'OCTOBER'),
            $this->line($this->flour, '1.000', $this->kilogram),
            $this->line($this->laptop, '2.500', $this->kilogram, 'NOVEMBER'),
            $this->line($this->laptop, '1.000', $this->piece, 'november'),
        ]);

        self::assertSame([['NOVEMBER', '1.000']], $this->lotsWritten($sold), 'only the line that names a lot the sale took, whatever its case');
        self::assertCount(3, $skipped);
        // A line the unit refuses is said while the lines are read, the others once the stock is, so the order is not the lines'.
        $said = static fn (string $part): bool => array_any($skipped, static fn (string $reason): bool => str_contains($reason, $part));
        self::assertSame([true, true, true], [$said('1.000 of ART-001 lot OCTOBER'), $said('1.000 of ART-002'), $said('a line of ART-001 is counted in another unit')]);
        self::assertSame([], $this->move->returned(Uuid::v7(), Uuid::v7(), $this->company->getId(), []), 'a credit note with no returned line moves nothing and says nothing');
    }

    /**
     * An invoice built from delivery notes sold what the notes took out, so its credit note returns against them
     * (docs/SPEC.md § 7, audit 2026-10-06 E-5): once per place, at what the goods left at across the notes, and named
     * after the invoice, so a later credit note counts what came back.
     */
    public function testACreditNoteOfAnInvoiceBuiltFromDeliveryNotesReturnsWhatTheNotesTookOutAtTheirCost(): void
    {
        $depot = $this->manage->defaultOf($this->depot)->getId();
        $this->keep->receive($this->company, $this->laptop->getId(), $depot, '5', null, null, '700');
        $first = Uuid::v7();
        $this->move->validated($first, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '2.000', $this->piece)]);
        $this->keep->receive($this->company, $this->laptop->getId(), $depot, '5', null, null, '1000');
        $second = Uuid::v7();
        $this->move->validated($second, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '3.000', $this->piece)]);
        $invoice = Uuid::v7();
        $sold = \count($this->movements->movements);

        $skipped = $this->move->returned(Uuid::v7(), $invoice, $this->company->getId(), [$this->line($this->laptop, '4.000', $this->piece)], [$first, $second]);
        $short = $this->move->returned(Uuid::v7(), $invoice, $this->company->getId(), [$this->line($this->laptop, '2.000', $this->piece)], [$first, $second]);

        self::assertSame([], $skipped);
        self::assertCount(1, $short);
        self::assertStringContainsString('1.000 of ART-001', $short[0]);
        $back = \array_slice($this->movements->movements, $sold);
        self::assertSame([['4.000', '812.5000', $invoice->toRfc4122()], ['1.000', '812.5000', $invoice->toRfc4122()]], array_map(
            static fn (StockMovement $movement): array => [$movement->getQuantity(), $movement->getUnitCost(), $movement->getReversesSourceId()?->toRfc4122()],
            $back,
        ), 'one return per place, at 2 × 700 and 3 × 887.5 over 5, the second given what the first left');
    }

    public function testWithoutItsDeliveryNotesAnInvoiceThatSoldNothingItselfReturnsNothing(): void
    {
        $note = Uuid::v7();
        $this->move->validated($note, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '2.000', $this->piece)]);

        $skipped = $this->move->returned(Uuid::v7(), Uuid::v7(), $this->company->getId(), [$this->line($this->laptop, '1.000', $this->piece)], []);

        self::assertCount(1, $skipped, 'another invoice\'s notes are not this one\'s');
    }

    public function testWhatASaleTookOutComesBackEvenOnceTheModuleIsOff(): void
    {
        $invoice = Uuid::v7();
        $this->move->invoiced($invoice, $this->company->getId(), $this->depot->getId(), [$this->line($this->laptop, '2.000', $this->piece)]);
        $this->states->save(ModuleState::of($this->company, InventoryModule::KEY, false, $this->clock->now()));

        $skipped = $this->move->returned(Uuid::v7(), $invoice, $this->company->getId(), [$this->line($this->laptop, '2.000', $this->piece)]);

        self::assertSame([], $skipped);
        self::assertSame(['0.000'], array_map(static fn ($level) => $level->quantity, $this->movements->levels($this->company->getId())));
    }

    private function line(?Product $product, string $quantity, Unit $unit, ?string $lotCode = null): DeliveredQuantity
    {
        return new DeliveredQuantity($product?->getId(), $quantity, $unit->getId(), $lotCode);
    }

    /** @return list<array{string, string, string, string}> */
    private function written(): array
    {
        return array_map(static fn (StockMovement $m) => [$m->getProduct()->getReference(), $m->getLocation()->getCode(), $m->getKind()->value, $m->getQuantity()], $this->movements->movements);
    }
}
