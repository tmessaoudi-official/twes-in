<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A merge hands a customer's own settings to the customer kept, and leaves its issued documents naming the customer
 * merged away: a setting read for a document's customer reads the one it stands for (`standingFor()`), or it finds
 * nothing at the customer's level and silently takes the group's or the company's, another language or other terms.
 */
final class CustomerSettingsReadTheOneKeptTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../src';

    public function testEverySettingReadForACustomerReadsTheCustomerKept(): void
    {
        $kept = 0;
        $named = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCE, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match_all('/new SettingContext\(([^;]*)/', $code, $calls);
            foreach ($calls[1] as $call) {
                // A customer handed as an id the caller holds is the one it names; one read off an entity is the read.
                preg_match_all('/\bcustomer(?:Group)?Id:\s*([^,\s]+)/', $call, $reads);
                foreach ($reads[1] as $read) {
                    if (!str_contains($read, '->')) {
                        continue;
                    }
                    if (str_contains($read, '->standingFor()')) {
                        ++$kept;
                    } else {
                        $named[] = $file->getFilename().': '.$read;
                    }
                }
            }
        }

        sort($named);
        self::assertSame([], $named, 'a setting read for a customer read off a document: read it for $customer->standingFor()');
        // Today's reads, a customer's group and the customer each: the invoices' issue, mentions, print, receiver rule,
        // deposit and late-fee language, statement and credit limit; the delivery notes' and the quotes' print and settings.
        self::assertGreaterThanOrEqual(24, $kept, 'every setting read for a customer was found');
    }
}
