<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

use App\Files\Domain\Attachment;
use App\Files\Domain\AttachmentRepository;
use App\Files\Domain\StoredFile;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Files attached to what a company keeps. A file's type is read from its bytes, never taken from its name or from what
 * the browser declared, and only the configured types are kept: a document or a picture of one. Size and count are
 * limited per subject. Whether the subject exists and may take a file is its own context's to say.
 */
final readonly class Attachments
{
    /** @param list<string> $allowedTypes */
    public function __construct(
        private Files $files,
        private AttachmentRepository $attachments,
        private ClockInterface $clock,
        #[Autowire(param: 'app.files.upload_max_bytes')]
        private int $maxBytes,
        #[Autowire(param: 'app.files.attachment_types')]
        private array $allowedTypes,
        #[Autowire(param: 'app.files.attachments_per_subject')]
        private int $perSubject,
    ) {
    }

    /** @throws AttachmentRefused */
    public function attach(Company $company, string $entityType, Uuid $entityId, string $originalName, string $contents, ?Uuid $uploadedBy): Attachment
    {
        if ('' === $contents) {
            throw new AttachmentRefused('The file is empty.');
        }
        if (\strlen($contents) > $this->maxBytes) {
            throw new AttachmentRefused(\sprintf('A file is at most %d KB.', intdiv($this->maxBytes, 1024)));
        }
        $type = (string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents);
        if (!\in_array($type, $this->allowedTypes, true)) {
            throw new AttachmentRefused(\sprintf('A file of type %s is not attached; accepted: %s.', $type, implode(', ', $this->allowedTypes)));
        }
        if (\count($this->of($company, $entityType, $entityId)) >= $this->perSubject) {
            throw $this->tooMany();
        }

        $attachment = new Attachment($this->files->store($company, StoredFile::nameFrom($originalName, 'attachment'), $type, $contents, $uploadedBy), $entityType, $entityId, $this->clock->now());
        $this->attachments->save($attachment);

        return $attachment;
    }

    /** @return list<Attachment> */
    public function of(Company $company, string $entityType, Uuid $entityId): array
    {
        return $this->attachments->ofEntity($company->getId(), $entityType, $entityId);
    }

    /**
     * @param list<Uuid> $entityIds
     *
     * @return array<string, int> each subject's attachment count, by id (RFC 4122)
     */
    public function countsOf(Company $company, string $entityType, array $entityIds): array
    {
        return $this->attachments->countsOfEntities($company->getId(), $entityType, $entityIds);
    }

    /**
     * The stored file of each subject's oldest attachment, across companies, in one read.
     *
     * @param list<Uuid> $entityIds
     *
     * @return array<string, string> the file id by subject id, both RFC 4122
     */
    public function fileIdsOf(string $entityType, array $entityIds): array
    {
        return $this->attachments->fileIdsOfEntities($entityType, $entityIds);
    }

    public function find(Company $company, string $entityType, Uuid $entityId, Uuid $id): ?Attachment
    {
        foreach ($this->of($company, $entityType, $entityId) as $attachment) {
            if ($attachment->getId()->equals($id)) {
                return $attachment;
            }
        }

        return null;
    }

    /**
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function contents(Attachment $attachment): string
    {
        return $this->files->contents($attachment->getFile());
    }

    /** Takes a file off: it leaves every list and count, and is kept so that it can be put back. */
    public function detach(Attachment $attachment): void
    {
        $attachment->remove($this->clock->now());
        $this->attachments->save($attachment);
    }

    /**
     * Puts a file taken off back on its subject, where it was among the others. Null when the subject never held it;
     * a file still attached is left as it is.
     *
     * @throws AttachmentRefused when the subject holds as many files as it may
     */
    public function restore(Company $company, string $entityType, Uuid $entityId, Uuid $id): ?Attachment
    {
        foreach ($this->attachments->ofEntityWithRemoved($company->getId(), $entityType, $entityId) as $attachment) {
            if (!$attachment->getId()->equals($id)) {
                continue;
            }
            if ($attachment->isRemoved()) {
                if (\count($this->of($company, $entityType, $entityId)) >= $this->perSubject) {
                    throw $this->tooMany();
                }
                $attachment->restore();
                $this->attachments->save($attachment);
            }

            return $attachment;
        }

        return null;
    }

    /** Deletes a subject's attachments for good, those taken off included: the subject itself is going. */
    public function detachAll(Company $company, string $entityType, Uuid $entityId): void
    {
        foreach ($this->attachments->ofEntityWithRemoved($company->getId(), $entityType, $entityId) as $attachment) {
            $this->attachments->remove($attachment);
        }
    }

    private function tooMany(): AttachmentRefused
    {
        return new AttachmentRefused(\sprintf('At most %d files are attached to one record.', $this->perSubject), AttachmentRefused::TOO_MANY_FILES, ['max' => $this->perSubject]);
    }
}
