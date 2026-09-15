<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Files\Application;

use App\Files\Application\AttachmentRefused;
use App\Files\Application\Attachments;
use App\Files\Application\Files;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryAttachments;
use App\Tests\Support\InMemoryFileStorage;
use App\Tests\Support\InMemoryStoredFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class AttachmentsTest extends TestCase
{
    public const string PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    public const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private InMemoryFileStorage $storage;
    private InMemoryStoredFiles $files;
    private InMemoryAttachments $records;
    private Attachments $attachments;
    private Company $acme;
    private Uuid $expenseId;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-15 09:00:00');
        $this->storage = new InMemoryFileStorage();
        $this->files = new InMemoryStoredFiles();
        $this->records = new InMemoryAttachments();
        $this->attachments = new Attachments(new Files($this->storage, $this->files, $clock), $this->records, $clock, 1024, ['application/pdf', 'image/png'], 2);
        $this->acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->expenseId = Uuid::v7();
    }

    public function testAFileIsAttachedUnderTheTypeItsBytesHaveNotTheOneItsNameClaims(): void
    {
        $uploader = Uuid::v7();

        $attachment = $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'recu', self::PDF, $uploader);
        $png = $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'photo.pdf', (string) base64_decode(self::PNG, true), $uploader);

        self::assertSame(['recu', 'application/pdf', \strlen(self::PDF), $uploader], [$attachment->getFile()->getOriginalName(), $attachment->getFile()->getMime(), $attachment->getFile()->getSize(), $attachment->getFile()->getUploadedBy()]);
        self::assertSame('image/png', $png->getFile()->getMime());
        self::assertSame([$attachment, $png], $this->attachments->of($this->acme, 'expense', $this->expenseId));
        self::assertSame(self::PDF, $this->attachments->contents($attachment));
    }

    public function testWhatIsNotAnAllowedTypeTooLargeOrEmptyIsRefusedAndNothingIsStored(): void
    {
        foreach ([
            'text named like a PDF' => ['facture.pdf', 'Bonjour, voici la facture'],
            'a script' => ['recu.pdf', "<?php echo 'x';"],
            'empty' => ['vide.pdf', ''],
            'one byte above the limit' => ['gros.pdf', self::PDF.str_repeat(' ', 1025 - \strlen(self::PDF))],
        ] as $case => [$name, $contents]) {
            try {
                $this->attachments->attach($this->acme, 'expense', $this->expenseId, $name, $contents, null);
                self::fail("$case was attached");
            } catch (AttachmentRefused) {
            }
        }
        self::assertSame([[], [], []], [$this->storage->contents, $this->files->files, $this->records->attachments]);
        $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'juste.pdf', self::PDF.str_repeat(' ', 1024 - \strlen(self::PDF)), null);
    }

    public function testANameKeepsOnlyItsLastSegment(): void
    {
        foreach (['C:\\fakepath\\scan.pdf' => 'scan.pdf', '../../etc/passwd.pdf' => 'passwd.pdf', '   ' => 'attachment'] as $given => $kept) {
            $attachment = (new Attachments(new Files(new InMemoryFileStorage(), new InMemoryStoredFiles(), new MockClock()), new InMemoryAttachments(), new MockClock(), 1024, ['application/pdf'], 2))
                ->attach($this->acme, 'expense', $this->expenseId, $given, self::PDF, null);
            self::assertSame($kept, $attachment->getFile()->getOriginalName(), $given);
        }
    }

    public function testAnEntityCarriesALimitedNumberOfAttachments(): void
    {
        $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'a.pdf', self::PDF, null);
        $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'b.pdf', self::PDF, null);

        $this->expectException(AttachmentRefused::class);
        $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'c.pdf', self::PDF, null);
    }

    public function testAnAttachmentIsFoundOnlyForItsCompanyAndEntityAndDetachingKeepsTheBytes(): void
    {
        $attachment = $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'a.pdf', self::PDF, null);
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');

        self::assertSame($attachment, $this->attachments->find($this->acme, 'expense', $this->expenseId, $attachment->getId()));
        self::assertNull($this->attachments->find($globex, 'expense', $this->expenseId, $attachment->getId()));
        self::assertNull($this->attachments->find($this->acme, 'expense', Uuid::v7(), $attachment->getId()));
        self::assertNull($this->attachments->find($this->acme, 'invoice', $this->expenseId, $attachment->getId()));
        self::assertSame([], $this->attachments->of($globex, 'expense', $this->expenseId));

        $this->attachments->detach($attachment);

        self::assertSame([], $this->attachments->of($this->acme, 'expense', $this->expenseId));
        self::assertCount(1, $this->storage->contents, 'a stored file is never removed');
        $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'b.pdf', self::PDF, null);
        $this->attachments->attach($this->acme, 'expense', $this->expenseId, 'c.pdf', self::PDF, null);
        $this->attachments->detachAll($this->acme, 'expense', $this->expenseId);
        self::assertSame([], $this->records->attachments);
    }
}
