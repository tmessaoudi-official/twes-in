<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ModuleRegistry\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\ModuleRegistry\Application\ManageModules;
use App\ModuleRegistry\Application\ModuleAlreadyAvailable;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Application\ModuleInterests;
use App\ModuleRegistry\Application\ModuleManifest;
use App\ModuleRegistry\Application\PlannedModules;
use App\ModuleRegistry\Application\UnknownModule;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryModuleInterests;
use App\Tests\Support\InMemoryNotifications;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * « Me prévenir » (docs/SPEC.md § 7, 2026-09-26 10:08, row 150): a company asks to be told when a planned module
 * arrives; the operator reads how many companies asked; the day the module ships, the members who may switch it on
 * are told, once.
 */
final class ModuleInterestsTest extends TestCase
{
    private FakeTransactions $transactions;
    private InMemoryModuleInterests $rows;
    private InMemoryAuditTrail $audit;
    private InMemoryMemberships $memberships;
    private InMemoryNotifications $notifications;
    private Company $acme;
    private Company $globex;

    protected function setUp(): void
    {
        $this->rows = new InMemoryModuleInterests();
        $this->transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $this->memberships = new InMemoryMemberships();
        $this->notifications = new InMemoryNotifications();
        $this->acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'FR', 'EUR', 'fr', 'Europe/Paris');
    }

    public function testAskingRecordsTheCompanysInterestOnceAndIsAudited(): void
    {
        $interests = $this->interests(self::planned());
        $actor = Uuid::v7();

        self::assertTrue($interests->set($this->acme, 'quotes', true, $actor));
        self::assertTrue($interests->set($this->acme, 'quotes', true, $actor), 'asking again changes nothing');

        self::assertSame(['quotes'], $interests->keysOf($this->acme->getId()));
        self::assertSame([], $interests->keysOf($this->globex->getId()));
        self::assertCount(1, $this->rows->rows);
        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame([ManageModules::ENTITY_TYPE, ModuleInterests::RECORDED, ['key' => 'quotes'], $actor], [$entry->entityType, $entry->action, $entry->changes, $entry->actorUserId]);
        self::assertTrue($this->acme->getId()->equals($entry->companyId));
    }

    public function testWithdrawingRemovesTheRowAndIsAudited(): void
    {
        $interests = $this->interests(self::planned());
        $interests->set($this->acme, 'quotes', true, null);

        self::assertFalse($interests->set($this->acme, 'quotes', false, null));
        self::assertFalse($interests->set($this->acme, 'quotes', false, null), 'withdrawing again changes nothing');

        self::assertSame([], $interests->keysOf($this->acme->getId()));
        self::assertSame([], $this->rows->rows);
        self::assertSame([ModuleInterests::RECORDED, ModuleInterests::WITHDRAWN], array_map(static fn ($e) => $e->action, $this->audit->entries));
    }

    public function testAModuleThatShipsCannotBeAskedForAndAnUnknownOneIsAbsent(): void
    {
        $interests = $this->interests(self::planned());

        try {
            $interests->set($this->acme, 'customers', true, null);
            self::fail('a real module is refused');
        } catch (ModuleAlreadyAvailable $refused) {
            self::assertSame('customers', $refused->key);
        }
        $this->expectException(UnknownModule::class);
        $interests->set($this->acme, 'teleportation', true, null);
    }

    public function testTheDemandCountsCompaniesPerPlannedModuleMostAskedFirst(): void
    {
        $interests = $this->interests(self::planned());
        $interests->set($this->acme, 'quotes', true, null);
        $interests->set($this->globex, 'quotes', true, null);
        $interests->set($this->globex, 'recurring', true, null);

        $demand = array_map(static fn ($row) => [$row->manifest->key, $row->companies], $interests->demand());

        self::assertSame([['quotes', 2], ['recurring', 1], ['works', 0]], $demand);
    }

    public function testTheDayAModuleShipsTheMembersWhoMaySwitchItOnAreToldOnce(): void
    {
        $this->interests(self::planned())->set($this->acme, 'quotes', true, null);
        $this->interests(self::planned())->set($this->globex, 'recurring', true, null);
        $owner = $this->member('owner@acme.test', $this->acme, [Permission::WILDCARD]);
        $admin = $this->member('admin@acme.test', $this->acme, ['company.read', 'company.settings']);
        $this->member('clerk@acme.test', $this->acme, ['company.read', 'invoice.write']);
        $this->member('owner@globex.test', $this->globex, [Permission::WILDCARD]);
        // `quotes` ships: it leaves the planned list for its own declaration; `recurring` is still to come.
        $shipped = $this->interests([
            new ModuleManifest('quotes', 'modules.quotes', ['customers']),
            new ModuleManifest('recurring', 'modules.recurring', [], planned: 'v1'),
        ]);

        self::assertSame(1, $shipped->announceArrivals());
        self::assertSame(0, $shipped->announceArrivals(), 'a restart tells nobody again');

        self::assertSame([
            ['user:'.$owner->toRfc4122(), ModuleInterests::ARRIVED],
            ['user:'.$admin->toRfc4122(), ModuleInterests::ARRIVED],
        ], array_map(static fn ($n) => [$n->channel, $n->type], $this->notifications->published));
        self::assertSame(['module' => 'quotes', 'label_key' => 'modules.quotes', 'company' => 'Acme'], $this->notifications->published[0]->payload);
        self::assertSame([], $shipped->keysOf($this->acme->getId()), 'an arrived module is no longer asked for');
        self::assertSame(['recurring'], $shipped->keysOf($this->globex->getId()));
    }

    /** @param list<ModuleManifest> $planned the modules not built yet, beside a real `customers` */
    private function interests(array $planned): ModuleInterests
    {
        $real = [new ModuleManifest('customers', 'modules.customers')];
        foreach ($planned as $manifest) {
            if (null === $manifest->planned) {
                $real[] = $manifest;
            }
        }
        $catalog = new ModuleCatalog(
            ModuleCatalogTest::declared(...$real),
            new PlannedModules(array_values(array_filter($planned, static fn (ModuleManifest $m) => null !== $m->planned))),
        );

        return new ModuleInterests($catalog, $this->rows, $this->memberships, $this->notifications, $this->audit, new MockClock('2026-09-26 15:00:00'), $this->transactions);
    }

    /** @return list<ModuleManifest> */
    private static function planned(): array
    {
        return [
            new ModuleManifest('quotes', 'modules.quotes', ['customers'], planned: 'v1'),
            new ModuleManifest('recurring', 'modules.recurring', [], planned: 'v1'),
            new ModuleManifest('works', 'modules.works', ['quotes'], planned: 'later'),
        ];
    }

    /** @param list<string> $permissions */
    private function member(string $email, Company $company, array $permissions): Uuid
    {
        $user = new User(Email::fromString($email), 'Someone');
        $this->memberships->save(new Membership($user, $company, new Role(Role::MEMBER, $permissions, $company)));

        return $user->getId();
    }
}
