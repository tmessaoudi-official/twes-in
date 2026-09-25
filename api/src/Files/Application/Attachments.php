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
        private int $maxBytes,
        private array $allowedTypes,
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
            throw new AttachmentRefused(\sprintf('At most %d files are attached to one record.', $this->perSubject));
        }

        $attachment = new Attachment($this->files->store($company, self::name($originalName), $type, $contents, $uploadedBy), $entityType, $entityId, $this->clock->now());
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

    public function detach(Attachment $attachment): void
    {
        $this->attachments->remove($attachment);
    }

    public function detachAll(Company $company, string $entityType, Uuid $entityId): void
    {
        foreach ($this->of($company, $entityType, $entityId) as $attachment) {
            $this->attachments->remove($attachment);
        }
    }

    /** The last segment of what the browser sent, whichever separator its system uses: never a path. */
    private static function name(string $given): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', str_replace('\\', '/', $given));
        $name = trim(substr($name, (int) strrpos('/'.$name, '/')));

        return '' === $name ? 'attachment' : mb_substr($name, 0, StoredFile::NAME_MAX);
    }
}
