<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A stored file attached to something a company keeps, such as the receipt of an expense (docs/SPEC.md § 4 attachment).
 * The record names its subject by type and id, so any context attaches files without the files knowing it. Detaching
 * removes the attachment, never the stored file.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attachment')]
#[ORM\Index(name: 'idx_attachment_subject', columns: ['company_id', 'entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_attachment_file', columns: ['file_id'])]
class Attachment implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 64)]
    private string $entityType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $entityId;

    #[ORM\ManyToOne(targetEntity: StoredFile::class)]
    #[ORM\JoinColumn(name: 'file_id', nullable: false)]
    private StoredFile $file;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(StoredFile $file, string $entityType, Uuid $entityId, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $file->getCompany();
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->file = $file;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): Uuid
    {
        return $this->entityId;
    }

    public function getFile(): StoredFile
    {
        return $this->file;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
