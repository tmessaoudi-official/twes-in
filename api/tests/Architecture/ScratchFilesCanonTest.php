<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Two ways a full disk reaches a request outside the stored files, each through one canon so it is said in words: a
 * file the request prepares itself (a download, an import's working copy) is opened by `ScratchFiles`, which says a
 * write that failed for want of room as the storage full; a file the person sent is read through `ReceivedFile`, which
 * says one PHP had no room to keep the same way, rather than « the file did not arrive » or « not a picture ».
 */
final class ScratchFilesCanonTest extends TestCase
{
    private const string CANON = 'Shared/Infrastructure/Storage/ScratchFiles.php';
    private const string RECEIVED = 'Shared/Infrastructure/Http/ReceivedFile.php';

    public function testAFileARequestPreparesIsOpenedThroughTheCanon(): void
    {
        $opening = [];
        foreach (self::sources() as $relative => $source) {
            if (self::CANON !== $relative && 1 === preg_match('/\b(?:tempnam|tmpfile)\s*\(/', $source)) {
                $opening[] = $relative;
            }
        }

        self::assertSame([], $opening, 'a temporary file opened outside ScratchFiles fails silently, or as the person\'s mistake, on a full disk');
        self::assertMatchesRegularExpression('/\btempnam\s*\(/', self::sources()[self::CANON], 'the canon is what opens them');
    }

    public function testAFileThePersonSentIsReadThroughTheCanon(): void
    {
        $reading = 0;
        foreach (self::sources() as $relative => $source) {
            if (self::RECEIVED === $relative || 1 !== preg_match('/->files->get\(/', $source)) {
                continue;
            }
            ++$reading;
            self::assertMatchesRegularExpression('/ReceivedFile::(?:of|refuseUnwritten)\(/', $source, $relative.' reads a sent file and says a disk that had no room to keep it');
        }
        self::assertGreaterThanOrEqual(1, $reading, 'the phone\'s photo, read on its own, is found');
    }

    /** @return array<string, string> each file of api/src by its path under it */
    private static function sources(): array
    {
        $root = \dirname(__DIR__, 2).'/src/';
        $sources = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.php')) {
                $sources[substr($file->getPathname(), \strlen($root))] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }
}
