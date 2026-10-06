<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Role;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261006100000;
use Psr\Log\NullLogger;

/**
 * Drafting and issuing a credit note took `invoice.write` until `invoice.credit` existed (docs/SPEC.md § 7, audit
 * 2026-10-06 B-12): a role a company made for itself before then, and allowed to write invoices, keeps doing what it did.
 * A built-in role is the seed's to define, and a role that never wrote invoices gains nothing.
 */
final class CreditPermissionGrantTest extends ApiTestCase
{
    public function testACompanysRoleThatWroteInvoicesKeepsItsCreditNotesAndNoOtherRoleGainsThem(): void
    {
        $company = $this->createCompany('Acme');
        $writer = new Role('comptoir', ['invoice.read', 'invoice.write'], $company);
        $reader = new Role('lecteur', ['invoice.read'], $company);
        $already = new Role('gérant', ['invoice.write', 'invoice.credit'], $company);
        $builtIn = new Role(Role::CLERK, ['invoice.write']);
        foreach ([$writer, $reader, $already, $builtIn] as $role) {
            $this->em()->persist($role);
        }
        $this->em()->flush();

        $this->migrate();

        self::assertSame(['invoice.read', 'invoice.write', 'invoice.credit'], $this->permissionsOf($writer));
        self::assertSame(['invoice.read'], $this->permissionsOf($reader));
        self::assertSame(['invoice.write', 'invoice.credit'], $this->permissionsOf($already), 'granted once');
        self::assertSame(['invoice.write'], $this->permissionsOf($builtIn), 'the seed defines a built-in role');
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

    private function migrate(): void
    {
        // Doctrine loads migrations from their directory, not the autoloader.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261006100000.php';
        $connection = $this->em()->getConnection();
        $migration = new Version20261006100000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            $connection->executeStatement($query->getStatement());
        }
    }
}
