<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Files\Application\AttachmentRefused;
use App\Files\Application\Attachments;
use App\Files\Application\StoredFileCorrupted;
use App\Files\Application\StoredFileMissing;
use App\Files\Domain\Attachment;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The mark a company prints on its documents and shows in its switcher: one picture, kept as an attachment of the
 * company so it lives in the same storage as every other file. Only a raster picture is kept, read from its bytes:
 * a vector file can carry a script, and a logo is shown on pages and rendered by a browser engine. Replacing it
 * detaches the old one, whose stored file stays under the retention rules of every file.
 */
final readonly class CompanyLogo
{
    public const string SUBJECT = 'company_logo';
    public const string CHANGED = 'company.logo_changed';
    public const string REMOVED = 'company.logo_removed';
    public const int MAX_BYTES = 2 * 1024 * 1024;
    /** A picture beyond this is not a logo, and a renderer asked to scale one this large is a cost nobody chose. */
    public const int MAX_SIDE = 4000;
    private const array TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private Attachments $attachments,
        private AuditTrail $audit,
        private Transactions $transactions,
    ) {
    }

    /** @throws AttachmentRefused */
    public function set(Company $company, string $originalName, string $contents, ?Uuid $actorUserId): Attachment
    {
        self::check($contents);

        return $this->transactions->run(function () use ($company, $originalName, $contents, $actorUserId): Attachment {
            $this->attachments->detachAll($company, self::SUBJECT, $company->getId());
            $attachment = $this->attachments->attach($company, self::SUBJECT, $company->getId(), $originalName, $contents, $actorUserId);
            $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::CHANGED, $actorUserId, [], $company->getId()));

            return $attachment;
        });
    }

    public function current(Company $company): ?Attachment
    {
        return $this->attachments->of($company, self::SUBJECT, $company->getId())[0] ?? null;
    }

    /**
     * The version of each company's logo, in one read: the id of its stored file, which changes with the logo.
     *
     * @param list<Uuid> $companyIds
     *
     * @return array<string, string> by company id (RFC 4122), only for the companies that have a logo
     */
    public function versionsOf(array $companyIds): array
    {
        return $this->attachments->fileIdsOf(self::SUBJECT, $companyIds);
    }

    /** The picture as bytes, or null when the company has none or its stored file cannot be read. */
    public function contentsOf(Company $company): ?string
    {
        $current = $this->current($company);
        if (null === $current) {
            return null;
        }
        try {
            return $this->attachments->contents($current);
        } catch (StoredFileMissing|StoredFileCorrupted) {
            return null;
        }
    }

    /** The picture as a `data:` URI, which a document renderer with no network reads in place; null when there is none. */
    public function dataUri(Company $company): ?string
    {
        $current = $this->current($company);
        $contents = null === $current ? null : $this->contentsOf($company);

        return null === $current || null === $contents ? null : \sprintf('data:%s;base64,%s', $current->getFile()->getMime(), base64_encode($contents));
    }

    public function remove(Company $company, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $actorUserId): void {
            if ([] === $this->attachments->of($company, self::SUBJECT, $company->getId())) {
                return;
            }
            $this->attachments->detachAll($company, self::SUBJECT, $company->getId());
            $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::REMOVED, $actorUserId, [], $company->getId()));
        });
    }

    /** @throws AttachmentRefused */
    private static function check(string $contents): void
    {
        if ('' === $contents) {
            throw new AttachmentRefused('The file is empty.');
        }
        if (\strlen($contents) > self::MAX_BYTES) {
            throw new AttachmentRefused(\sprintf('A logo is at most %d KB.', intdiv(self::MAX_BYTES, 1024)));
        }
        $type = (string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents);
        $size = \in_array($type, self::TYPES, true) ? @getimagesizefromstring($contents) : false;
        if (false === $size) {
            throw new AttachmentRefused(\sprintf('A logo is a PNG, JPEG or WebP picture; this file is %s.', '' === $type ? 'unknown' : $type));
        }
        if ($size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw new AttachmentRefused(\sprintf('A logo is at most %d pixels on a side.', self::MAX_SIDE));
        }
    }
}
