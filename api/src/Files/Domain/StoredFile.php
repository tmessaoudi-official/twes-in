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
 * Bytes a company keeps, such as a document's PDF as it was issued (docs/SPEC.md § 4 file). The record names where the
 * bytes are stored and what proves they are still the same: their size and SHA-256. A stored file is never replaced.
 */
#[ORM\Entity]
#[ORM\Table(name: 'file')]
#[ORM\Index(name: 'idx_file_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_file_storage_key', columns: ['storage_key'])]
class StoredFile implements CompanyOwned
{
    public const int NAME_MAX = 255;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $storageKey;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $originalName;

    #[ORM\Column(length: 127)]
    private string $mime;

    #[ORM\Column]
    private int $size;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $sha256;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $uploadedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Company $company, string $originalName, string $mime, string $contents, ?Uuid $uploadedBy, \DateTimeImmutable $now)
    {
        $name = trim($originalName);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX || str_contains($name, '/')) {
            throw new \InvalidArgumentException(\sprintf('A file is named in 1 to %d characters, without a slash.', self::NAME_MAX));
        }
        if (1 !== preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a media type.', $mime));
        }

        $this->id = Uuid::v7();
        $this->company = $company;
        // Built from ids only: nothing a person typed reaches a storage path.
        $this->storageKey = \sprintf('companies/%s/%s', $company->getId()->toRfc4122(), $this->id->toRfc4122());
        $this->originalName = $name;
        $this->mime = $mime;
        $this->size = \strlen($contents);
        $this->sha256 = hash('sha256', $contents);
        $this->uploadedBy = $uploadedBy;
        $this->createdAt = $now;
    }

    /** Whether these bytes are the ones this record was made for. */
    public function matches(string $contents): bool
    {
        return \strlen($contents) === $this->size && hash_equals($this->sha256, hash('sha256', $contents));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getUploadedBy(): ?Uuid
    {
        return $this->uploadedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
