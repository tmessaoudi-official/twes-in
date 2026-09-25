<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use App\Identity\Domain\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Attaches a user to a company with one role. One row per (user, company). */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_company', columns: ['user_id', 'company_id'])]
// One pinned company per person, whatever writes it (docs/SPEC.md § 7, 2026-09-25 09:03).
#[ORM\UniqueConstraint(name: 'uniq_membership_opened_at_sign_in', columns: ['user_id'], options: ['where' => 'opened_at_sign_in'])]
class Membership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', nullable: false)]
    private Role $role;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** When the person last switched to this company: a sign-in reopens the latest. To the second. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** « Société à l'ouverture »: the company a sign-in always opens; at most one of a person's memberships. */
    #[ORM\Column(options: ['default' => false])]
    private bool $openedAtSignIn = false;

    public function __construct(User $user, Company $company, Role $role, ?\DateTimeImmutable $now = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->company = $company;
        $this->role = $role;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function isOpenedAtSignIn(): bool
    {
        return $this->openedAtSignIn;
    }

    /** The person opened this company now. */
    public function use(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    /** Pins it, or unpins it; keeping one pin per person is the use case's rule, across memberships. */
    public function openAtSignIn(bool $opened): void
    {
        $this->openedAtSignIn = $opened;
    }
}
