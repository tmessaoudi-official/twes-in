<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ModuleRegistry\Application;

use App\ModuleRegistry\Application\ManageModules;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Application\ModuleDependenciesDisabled;
use App\ModuleRegistry\Application\ModuleManifest;
use App\ModuleRegistry\Application\ModuleRequired;
use App\ModuleRegistry\Application\ModuleStates;
use App\ModuleRegistry\Application\ModuleView;
use App\ModuleRegistry\Application\UnknownModule;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryModuleStates;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * No real module depends on another before G6, so the dependency rules are exercised on fixture manifests: invoices
 * need customers and products.
 */
final class ManageModulesTest extends TestCase
{
    private InMemoryModuleStates $rows;
    private InMemoryAuditTrail $audit;
    private ModuleStates $states;
    private ManageModules $manage;
    private Company $company;

    protected function setUp(): void
    {
        $catalog = new ModuleCatalog(ModuleCatalogTest::declared(
            new ModuleManifest('customers', 'modules.customers'),
            new ModuleManifest('products', 'modules.products'),
            new ModuleManifest('invoices', 'modules.invoices', ['customers', 'products']),
        ));
        $this->rows = new InMemoryModuleStates();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->states = new ModuleStates($catalog, $this->rows);
        $this->manage = new ManageModules($catalog, $this->rows, $this->states, $this->audit, new MockClock('2026-09-14 10:00:00'), $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testEveryModuleIsOnUntilTheCompanySwitchesItOff(): void
    {
        $views = $this->manage->list($this->company);

        self::assertSame([['customers', true], ['invoices', true], ['products', true]], self::summary($views));
        self::assertTrue($this->states->isEnabled($this->company->getId(), 'invoices'));
        self::assertSame(['customers', 'invoices', 'products'], $this->states->enabledKeys($this->company->getId()));
        self::assertSame([], $this->rows->rows, 'nothing is stored before a company changes something');
    }

    public function testSwitchingAModuleOffConcernsThatCompanyAloneAndIsAudited(): void
    {
        $actor = Uuid::v7();

        $view = $this->manage->switch($this->company, 'invoices', false, $actor);

        self::assertFalse($view->enabled);
        self::assertSame('invoices', $view->manifest->key);
        self::assertFalse($this->states->isEnabled($this->company->getId(), 'invoices'));
        self::assertSame(['customers', 'products'], $this->states->enabledKeys($this->company->getId()));
        self::assertSame([['customers', true], ['invoices', false], ['products', true]], self::summary($this->manage->list($this->company)));
        $globex = new Company('Globex', 'FR', 'EUR', 'fr', 'Europe/Paris');
        self::assertTrue($this->states->isEnabled($globex->getId(), 'invoices'));

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame([ManageModules::ENTITY_TYPE, ManageModules::DISABLED, ['key' => 'invoices'], $actor], [$entry->entityType, $entry->action, $entry->changes, $entry->actorUserId]);
        self::assertTrue($this->rows->rows[0]->getId()->equals($entry->entityId));
        self::assertTrue($this->company->getId()->equals($entry->companyId));
    }

    public function testSwitchingBackOnIsAuditedAndSwitchingToTheCurrentStateChangesNothing(): void
    {
        $this->manage->switch($this->company, 'invoices', true, null);
        self::assertSame([], $this->audit->entries);
        self::assertSame([], $this->rows->rows);

        $this->manage->switch($this->company, 'invoices', false, null);
        $this->manage->switch($this->company, 'invoices', false, null);
        self::assertCount(1, $this->audit->entries);

        $view = $this->manage->switch($this->company, 'invoices', true, null);
        self::assertTrue($view->enabled);
        self::assertTrue($this->states->isEnabled($this->company->getId(), 'invoices'));
        self::assertSame([ManageModules::DISABLED, ManageModules::ENABLED], array_map(static fn ($entry) => $entry->action, $this->audit->entries));
        self::assertCount(1, $this->rows->rows, 'one row per module of a company');
    }

    public function testAModuleAnotherEnabledModuleNeedsStaysOn(): void
    {
        try {
            $this->manage->switch($this->company, 'customers', false, null);
            self::fail('A module invoices need was switched off.');
        } catch (ModuleRequired $refused) {
            self::assertSame(['invoices'], $refused->modules);
        }
        self::assertTrue($this->states->isEnabled($this->company->getId(), 'customers'));
        self::assertSame([], $this->audit->entries);

        $this->manage->switch($this->company, 'invoices', false, null);
        self::assertFalse($this->manage->switch($this->company, 'customers', false, null)->enabled);
    }

    public function testAModuleIsSwitchedOnOnlyOnceWhatItNeedsIsOn(): void
    {
        $this->manage->switch($this->company, 'invoices', false, null);
        $this->manage->switch($this->company, 'products', false, null);
        $this->manage->switch($this->company, 'customers', false, null);

        try {
            $this->manage->switch($this->company, 'invoices', true, null);
            self::fail('invoices were switched on without what they need.');
        } catch (ModuleDependenciesDisabled $refused) {
            self::assertSame(['customers', 'products'], $refused->modules);
        }
        self::assertFalse($this->states->isEnabled($this->company->getId(), 'invoices'));

        $this->manage->switch($this->company, 'customers', true, null);
        $this->manage->switch($this->company, 'products', true, null);
        self::assertTrue($this->manage->switch($this->company, 'invoices', true, null)->enabled);
    }

    public function testAnUndeclaredModuleIsNeitherOnNorSwitched(): void
    {
        self::assertFalse($this->states->isEnabled($this->company->getId(), 'vendors'));

        $this->expectException(UnknownModule::class);
        $this->manage->switch($this->company, 'vendors', true, null);
    }

    /**
     * @param list<ModuleView> $views
     *
     * @return list<array{string, bool}>
     */
    private static function summary(array $views): array
    {
        return array_map(static fn (ModuleView $view) => [$view->manifest->key, $view->enabled], $views);
    }
}
