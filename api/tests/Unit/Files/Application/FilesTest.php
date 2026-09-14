<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Files\Application;

use App\Files\Application\Files;
use App\Files\Application\StoredFileCorrupted;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryFileStorage;
use App\Tests\Support\InMemoryStoredFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class FilesTest extends TestCase
{
    private InMemoryFileStorage $storage;
    private InMemoryStoredFiles $records;
    private MockClock $clock;
    private Files $files;
    private Company $company;

    protected function setUp(): void
    {
        $this->storage = new InMemoryFileStorage();
        $this->records = new InMemoryStoredFiles();
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $this->files = new Files($this->storage, $this->records, $this->clock);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testAFileIsWrittenUnderItsCompanyAndRecordedWithWhatProvesItsBytes(): void
    {
        $uploader = Uuid::v7();

        $file = $this->files->store($this->company, 'BL-2026-00001.pdf', 'application/pdf', '%PDF-1.7 bytes', $uploader);

        self::assertSame('companies/'.$this->company->getId()->toRfc4122().'/'.$file->getId()->toRfc4122(), $file->getStorageKey());
        self::assertSame(
            [$this->company, 'BL-2026-00001.pdf', 'application/pdf', 14, hash('sha256', '%PDF-1.7 bytes'), $uploader],
            [$file->getCompany(), $file->getOriginalName(), $file->getMime(), $file->getSize(), $file->getSha256(), $file->getUploadedBy()],
        );
        self::assertEquals($this->clock->now(), $file->getCreatedAt());
        self::assertSame([$file->getStorageKey() => '%PDF-1.7 bytes'], $this->storage->contents);
        self::assertSame([$file], $this->records->files);
        self::assertSame('%PDF-1.7 bytes', $this->files->contents($file));
    }

    public function testBytesThatNoLongerMatchTheirRecordAreNeverServed(): void
    {
        $file = $this->files->store($this->company, 'BL-2026-00001.pdf', 'application/pdf', '%PDF-1.7 bytes', null);
        $this->storage->contents[$file->getStorageKey()] = '%PDF-1.7 other';

        $this->expectException(StoredFileCorrupted::class);
        $this->files->contents($file);
    }
}
