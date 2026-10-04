<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Infrastructure;

use App\Fiscal\Domain\Unit;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Application\TellStockKeepers;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Inventory\Infrastructure\Invoices\MoveStockOnInvoices;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Invoices\Domain\InvoicedQuantity;
use App\Module\Invoices\Domain\InvoiceIssued;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Application\ModuleStates;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryModuleStates;
use App\Tests\Support\InMemoryNotifications;
use App\Tests\Support\InMemoryProductHomeLocations;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryStockLocations;
use App\Tests\Support\InMemoryStockLots;
use App\Tests\Support\InMemoryStockMovements;
use App\Tests\Support\RecordingLiveChanges;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class MoveStockOnInvoicesTest extends TestCase
{
    private InMemoryNotifications $notifications;
    private InMemoryStockMovements $movements;
    private Company $company;
    private Establishment $establishment;
    private Product $laptop;
    private Product $fitting;
    private Unit $piece;

    public function testAnInvoiceTakesWhatItSoldOutOfTheEstablishmentsStockOnceNamingItself(): void
    {
        $invoiceId = Uuid::v7();
        [$listener] = $this->listener(new FakeTransactions());
        $issued = $this->issued([new InvoicedQuantity($this->laptop->getId(), '2.000', $this->piece->getId())], $invoiceId);

        $listener($issued);
        $listener($issued);

        self::assertCount(1, $this->movements->movements, 'an event handled twice moves nothing the second time');
        $movement = $this->movements->movements[0];
        self::assertSame([StockMovementKind::Out, '-2.000', StockMovement::SOURCE_INVOICE], [$movement->getKind(), $movement->getQuantity(), $movement->getSourceType()]);
        self::assertTrue($invoiceId->equals($movement->getSourceId()));
    }

    public function testAServiceAndAnInvoiceWithNothingDrawnByHandMoveNoStockAndAreNotSaid(): void
    {
        [$listener, $logger] = $this->listener(new FakeTransactions());

        $listener($this->issued([new InvoicedQuantity($this->fitting->getId(), '1.000', $this->piece->getId())]));
        $listener($this->issued([]));

        self::assertSame([[], []], [$this->movements->movements, $logger->logged]);
        self::assertSame([], $this->notifications->published);
    }

    public function testACreditNoteTakesNothingOutWhateverItsEventCarries(): void
    {
        [$listener] = $this->listener(new FakeTransactions());

        $listener($this->issued([new InvoicedQuantity($this->laptop->getId(), '1.000', $this->piece->getId())], type: InvoiceType::CreditNote));

        self::assertSame([], $this->movements->movements);
    }

    public function testASaleThatMovedNoStockIsLoggedAndItsStockKeepersToldAndNothingIsThrown(): void
    {
        $failing = new class implements Transactions {
            public function run(callable $work): mixed
            {
                throw new \RuntimeException('connection lost');
            }

            public function active(): bool
            {
                return false;
            }
        };
        [$listener, $logger] = $this->listener($failing);

        $listener($this->issued([new InvoicedQuantity($this->laptop->getId(), '1.000', $this->piece->getId())]));

        self::assertSame([], $this->movements->movements);
        self::assertSame('error', $logger->logged[0][0]);
        self::assertStringContainsString('FAC-2026-00001', $logger->logged[0][1]);
        self::assertSame([TellStockKeepers::INVOICE_MOVED_NO_STOCK], array_map(static fn ($n) => $n->type, $this->notifications->published));
    }

    public function testALineLeftOutIsLoggedAndItsStockKeepersToldWhileTheRestMoves(): void
    {
        $kilogram = Unit::create($this->company, 'KGM', 'Kilogramme', 3, 2, new \DateTimeImmutable());
        [$listener, $logger] = $this->listener(new FakeTransactions());

        $listener($this->issued([
            new InvoicedQuantity($this->laptop->getId(), '1.000', $this->piece->getId()),
            new InvoicedQuantity($this->laptop->getId(), '2.000', $kilogram->getId()),
        ]));

        self::assertCount(1, $this->movements->movements);
        self::assertSame('warning', $logger->logged[0][0]);
        self::assertSame([TellStockKeepers::INVOICE_LINES_LEFT_OUT], array_map(static fn ($n) => $n->type, $this->notifications->published));
    }

    public function testACreditNoteReturnsOnlyWhatItsReturnedLinesNameToTheInvoiceItCorrectsOnce(): void
    {
        $invoiceId = Uuid::v7();
        [$listener] = $this->listener(new FakeTransactions());
        $listener($this->issued([new InvoicedQuantity($this->laptop->getId(), '5.000', $this->piece->getId())], $invoiceId));
        $sold = \count($this->movements->movements);
        $note = $this->issued([], type: InvoiceType::CreditNote, corrects: $invoiceId, returned: [new InvoicedQuantity($this->laptop->getId(), '2.000', $this->piece->getId())]);

        $listener($note);
        $listener($note);

        self::assertCount($sold + 1, $this->movements->movements, 'an event handled twice moves nothing the second time');
        $back = $this->movements->movements[$sold];
        self::assertSame([StockMovementKind::In, '2.000', StockMovement::SOURCE_CREDIT_NOTE], [$back->getKind(), $back->getQuantity(), $back->getSourceType()]);
        self::assertTrue($note->invoiceId->equals($back->getSourceId()));
        self::assertTrue($invoiceId->equals($back->getReversesSourceId()));
        self::assertSame([], $this->notifications->published);
    }

    public function testACreditNoteWithNoReturnedLineOrNoInvoiceItCorrectsMovesNothing(): void
    {
        [$listener] = $this->listener(new FakeTransactions());
        $line = [new InvoicedQuantity($this->laptop->getId(), '1.000', $this->piece->getId())];

        $listener($this->issued([], type: InvoiceType::CreditNote, corrects: Uuid::v7()));
        $listener($this->issued([], type: InvoiceType::CreditNote, returned: $line));

        self::assertSame([], $this->movements->movements);
    }

    public function testAReturnThatBroughtNothingBackIsLoggedAndItsStockKeepersToldAndAFailedOneNeverThrows(): void
    {
        $invoiceId = Uuid::v7();
        [$listener, $logger] = $this->listener(new FakeTransactions());

        $listener($this->issued([], type: InvoiceType::CreditNote, corrects: $invoiceId, returned: [new InvoicedQuantity($this->laptop->getId(), '1.000', $this->piece->getId())]));

        self::assertSame([], $this->movements->movements);
        self::assertSame('warning', $logger->logged[0][0]);
        self::assertStringContainsString('FAC-2026-00001', $logger->logged[0][1]);
        self::assertSame([TellStockKeepers::CREDIT_LINES_NOT_RETURNED], array_map(static fn ($n) => $n->type, $this->notifications->published));

        $failing = new class implements Transactions {
            public function run(callable $work): mixed
            {
                throw new \RuntimeException('connection lost');
            }

            public function active(): bool
            {
                return false;
            }
        };
        $this->notifications->published = [];
        [$broken, $brokenLogger] = $this->listener($failing);

        $broken($this->issued([], type: InvoiceType::CreditNote, corrects: $invoiceId, returned: [new InvoicedQuantity($this->laptop->getId(), '1.000', $this->piece->getId())]));

        self::assertSame('error', $brokenLogger->logged[0][0]);
        self::assertSame([TellStockKeepers::CREDIT_MOVED_NO_STOCK], array_map(static fn ($n) => $n->type, $this->notifications->published));
    }

    protected function setUp(): void
    {
        $this->notifications = new InMemoryNotifications();
        $this->movements = new InMemoryStockMovements();
        $now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->establishment = Establishment::create($this->company, '000', 'Siège', true, $now);
        $this->piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, $now);
        $this->laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250'), $this->piece, null, [], $now);
        $this->fitting = Product::create($this->company, 'ART-002', new ProductDetails('Pose', null, ProductKind::Service, '40'), $this->piece, null, [], $now);
    }

    /** @return array{MoveStockOnInvoices, object{logged: list<array{string, string}>}} */
    private function listener(Transactions $transactions): array
    {
        $clock = new MockClock('2026-09-15 09:00:00');
        $establishments = new InMemoryEstablishments();
        $establishments->save($this->establishment);
        $products = new InMemoryProducts();
        $products->save($this->laptop);
        $products->save($this->fitting);
        $settings = new InMemorySettings();
        $settings->save(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $clock->now()));
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings()]), $settings));
        $locations = new InMemoryStockLocations();
        $manage = new ManageStockLocations($locations, $this->movements, $establishments, new InMemoryAuditTrail($transactions), $clock, $transactions);
        $keep = new KeepStock($this->movements, new InMemoryStockLots(), $locations, $products, $read, $transactions, $clock, new RecordingLiveChanges());
        $modules = new ModuleStates(new ModuleCatalog([new ProductsModule(), new InventoryModule()]), new InMemoryModuleStates());
        $move = new MoveStockForDeliveryNotes($this->movements, $manage, $establishments, $products, $keep, $modules, $transactions, $clock, new InMemoryProductHomeLocations());
        $memberships = new InMemoryMemberships();
        $memberships->save(new Membership(new User(Email::fromString('keeper@acme.test'), 'Keeper'), $this->company, new Role(Role::MEMBER, ['stock.write'], $this->company)));
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $logged = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $placeholders = [];
                foreach ($context as $key => $value) {
                    $placeholders['{'.$key.'}'] = \is_scalar($value) ? (string) $value : '';
                }
                $this->logged[] = [\is_string($level) ? $level : '', strtr((string) $message, $placeholders)];
            }
        };

        return [new MoveStockOnInvoices($move, new TellStockKeepers($memberships, $this->notifications), $logger), $logger];
    }

    /**
     * @param list<InvoicedQuantity> $lines
     * @param list<InvoicedQuantity> $returned
     */
    private function issued(array $lines, ?Uuid $invoiceId = null, InvoiceType $type = InvoiceType::Invoice, ?Uuid $corrects = null, array $returned = []): InvoiceIssued
    {
        return new InvoiceIssued($invoiceId ?? Uuid::v7(), $this->company->getId(), $this->establishment->getId(), $type, 'FAC-2026-00001', new \DateTimeImmutable('2026-09-15'), [], $lines, $corrects, $returned);
    }
}
