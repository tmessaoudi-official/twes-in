<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ModuleRegistry\Infrastructure;

use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;
use App\ModuleRegistry\Infrastructure\ApiPlatform\ModuleOwnership;
use App\Tests\Unit\ModuleRegistry\Application\ModuleCatalogTest;
use PHPUnit\Framework\TestCase;

/**
 * Ownership is read from the declaring class's namespace, so the declarations here are classes defined under
 * `App\Module\<Name>\`, which no autoloader maps for tests: they are defined on the spot.
 */
final class ModuleOwnershipTest extends TestCase
{
    public function testAClassBelongsToTheModuleDeclaredInItsModuleDirectory(): void
    {
        $outsider = ModuleCatalogTest::declared(new ModuleManifest('outsider', 'modules.outsider'))[0];

        $ownership = new ModuleOwnership([self::declaration('Ledger', 'Infrastructure', 'LedgerModule', 'ledger'), $outsider]);

        self::assertSame('ledger', $ownership->ownerOf('App\\Module\\Ledger\\Infrastructure\\ApiPlatform\\EntryResource'));
        self::assertSame('ledger', $ownership->ownerOf('App\\Module\\Ledger\\Domain\\Entry'));
        self::assertNull($ownership->ownerOf('App\\Module\\LedgerArchive\\Infrastructure\\ApiPlatform\\EntryResource'), 'a directory whose name merely starts alike');
        self::assertNull($ownership->ownerOf('App\\Settings\\Infrastructure\\ApiPlatform\\SettingResource'), 'the core');
    }

    public function testAModuleDirectoryDeclaresOneModule(): void
    {
        $first = self::declaration('Twice', 'Application', 'FirstModule', 'twice_first');
        $second = self::declaration('Twice', 'Infrastructure', 'SecondModule', 'twice_second');

        $this->expectException(\LogicException::class);
        new ModuleOwnership([$first, $second]);
    }

    private static function declaration(string $module, string $layer, string $class, string $key): DeclaresModule
    {
        $namespace = "App\\Module\\$module\\$layer";
        if (!class_exists("$namespace\\$class", false)) {
            eval(\sprintf(
                'namespace %s; final class %s implements \\%s { public function manifest(): \\%s { return new \\%s(%s, "modules.x"); } }',
                $namespace,
                $class,
                DeclaresModule::class,
                ModuleManifest::class,
                ModuleManifest::class,
                var_export($key, true),
            ));
        }
        $name = "$namespace\\$class";
        $declaration = new $name();
        self::assertInstanceOf(DeclaresModule::class, $declaration);

        return $declaration;
    }
}
