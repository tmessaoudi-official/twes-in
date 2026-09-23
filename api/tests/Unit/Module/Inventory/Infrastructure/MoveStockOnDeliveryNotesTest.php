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
use App\Module\DeliveryNotes\Domain\DeliveredQuantity;
use App\Module\DeliveryNotes\Domain\DeliveryNoteValidated;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Application\TellStockKeepers;
use App\Module\Inventory\Infrastructure\DeliveryNotes\MoveStockOnDeliveryNotes;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
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

final class MoveStockOnDeliveryNotesTest extends TestCase
{
    private InMemoryNotifications $notifications;
    private InMemoryStockMovements $movements;
    private Company $company;
    private Establishment $establishment;
    private Product $laptop;
    private Unit $piece;

    public function testAValidatedNoteThatMovedNoStockIsLoggedAndItsStockKeepersToldAndNothingIsThrown(): void
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

        $listener->onValidated($this->validated([new DeliveredQuantity($this->laptop->getId(), '1.000', $this->piece->getId())]));

        self::assertSame([], $this->movements->movements);
        self::assertSame('error', $logger->logged[0][0]);
        self::assertStringContainsString('BL-2026-00001', $logger->logged[0][1]);
        self::assertSame([TellStockKeepers::MOVED_NO_STOCK], array_map(static fn ($n) => $n->type, $this->notifications->published));
    }

    public function testALineLeftOutIsLoggedAndItsStockKeepersToldWhileTheRestMoves(): void
    {
        $kilogram = Unit::create($this->company, 'KGM', 'Kilogramme', 3, 2, new \DateTimeImmutable());
        [$listener, $logger] = $this->listener(new FakeTransactions());

        $listener->onValidated($this->validated([
            new DeliveredQuantity($this->laptop->getId(), '1.000', $this->piece->getId()),
            new DeliveredQuantity($this->laptop->getId(), '2.000', $kilogram->getId()),
        ]));

        self::assertCount(1, $this->movements->movements);
        self::assertSame('warning', $logger->logged[0][0]);
        self::assertSame([TellStockKeepers::LINES_LEFT_OUT], array_map(static fn ($n) => $n->type, $this->notifications->published));
    }

    public function testANoteThatMovedEverythingTellsNobody(): void
    {
        [$listener, $logger] = $this->listener(new FakeTransactions());
        $listener->onValidated($this->validated([new DeliveredQuantity($this->laptop->getId(), '1.000', $this->piece->getId())]));

        self::assertSame([[], 1], [$this->notifications->published, \count($this->movements->movements)]);
        self::assertSame([], $logger->logged);
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
    }

    /** @return array{MoveStockOnDeliveryNotes, object{logged: list<array{string, string}>}} */
    private function listener(Transactions $transactions): array
    {
        $clock = new MockClock('2026-09-15 09:00:00');
        $establishments = new InMemoryEstablishments();
        $establishments->save($this->establishment);
        $products = new InMemoryProducts();
        $products->save($this->laptop);
        $settings = new InMemorySettings();
        $settings->save(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $clock->now()));
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings()]), $settings));
        $locations = new InMemoryStockLocations();
        $manage = new ManageStockLocations($locations, $this->movements, $establishments, new InMemoryAuditTrail($transactions), $clock, $transactions);
        $keep = new KeepStock($this->movements, new InMemoryStockLots(), $locations, $products, $read, $transactions, $clock, new RecordingLiveChanges());
        $modules = new ModuleStates(new ModuleCatalog([new ProductsModule(), new InventoryModule()]), new InMemoryModuleStates());
        $move = new MoveStockForDeliveryNotes($this->movements, $manage, $establishments, $products, $keep, $modules, $transactions, $clock);
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

        return [new MoveStockOnDeliveryNotes($move, new TellStockKeepers($memberships, $this->notifications), $logger), $logger];
    }

    /** @param list<DeliveredQuantity> $lines */
    private function validated(array $lines): DeliveryNoteValidated
    {
        return new DeliveryNoteValidated(Uuid::v7(), $this->company->getId(), $this->establishment->getId(), 'BL-2026-00001', new \DateTimeImmutable('2026-09-15'), $lines);
    }
}
