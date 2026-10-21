<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Model\Role;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261006100000;
use DoctrineMigrations\Version20261021131500;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

/**
 * A permission split out of another keeps what roles already did: a role a company made for itself before the split, and
 * holding the permission that used to cover the action, is granted the new one. A built-in role is the seed's to define,
 * and a role that never held the old permission gains nothing. Credit notes left `invoice.write` for `invoice.credit`
 * (docs/SPEC.md § 7, audit 2026-10-06 B-12); deleting and merging customers left `customer.write` for `customer.remove`.
 */
final class PermissionGrantMigrationsTest extends ApiTestCase
{
    /** @return iterable<string, array{class-string<AbstractMigration>, string, string, string}> */
    public static function splits(): iterable
    {
        yield 'credit notes' => [Version20261006100000::class, 'invoice.read', 'invoice.write', 'invoice.credit'];
        yield 'removing customers' => [Version20261021131500::class, 'customer.read', 'customer.write', 'customer.remove'];
    }

    /** @param class-string<AbstractMigration> $migration */
    #[DataProvider('splits')]
    public function testACompanysRoleThatHeldTheOldPermissionKeepsTheActionAndNoOtherRoleGainsIt(string $migration, string $read, string $old, string $new): void
    {
        $company = $this->createCompany('Acme');
        $holder = new Role('comptoir', [$read, $old], $company);
        $reader = new Role('lecteur', [$read], $company);
        $already = new Role('gérant', [$old, $new], $company);
        $builtIn = new Role(Role::CLERK, [$old]);
        foreach ([$holder, $reader, $already, $builtIn] as $role) {
            $this->em()->persist($role);
        }
        $this->em()->flush();

        $this->migrate($migration);

        self::assertSame([$read, $old, $new], $this->permissionsOf($holder));
        self::assertSame([$read], $this->permissionsOf($reader));
        self::assertSame([$old, $new], $this->permissionsOf($already), 'granted once');
        self::assertSame([$old], $this->permissionsOf($builtIn), 'the seed defines a built-in role');
    }

    /** @return list<string> */
    private function permissionsOf(Role $role): array
    {
        $json = $this->em()->getConnection()->fetchOne('SELECT permissions FROM role WHERE id = ?', [$role->getId()->toRfc4122()]);
        self::assertIsString($json);
        $permissions = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsArray($permissions);
        self::assertTrue(array_is_list($permissions));

        return array_map(static fn (mixed $permission): string => \is_string($permission) ? $permission : self::fail('a permission is a string'), $permissions);
    }

    /** @param class-string<AbstractMigration> $class */
    private function migrate(string $class): void
    {
        // Doctrine loads migrations from their directory, not the autoloader.
        require_once \dirname(__DIR__, 2).'/migrations/'.substr($class, (int) strrpos($class, '\\') + 1).'.php';
        $connection = $this->em()->getConnection();
        $migration = new $class($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            $connection->executeStatement($query->getStatement());
        }
    }
}
