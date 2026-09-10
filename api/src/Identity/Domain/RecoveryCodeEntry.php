<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One spendable recovery code in a user's set: the SHA-256 of the code, and nothing that could reproduce it.
 *
 * A row per code rather than a list on the user, for the same reason the invitation token has its own unique
 * index: single use is then enforced by the database and not by whichever code path happens to run. Spending
 * is a delete — a spent code has no story left to tell that `audit_log` does not already carry
 * (`auth.recovery_code_used`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'recovery_code')]
#[ORM\UniqueConstraint(name: 'uniq_recovery_code_hash', columns: ['user_id', 'code_hash'])]
#[ORM\Index(name: 'idx_recovery_code_user', columns: ['user_id'])]
class RecoveryCodeEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $codeHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $codeHash, ?\DateTimeImmutable $now = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->codeHash = $codeHash;
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

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
