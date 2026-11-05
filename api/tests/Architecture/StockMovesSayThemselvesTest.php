<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every class writing stock movements stages a `stock` live change in the same unit: no audit entry stands for a
 * movement, so a move that stages nothing is heard by no stock screen, which keeps showing the stock before it. A sale's
 * move runs after the document's own change was said, so the document's signal is no stand-in for it either.
 */
final class StockMovesSayThemselvesTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../src';

    public function testEveryWriterOfStockMovementsStagesAStockChange(): void
    {
        $writers = [];
        $silent = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCE, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match_all('/StockMovementRepository\s+\$(\w+)/', $code, $properties);
            if (!array_any($properties[1], static fn (string $property): bool => 1 === preg_match('/\$this->'.$property.'->save(?:Valued)?\(/', $code))) {
                continue;
            }
            $writers[] = $file->getFilename();
            if (1 !== preg_match("/new LiveChange\\(\\s*'stock'/", $code)) {
                $silent[] = $file->getFilename();
            }
        }
        sort($writers);

        // Today's writers: the stock's own actions, a merge carrying stock, a sale's or a return's move, an import undone.
        self::assertGreaterThanOrEqual(4, \count($writers), 'every writer was found: '.implode(', ', $writers));
        self::assertSame([], $silent, 'a stock movement written with no `stock` change leaves every stock screen on the stock before it');
    }
}
