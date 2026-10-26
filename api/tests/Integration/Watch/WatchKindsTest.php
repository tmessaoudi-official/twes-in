<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Watch;

use App\Records\Application\RecordCatalogue;
use App\Watch\Application\WatchCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « À surveiller » is read again on the kinds of change its declarations name (`DeclaresWatch::changedBy`), which the
 * web lists as `WATCHED_KINDS` (web/src/app/watch/watch-types.ts): a kind a declaration reads and the web does not hear
 * leaves the count stale until a reload, and a kind the web hears that nothing says is dead. Each is a kind the API
 * says: a recorded subject, a staged live change, or a record subject's table, which deleting and restoring say.
 */
final class WatchKindsTest extends KernelTestCase
{
    public function testTheWebHearsExactlyTheKindsTheDeclarationsReadAndEachIsSaid(): void
    {
        $read = static::getContainer()->get(WatchCatalogue::class)->changedBy();
        $root = \dirname(__DIR__, 4);
        $web = (string) file_get_contents($root.'/web/src/app/watch/watch-types.ts');
        if (1 !== preg_match('/WATCHED_KINDS = \[(.*?)\] as const;/s', $web, $list)) {
            self::fail('WATCHED_KINDS is not found in watch-types.ts');
        }
        preg_match_all("/'([a-z_]+)'/", $list[1], $heard);
        $heard = $heard[1];
        sort($heard);

        self::assertGreaterThanOrEqual(9, \count($read), 'the declarations are read');
        self::assertSame($read, $heard);
        $said = array_keys(static::getContainer()->get(RecordCatalogue::class)->subjects());
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/api/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                preg_match_all("/ENTITY_TYPE = '([a-z_]+)'|new LiveChange\\('([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $kinds);
                $said = [...$said, ...array_filter([...$kinds[1], ...$kinds[2]])];
            }
        }
        foreach ($read as $kind) {
            self::assertContains($kind, $said, "nothing says the kind $kind");
        }
    }
}
