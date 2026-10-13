<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Files\Application\FileStorage;
use App\Files\Application\StoredFileMissing;
use App\Tenancy\Application\Company\RemoveClosedCompanies;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Once the years a closed company's records are kept have passed, the nightly run removes it whole: every row that is
 * its own, by the database's cascades in one statement, and every file it stored. The demo dataset is what is removed,
 * for it runs every workflow there is: a table one of them reaches and the cascade misses fails here. The other demo
 * company is the witness that nothing else goes, and the journal keeps the removal itself.
 */
final class ClosedCompanyRemovalTest extends ApiTestCase
{
    private const string GONE = 'Atelier Mercier';
    private const string KEPT = 'Carthage Conseil';

    public function testACompanyPastItsRetentionGoesWholeAndNothingElseWithIt(): void
    {
        $this->createUser('operator@twes.local', 'operator-secret', operator: true);
        $tester = $this->load();
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $gone = $this->idOf(self::GONE);
        $kept = $this->idOf(self::KEPT);
        $keys = $this->connection()->fetchFirstColumn('SELECT storage_key FROM file WHERE company_id = ?', [$gone]);
        self::assertNotEmpty($keys, 'the demo stores files');
        $tables = $this->tablesWithACompany();
        $before = $this->rowsOf($kept, $tables);
        $this->closed($gone, '-1 day');
        $this->closed($kept, '+1 day');

        self::assertSame(1, static::getContainer()->get(RemoveClosedCompanies::class)->handle());

        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM company WHERE id = ?', [$gone]));
        $left = array_filter($this->rowsOf($gone, $tables), static fn (int $count): bool => 0 !== $count);
        self::assertSame([], $left, 'every table is emptied of the company');
        self::assertSame($before, $this->rowsOf($kept, $tables), 'the company whose retention runs on keeps every row');
        $storage = static::getContainer()->get(FileStorage::class);
        foreach ($keys as $key) {
            self::assertIsString($key);
            try {
                $storage->read($key);
                self::fail("$key is still stored");
            } catch (StoredFileMissing) {
            }
        }
        self::assertSame(1, $this->connection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'company.removed' AND entity_id = ?", [$gone]));
        self::assertSame(0, static::getContainer()->get(RemoveClosedCompanies::class)->handle(), 'a second run finds nothing more');
    }

    /** @return list<string> every table holding a company's own rows, read from the schema so a new one is counted */
    private function tablesWithACompany(): array
    {
        $tables = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'company_id' AND table_name <> 'audit_log' ORDER BY table_name") as $table) {
            self::assertIsString($table);
            $tables[] = $table;
        }
        self::assertGreaterThan(50, \count($tables), 'the schema was read');

        return $tables;
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, int>
     */
    private function rowsOf(string $companyId, array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM \"$table\" WHERE company_id = ?", [$companyId]);
            self::assertIsInt($count);
            $rows[$table] = $count;
        }

        return $rows;
    }

    private function closed(string $companyId, string $retainedUntil): void
    {
        $this->connection()->executeStatement("UPDATE company SET status = 'closed', closed_down_at = ?, retain_until = ? WHERE id = ?", [
            (new \DateTimeImmutable('-11 years'))->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable($retainedUntil))->format('Y-m-d'),
            $companyId,
        ]);
    }

    private function idOf(string $name): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM company WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }

    private function load(): ApplicationTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'doctrine:fixtures:load', '--append' => true], ['interactive' => false]);

        return $tester;
    }
}
