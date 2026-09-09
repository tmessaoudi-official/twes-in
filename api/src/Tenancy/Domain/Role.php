<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A named set of permission strings. The three built-in roles have no company; custom roles (later)
 * belong to one. A permission is granted when the role lists it, or lists the wildcard "*".
 */
#[ORM\Entity]
#[ORM\Table(name: 'role')]
#[ORM\UniqueConstraint(name: 'uniq_role_company_name', columns: ['company_id', 'name'])]
class Role
{
    public const string OWNER = 'owner';
    public const string ADMIN = 'admin';
    public const string MEMBER = 'member';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: true, onDelete: 'CASCADE')]
    private ?Company $company;

    #[ORM\Column(length: 64)]
    private string $name;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $permissions;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<string> $permissions */
    public function __construct(string $name, array $permissions, ?Company $company = null, ?\DateTimeImmutable $now = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->permissions = array_values(array_unique($permissions));
        $this->company = $company;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function isBuiltIn(): bool
    {
        return null === $this->company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function grants(string $permission): bool
    {
        return \in_array(Permission::WILDCARD, $this->permissions, true) || \in_array($permission, $this->permissions, true);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
