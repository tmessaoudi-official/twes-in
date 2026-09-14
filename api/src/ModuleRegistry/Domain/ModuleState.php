<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Domain;

use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Whether a company has a module switched on (docs/SPEC.md § 4 module_state). A row exists only once the company
 * has switched the module; without one the module is on. Switched off, `enabled_at` is null; switched on, it holds
 * when.
 */
#[ORM\Entity]
#[ORM\Table(name: 'module_state')]
#[ORM\Index(name: 'idx_module_state_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_module_state_company_key', columns: ['company_id', 'module_key'])]
class ModuleState
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(name: 'module_key', length: 40)]
    private string $key;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $enabledAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, string $key, ?\DateTimeImmutable $enabledAt, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->key = $key;
        $this->enabledAt = $enabledAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function of(Company $company, string $key, bool $enabled, \DateTimeImmutable $now): self
    {
        return new self($company, $key, $enabled ? $now : null, $now);
    }

    /** @return bool whether anything changed: switching to the current state keeps the date it was switched on */
    public function switchTo(bool $enabled, \DateTimeImmutable $now): bool
    {
        if ($enabled === $this->isEnabled()) {
            return false;
        }
        $this->enabledAt = $enabled ? $now : null;
        $this->updatedAt = $now;

        return true;
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

    public function isEnabled(): bool
    {
        return null !== $this->enabledAt;
    }

    public function getEnabledAt(): ?\DateTimeImmutable
    {
        return $this->enabledAt;
    }
}
