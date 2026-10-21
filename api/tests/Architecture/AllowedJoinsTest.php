<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A module's tables are its own, as its classes are (`ModuleBoundariesTest`): another module, or the core, which never
 * depends on an optional module, reaches them through a port the caller owns, answered in the module's own
 * `Infrastructure/<Caller>/`. Every context reads the core's tables, as it names the core's classes. One departure is
 * ruled until the release: a module's report or watch may read another module's tables, each such read listed here, so
 * a new one is a decision and one gone is struck off. A table's owner is the context of the entity mapped to it; a statement's tables are what its SQL reads after
 * FROM or JOIN and what it writes after INTO, UPDATE or DELETE FROM, and the tables `KeptRecords` is handed to fold.
 */
final class AllowedJoinsTest extends KernelTestCase
{
    private const string SRC = __DIR__.'/../../src';

    /** Each a report or watch file and the other module's table it reads. */
    private const array REPORTS_AND_WATCHES_READING_ANOTHER_MODULE = [
        'Module/Expenses/Infrastructure/Reports/ExpensesBySupplierReport.php -> vendor',
        'Module/Expenses/Infrastructure/Watch/ExpenseWatch.php -> vendor',
        'Module/Inventory/Infrastructure/Reports/DeadStockReport.php -> product',
        'Module/Inventory/Infrastructure/Reports/StockMovementsReport.php -> product',
        'Module/Inventory/Infrastructure/Watch/ReceiptCostWatch.php -> product',
        'Module/Inventory/Infrastructure/Watch/StockWatch.php -> product',
        'Module/Invoices/Infrastructure/Reports/AgedBalanceReport.php -> customer',
        'Module/Invoices/Infrastructure/Reports/CreditNotesAndDiscountsReport.php -> customer',
        'Module/Invoices/Infrastructure/Reports/IssuedLines.php -> product',
        'Module/Invoices/Infrastructure/Reports/SalesByCustomerReport.php -> customer',
        'Module/Invoices/Infrastructure/Reports/SalesByFamilyReport.php -> product',
        'Module/Invoices/Infrastructure/Reports/SalesByFamilyReport.php -> product_category',
        'Module/Invoices/Infrastructure/Reports/SalesByProductReport.php -> product',
        'Module/Invoices/Infrastructure/Reports/TakingsReport.php -> customer',
        'Module/Invoices/Infrastructure/Reports/WithholdingSufferedReport.php -> customer',
        'Module/Invoices/Infrastructure/Watch/InvoiceWatch.php -> customer',
        'Module/Invoices/Infrastructure/Watch/InvoiceWatch.php -> product',
    ];

    public function testAContextReadsAnothersTablesOnlyWhereRuled(): void
    {
        self::bootKernel();
        $owners = $this->owners();
        self::assertGreaterThan(60, \count($owners), 'the mapped tables were found: none found would pass every statement');

        $listed = [];
        $refused = [];
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $path = substr($file->getPathname(), \strlen(self::SRC) + 1);
            $context = self::contextOf($path);
            if (null === $context) {
                continue;
            }
            ++$files;
            foreach (self::tablesIn((string) file_get_contents($file->getPathname())) as [$table, $writes]) {
                $owner = $owners[$table] ?? null;
                // Every context builds on the core's tables as on its classes; a module's are its own.
                if (null === $owner || $owner === $context || !str_starts_with($owner, 'Module/')) {
                    continue;
                }
                $line = $path.' -> '.$table;
                $ruled = !$writes && str_starts_with($context, 'Module/')
                    && 1 === preg_match('#/Infrastructure/(Reports|Watch)/#', $path);
                if ($ruled) {
                    $listed[$line] = true;
                } else {
                    $refused[$line.($writes ? ' (written)' : '')] = true;
                }
            }
        }

        self::assertGreaterThan(1500, $files, 'the sources were found');
        self::assertSame([], array_keys($refused), 'these statements reach another context\'s table: ask a port the caller owns, answered in the other\'s Infrastructure/<Caller>/');
        $found = array_keys($listed);
        sort($found);
        self::assertSame(self::REPORTS_AND_WATCHES_READING_ANOTHER_MODULE, $found, 'the reports and watches reading another module\'s tables are exactly the ones listed');
    }

    /** @return array<string, string> each mapped table and the context its entity belongs to */
    private function owners(): array
    {
        $owners = [];
        foreach (static::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata() as $metadata) {
            $context = self::contextOf(str_replace('\\', '/', substr($metadata->getName(), \strlen('App\\'))).'.php');
            if (null === $context) {
                continue;
            }
            $owners[trim($metadata->getTableName(), '"`')] = $context;
            foreach ($metadata->getAssociationMappings() as $association) {
                if ($association instanceof ManyToManyOwningSideMapping) {
                    $owners[trim($association->joinTable->name, '"`')] = $context;
                }
            }
        }

        return $owners;
    }

    /** `Module/<Name>` for a module, the folder for a core context, null for what sits outside the contexts. */
    private static function contextOf(string $path): ?string
    {
        $parts = explode('/', $path);
        if ('Module' === $parts[0]) {
            return isset($parts[2]) ? 'Module/'.$parts[1] : null;
        }

        return isset($parts[1]) && !\in_array($parts[0], ['DataFixtures', 'Migrations'], true) ? $parts[0] : null;
    }

    /** @return list<array{0: string, 1: bool}> each table a file's SQL names, and whether it writes it */
    private static function tablesIn(string $code): array
    {
        $tables = [];
        preg_match_all('/\b(FROM|JOIN|INTO|UPDATE)\s+"?([a-z_][a-z0-9_]*)"?/', $code, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $deletes = 'FROM' === $match[1][0] && 1 === preg_match('/DELETE\s+$/', substr($code, max(0, $match[1][1] - 10), min(10, $match[1][1])));
            $tables[] = [$match[2][0], $deletes || \in_array($match[1][0], ['INTO', 'UPDATE'], true)];
        }
        preg_match_all("/KeptRecords::join\\(\\s*'([a-z_]+)'/", $code, $joins);
        preg_match_all("/KeptRecords::among\\(\\s*'[^']*',\\s*'([a-z_]+)'/", $code, $amongs);
        foreach ([...$joins[1], ...$amongs[1]] as $folded) {
            $tables[] = [$folded, false];
        }

        return $tables;
    }
}
