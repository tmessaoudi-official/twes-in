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
