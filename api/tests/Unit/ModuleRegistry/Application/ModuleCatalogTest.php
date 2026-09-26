<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ModuleRegistry\Application;

use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Application\ModuleManifest;
use App\ModuleRegistry\Application\PlannedModules;
use PHPUnit\Framework\TestCase;

final class ModuleCatalogTest extends TestCase
{
    public function testItListsTheDeclaredModulesByKey(): void
    {
        $products = new ModuleManifest('products', 'modules.products');
        $customers = new ModuleManifest('customers', 'modules.customers', [], ['customer.read']);

        $catalog = new ModuleCatalog(self::declared($products, $customers));

        self::assertSame([$customers, $products], $catalog->all());
        self::assertSame($products, $catalog->get('products'));
        self::assertNull($catalog->get('vendors'));
    }

    public function testAModuleKeyIsLowercaseLettersDigitsAndUnderscores(): void
    {
        foreach (['Customers', 'c', 'delivery-notes', '1invoices', str_repeat('a', 41)] as $key) {
            try {
                new ModuleManifest($key, 'modules.x');
                self::fail("$key was accepted.");
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('delivery_notes', (new ModuleManifest('delivery_notes', 'modules.delivery_notes'))->key);
    }

    // docs/SPEC.md § 7, 2026-09-26 10:08 (row 150): the complete product shows, what is not built yet as planned.
    public function testAPlannedModuleSaysWhenItIsExpectedAndChecksNothingYet(): void
    {
        self::assertSame('v1', (new ModuleManifest('quotes', 'modules.quotes', planned: 'v1'))->planned);
        self::assertSame('later', (new ModuleManifest('zakat', 'modules.zakat', planned: 'later'))->planned);
        self::assertNull((new ModuleManifest('customers', 'modules.customers'))->planned);
        foreach ([['soon', []], ['v1', ['quote.read']]] as [$planned, $permissions]) {
            try {
                new ModuleManifest('quotes', 'modules.quotes', [], $permissions, $planned);
                self::fail("$planned with ".\count($permissions).' permissions was accepted.');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPlannedModulesAreListedWithTheRealOnesAndMayNeedEither(): void
    {
        $customers = new ModuleManifest('customers', 'modules.customers');
        $quotes = new ModuleManifest('quotes', 'modules.quotes', ['customers'], planned: 'v1');
        $works = new ModuleManifest('works', 'modules.works', ['quotes'], planned: 'v1');

        $catalog = new ModuleCatalog(self::declared($customers), new PlannedModules([$works, $quotes]));

        self::assertSame([$customers, $quotes, $works], $catalog->all());
        self::assertSame($quotes, $catalog->get('quotes'));
    }

    public function testAModuleThatShipsCannotNeedAPlannedOne(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('invoices depends on quotes, which is only planned');
        new ModuleCatalog(
            self::declared(new ModuleManifest('invoices', 'modules.invoices', ['quotes'])),
            new PlannedModules([new ModuleManifest('quotes', 'modules.quotes', planned: 'v1')]),
        );
    }

    public function testAModuleThatShipsReplacesItsPlannedEntryRatherThanJoiningIt(): void
    {
        $this->expectException(\LogicException::class);
        new ModuleCatalog(
            self::declared(new ModuleManifest('quotes', 'modules.quotes')),
            new PlannedModules([new ModuleManifest('quotes', 'modules.quotes', planned: 'v1')]),
        );
    }

    public function testAKeyIsDeclaredOnce(): void
    {
        $this->expectException(\LogicException::class);
        new ModuleCatalog(self::declared(new ModuleManifest('customers', 'a'), new ModuleManifest('customers', 'b')));
    }

    public function testAModuleDoesNotDependOnItself(): void
    {
        $this->expectException(\LogicException::class);
        new ModuleCatalog(self::declared(new ModuleManifest('customers', 'a', ['customers'])));
    }

    public function testADependencyIsADeclaredModule(): void
    {
        $this->expectException(\LogicException::class);
        new ModuleCatalog(self::declared(new ModuleManifest('invoices', 'a', ['customers'])));
    }

    public function testDependenciesFormNoCycleThoughTwoModulesMayShareOne(): void
    {
        $shared = new ModuleCatalog(self::declared(
            new ModuleManifest('customers', 'a'),
            new ModuleManifest('products', 'b', ['customers']),
            new ModuleManifest('delivery_notes', 'c', ['customers']),
            new ModuleManifest('invoices', 'd', ['products', 'delivery_notes']),
        ));
        self::assertCount(4, $shared->all());

        $this->expectException(\LogicException::class);
        new ModuleCatalog(self::declared(
            new ModuleManifest('customers', 'a', ['invoices']),
            new ModuleManifest('products', 'b', ['customers']),
            new ModuleManifest('invoices', 'c', ['products']),
        ));
    }

    public function testItNamesTheModulesThatNeedAModule(): void
    {
        $catalog = new ModuleCatalog(self::declared(
            new ModuleManifest('invoices', 'c', ['customers', 'products']),
            new ModuleManifest('customers', 'a'),
            new ModuleManifest('products', 'b', ['customers']),
        ));

        self::assertSame(['invoices', 'products'], $catalog->dependentsOf('customers'));
        self::assertSame(['invoices'], $catalog->dependentsOf('products'));
        self::assertSame([], $catalog->dependentsOf('invoices'));
    }

    /** @return list<DeclaresModule> */
    public static function declared(ModuleManifest ...$manifests): array
    {
        return array_map(static fn (ModuleManifest $manifest) => new class($manifest) implements DeclaresModule {
            public function __construct(private readonly ModuleManifest $declared)
            {
            }

            public function manifest(): ModuleManifest
            {
                return $this->declared;
            }
        }, array_values($manifests));
    }
}
