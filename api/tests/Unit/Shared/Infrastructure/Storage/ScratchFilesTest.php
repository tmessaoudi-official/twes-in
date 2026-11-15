<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageFull;
use App\Shared\Infrastructure\Storage\ScratchFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * A file a request prepares and hands over (a download, an import's working copy) that could not be written says why:
 * the disk out of room in words, anything else a moment to wait, never the person's own file or a silent half.
 */
final class ScratchFilesTest extends TestCase
{
    public function testAPathIsANewEmptyFileInTheFolderAsked(): void
    {
        $folder = sys_get_temp_dir().'/twes-scratch-'.bin2hex(random_bytes(6));
        mkdir($folder);
        try {
            $path = (new ScratchFiles(0))->path('twes-test-', $folder);

            self::assertSame($folder, \dirname($path));
            self::assertSame(0, filesize($path));
            unlink($path);
        } finally {
            rmdir($folder);
        }
    }

    public function testAFileThatCouldNotBeWrittenForWantOfRoomIsSaidAsStorageFull(): void
    {
        $why = new \RuntimeException('The writer stopped.');

        $answer = (new ScratchFiles(\PHP_INT_MAX))->unprepared($why);

        self::assertInstanceOf(StorageFull::class, $answer);
        self::assertSame($why, $answer->getPrevious());
    }

    public function testAFileThatCouldNotBeWrittenWithRoomLeftIsAMomentToWait(): void
    {
        $why = new \RuntimeException('The writer stopped.');

        $answer = (new ScratchFiles(0))->unprepared($why);

        self::assertInstanceOf(ServiceUnavailableHttpException::class, $answer);
        self::assertSame(503, $answer->getStatusCode());
        self::assertSame(30, $answer->getHeaders()['Retry-After'] ?? null);
        self::assertSame($why, $answer->getPrevious());
    }
}
