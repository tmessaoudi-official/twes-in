<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * What an issued invoice is and what an open one is are written once, in `InvoiceRules`, which every statement reading
 * them concatenates. Three copies of « open » once disagreed: the home counted an invoice issued with nothing to pay
 * as late while the aged balance and the late invoices' watch did not. This test refuses a copy written by hand.
 */
final class InvoiceRulesTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    private const string HOME = 'Module/Invoices/Infrastructure/Doctrine/InvoiceRules.php';

    public function testOnlyTheRulesHomeWritesThemInSql(): void
    {
        $uses = 0;
        $byHand = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $path = substr($file->getPathname(), \strlen(self::SRC) + 1);
            $code = (string) file_get_contents($file->getPathname());
            $uses += substr_count($code, 'InvoiceRules::');
            if (self::HOME === $path || str_starts_with($path, 'DataFixtures/Scale/')) {
                continue;
            }
            if (1 === preg_match('/amount_?due\s+(IS\s+NOT\s+NULL|>\s*0)|Issued->value,\s*InvoiceStatus::PartiallyPaid->value\s*\]/i', $code)) {
                $byHand[] = $path;
            }
        }

        self::assertGreaterThanOrEqual(20, $uses, 'the statements reading the rules were found: a wrong directory finds none and passes');
        self::assertSame([], $byHand, 'these statements write what issued or open means by hand: read it through InvoiceRules');
    }
}
