<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A file a company imported: what it was, by its content's SHA-256, who sent it and what it did. Kept for committed
 * imports only, since a preview and a refused file leave nothing behind. The next preview of the very same file is
 * told it was already imported, and what the import wrote elsewhere (a stock movement) can carry its id, so it reads
 * as the import's.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_run')]
#[ORM\Index(name: 'idx_import_run_content', columns: ['company_id', 'subject', 'content_hash'])]
class ImportRun implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 32)]
    private string $subject;

    /** The SHA-256 of the file's bytes, in lowercase hexadecimal. */
    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(length: 16)]
    private string $mode;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $importedBy;

    #[ORM\Column]
    private int $created;

    #[ORM\Column]
    private int $updated;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $at;

    public function __construct(Uuid $id, Company $company, string $subject, string $contentHash, string $mode, ?Uuid $importedBy, int $created, int $updated, \DateTimeImmutable $at)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $contentHash)) {
            throw new \InvalidArgumentException('A content hash is a SHA-256 in lowercase hexadecimal.');
        }
        $this->id = $id;
        $this->company = $company;
        $this->subject = $subject;
        $this->contentHash = $contentHash;
        $this->mode = $mode;
        $this->importedBy = $importedBy;
        $this->created = $created;
        $this->updated = $updated;
        $this->at = $at;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getImportedBy(): ?Uuid
    {
        return $this->importedBy;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
