<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A company asked to be told when a planned module arrives (« Me prévenir », docs/SPEC.md § 4 module_interest). One
 * row per company and module; withdrawing deletes it. `announced_at` is set the day the module ships and its members
 * are told, so a restart never tells them twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'module_interest')]
#[ORM\Index(name: 'idx_module_interest_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_module_interest_company_key', columns: ['company_id', 'module_key'])]
class ModuleInterest implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(name: 'module_key', length: 40)]
    private string $key;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $announcedAt = null;

    public function __construct(Company $company, string $key, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->key = $key;
        $this->createdAt = $now;
    }

    public function announce(\DateTimeImmutable $now): void
    {
        $this->announcedAt ??= $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAnnouncedAt(): ?\DateTimeImmutable
    {
        return $this->announcedAt;
    }

    public function isAnnounced(): bool
    {
        return null !== $this->announcedAt;
    }
}
