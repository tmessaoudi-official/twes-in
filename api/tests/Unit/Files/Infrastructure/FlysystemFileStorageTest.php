<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Files\Infrastructure;

use App\Files\Application\StoredFileMissing;
use App\Files\Infrastructure\Flysystem\FlysystemFileStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem as LocalFiles;

final class FlysystemFileStorageTest extends TestCase
{
    private string $directory;
    private FlysystemFileStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/twes-files-'.bin2hex(random_bytes(6));
        $this->storage = new FlysystemFileStorage(new Filesystem(new LocalFilesystemAdapter($this->directory)));
    }

    protected function tearDown(): void
    {
        new LocalFiles()->remove($this->directory);
    }

    public function testWhatIsWrittenUnderAKeyIsReadBackByteForByte(): void
    {
        $bytes = "%PDF-1.7\n\x00\xff\xfe binary";

        $this->storage->write('companies/c1/f1', $bytes);

        self::assertFileExists($this->directory.'/companies/c1/f1');
        self::assertSame($bytes, $this->storage->read('companies/c1/f1'));
    }

    public function testAMissingKeyIsNamedAndAWrittenKeyIsNeverOverwritten(): void
    {
        try {
            $this->storage->read('companies/c1/absent');
            self::fail('a missing file was read');
        } catch (StoredFileMissing $missing) {
            self::assertStringContainsString('companies/c1/absent', $missing->getMessage());
        }

        $this->storage->write('companies/c1/f1', 'first');
        try {
            $this->storage->write('companies/c1/f1', 'second');
            self::fail('a stored file was overwritten');
        } catch (\LogicException) {
        }
        self::assertSame('first', $this->storage->read('companies/c1/f1'));
    }
}
