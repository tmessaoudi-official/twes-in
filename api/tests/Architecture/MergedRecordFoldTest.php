<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A record merged away stays, pointing at the one kept, and every figure reads the one kept. That rule is written once,
 * in `KeptRecords`, so it changes in one place; this test refuses a copy written by hand. A statement that forgets the
 * fold altogether is caught by its own test with a merged record, not here. The entity mappings name the column, and the
 * records engine and the declarations it reads move it as data; nothing else does.
 */
final class MergedRecordFoldTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    /** Paths below src/ that may name the column: the engine that writes it, the large-data generator that clones it, and the fold's home. */
    private const array OWNERS = ['Records/', 'DataFixtures/Scale/', 'Shared/Infrastructure/Doctrine/KeptRecords.php'];

    public function testOnlyTheFoldsHomeWritesItInSql(): void
    {
        $folds = 0;
        $byHand = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $path = substr($file->getPathname(), \strlen(self::SRC) + 1);
            $code = (string) file_get_contents($file->getPathname());
            $folds += substr_count($code, 'KeptRecords::');
            foreach (self::OWNERS as $owner) {
                if (str_starts_with($path, $owner)) {
                    continue 2;
                }
            }
            // A module's declarations to the records engine name the column the engine moves on a merge and its undo.
            if (str_contains($path, '/Infrastructure/Records/')) {
                continue;
            }
            // An entity maps the column by name; that is its storage, not a statement.
            $code = str_replace("JoinColumn(name: 'merged_into_id'", '', $code);
            if (str_contains($code, 'merged_into_id')) {
                $byHand[] = $path;
            }
        }

        self::assertGreaterThanOrEqual(8, $folds, 'the statements reading the record kept were found: a wrong directory finds none and passes');
        self::assertSame([], $byHand, 'these statements fold a merged record by hand: read it through KeptRecords');
    }
}
