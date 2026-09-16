<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Audit\Application\AuditEntry;
use App\Fiscal\Domain\Unit;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Application\StockLocationCodeTaken;
use App\Module\Inventory\Application\StockLocationInUse;
use App\Module\Inventory\Application\StockLocationNotFound;
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryStockLocations;
use App\Tests\Support\InMemoryStockMovements;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageStockLocationsTest extends TestCase
{
    private InMemoryStockLocations $locations;
    private InMemoryStockMovements $movements;
    private InMemoryEstablishments $establishments;
    private InMemoryAuditTrail $audit;
    private MockClock $clock;
    private ManageStockLocations $manage;
    private Company $company;
    private Establishment $main;
    private Establishment $depot;

    protected function setUp(): void
    {
        $this->locations = new InMemoryStockLocations();
        $this->movements = new InMemoryStockMovements();
        $this->establishments = new InMemoryEstablishments();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $this->manage = new ManageStockLocations($this->locations, $this->movements, $this->establishments, $this->audit, $this->clock, $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->main = Establishment::create($this->company, '000', 'Siège', true, $this->clock->now());
        $this->depot = Establishment::create($this->company, '001', 'Dépôt de Sfax', false, $this->clock->now());
        $this->establishments->save($this->main);
        $this->establishments->save($this->depot);
    }

    public function testListingGivesEveryEstablishmentItsDefaultLocationOnce(): void
    {
        $first = $this->manage->list($this->company);
        $second = $this->manage->list($this->company);

        self::assertSame([['000', 'Siège', true], ['001', 'Dépôt de Sfax', true]], array_map(static fn (StockLocation $l) => [$l->getCode(), $l->getName(), $l->isDefault()], $first));
        self::assertSame($first, $second);
        self::assertCount(2, $this->locations->locations);
        self::assertSame([], $this->audit->entries, 'a default location is nobody\'s action');
    }

    public function testALocationWithoutAParentSitsUnderItsEstablishmentsDefaultAndIsAudited(): void
    {
        $actor = Uuid::v7();

        $zone = $this->manage->create($this->company, $this->depot->getId(), null, StockLocationKind::Zone, 'Z1', 'Zone froide', $actor);
        $rack = $this->manage->create($this->company, $this->depot->getId(), $zone->getId(), StockLocationKind::Rack, 'R1', 'Rayonnage 1', $actor);

        self::assertSame(['001', true], [$zone->getParent()?->getCode(), $zone->getParent()?->isDefault()]);
        self::assertSame($zone, $rack->getParent());
        self::assertSame(1, $this->manage->childCount($zone));
        $entry = $this->audit->entries[0];
        self::assertSame([ManageStockLocations::ENTITY_TYPE, ManageStockLocations::CREATED, [], $actor], [$entry->entityType, $entry->action, $entry->changes, $entry->actorUserId]);
        self::assertTrue($zone->getId()->equals($entry->entityId));
        self::assertTrue($this->company->getId()->equals($entry->companyId));
    }

    public function testACodeIsUsedOnceInAnEstablishmentAndWhatIsNotTheCompanysIsRefused(): void
    {
        $zone = $this->manage->create($this->company, $this->main->getId(), null, StockLocationKind::Zone, 'Z1', 'Zone 1', null);
        $this->manage->create($this->company, $this->depot->getId(), null, StockLocationKind::Zone, 'Z1', 'Zone 1', null);
        $other = $this->manage->create($this->company, $this->main->getId(), null, StockLocationKind::Zone, 'Z2', 'Zone 2', null);

        foreach ([
            fn () => $this->manage->create($this->company, $this->main->getId(), null, StockLocationKind::Zone, ' Z1 ', 'Encore', null),
            fn () => $this->manage->revise($this->company, $other->getId(), null, StockLocationKind::Zone, 'Z1', 'Zone 2', null),
        ] as $taken) {
            try {
                $taken();
                self::fail('A code was used twice in an establishment.');
            } catch (StockLocationCodeTaken) {
            }
        }
        self::assertSame([], $this->manage->revise($this->company, $zone->getId(), null, StockLocationKind::Zone, 'Z1', 'Zone 1', null), 'a location keeps its own code');

        $globex = Establishment::create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), '000', 'Globex', true, $this->clock->now());
        $this->establishments->save($globex);
        $theirs = $this->manage->defaultOf($globex);
        foreach ([
            'establishmentId' => fn () => $this->manage->create($this->company, Uuid::v7(), null, StockLocationKind::Zone, 'Z9', 'Zone 9', null),
            'establishmentId ' => fn () => $this->manage->create($this->company, $globex->getId(), null, StockLocationKind::Zone, 'Z9', 'Zone 9', null),
            'parentId' => fn () => $this->manage->create($this->company, $this->main->getId(), Uuid::v7(), StockLocationKind::Zone, 'Z9', 'Zone 9', null),
            'parentId ' => fn () => $this->manage->create($this->company, $this->main->getId(), $theirs->getId(), StockLocationKind::Zone, 'Z9', 'Zone 9', null),
        ] as $field => $refused) {
            try {
                $refused();
                self::fail('The location was accepted.');
            } catch (InvalidStockLocation $invalid) {
                self::assertSame(trim($field), $invalid->field);
            }
        }
        self::assertNull($this->locations->ofCodeInEstablishment('Z9', $this->main->getId()));
    }

    public function testARevisionIsAuditedWithWhatChangedAndANullParentMeansTheDefault(): void
    {
        $zone = $this->manage->create($this->company, $this->main->getId(), null, StockLocationKind::Zone, 'Z1', 'Zone 1', null);
        $rack = $this->manage->create($this->company, $this->main->getId(), $zone->getId(), StockLocationKind::Rack, 'R1', 'Rayonnage 1', null);
        $actor = Uuid::v7();

        $this->manage->revise($this->company, $rack->getId(), null, StockLocationKind::Rack, 'R1', 'Rayonnage 1', $actor);
        $this->manage->revise($this->company, $rack->getId(), null, StockLocationKind::Rack, 'R1', 'Rayonnage 1', $actor);

        self::assertSame('000', $rack->getParent()?->getCode());
        $revisions = array_values(array_filter($this->audit->entries, static fn ($entry) => ManageStockLocations::REVISED === $entry->action));
        self::assertCount(1, $revisions, 'a revision changing nothing is not recorded');
        self::assertSame([['fields' => ['parentId']], $actor], [$revisions[0]->changes, $revisions[0]->actorUserId]);
        $default = $this->manage->defaultOf($this->main);
        self::assertSame(['name'], $this->changedFields(fn () => $this->manage->revise($this->company, $default->getId(), null, StockLocationKind::Site, '000', 'Magasin de Tunis', $actor)));

        $this->expectException(StockLocationNotFound::class);
        $this->manage->revise($this->company, Uuid::v7(), null, StockLocationKind::Rack, 'R1', 'Rayonnage 1', $actor);
    }

    public function testALocationIsDeletedOnlyOnceEmptyAndTheDefaultIsKept(): void
    {
        $default = $this->manage->defaultOf($this->main);
        $zone = $this->manage->create($this->company, $this->main->getId(), null, StockLocationKind::Zone, 'Z1', 'Zone 1', null);
        $rack = $this->manage->create($this->company, $this->main->getId(), $zone->getId(), StockLocationKind::Rack, 'R1', 'Rayonnage 1', null);
        $bin = $this->manage->create($this->company, $this->main->getId(), $zone->getId(), StockLocationKind::Bin, 'B1', 'Casier 1', null);
        $piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, $this->clock->now());
        $laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250'), $piece, null, [], $this->clock->now());
        $this->movements->save(StockMovement::receipt($laptop, $rack, '1', null, $this->clock->now()));

        foreach ([$default, $zone, $rack] as $kept) {
            try {
                $this->manage->delete($this->company, $kept->getId(), null);
                self::fail(\sprintf('The location %s was deleted.', $kept->getCode()));
            } catch (StockLocationInUse) {
            }
        }
        self::assertSame(1, $this->manage->movementCount($rack));

        $actor = Uuid::v7();
        $this->manage->delete($this->company, $bin->getId(), $actor);

        self::assertNull($this->locations->ofIdInCompany($bin->getId(), $this->company->getId()));
        $last = $this->lastEntry();
        self::assertSame([ManageStockLocations::DELETED, $actor], [$last->action, $last->actorUserId]);
        $this->expectException(StockLocationNotFound::class);
        $this->manage->delete($this->company, $bin->getId(), $actor);
    }

    /**
     * The fields the audit recorded for what the call revised; none when it recorded nothing.
     *
     * @param callable(): mixed $revise
     *
     * @return list<string>
     */
    private function changedFields(callable $revise): array
    {
        $before = \count($this->audit->entries);
        $revise();
        if (\count($this->audit->entries) === $before) {
            return [];
        }
        $fields = $this->lastEntry()->changes['fields'] ?? null;

        return \is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
    }

    private function lastEntry(): AuditEntry
    {
        $entries = $this->audit->entries;
        $last = end($entries);
        self::assertInstanceOf(AuditEntry::class, $last);

        return $last;
    }
}
