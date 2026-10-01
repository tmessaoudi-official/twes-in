<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A list that is not fetch-joined counts its rows with COUNT(id). Doctrine's default output walker counts a
 * SELECT DISTINCT of every column of the row instead, which took 8.4 s for a million invoices (docs/SPEC.md § 7).
 * Every `new Paginator(` in the code sets `setUseOutputWalkers(false)`; one that needs the walker must say why here.
 */
final class PaginatorCountTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    public function testEveryPaginatorCountsIdsWithoutTheOutputWalker(): void
    {
        $found = 0;
        $walking = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $built = substr_count($code, 'new Paginator(');
            $found += $built;
            if (substr_count($code, '->setUseOutputWalkers(false)') < $built) {
                $walking[] = $file->getPathname();
            }
        }

        self::assertGreaterThanOrEqual(7, $found, 'the seven paged lists were found: a wrong directory finds none and passes');
        self::assertSame([], $walking, 'a Paginator without setUseOutputWalkers(false) counts a SELECT DISTINCT of every column');
    }
}
