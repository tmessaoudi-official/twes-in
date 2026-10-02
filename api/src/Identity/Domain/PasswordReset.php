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
 * A mailed link that lets whoever reads that inbox choose a new password for an account. Single use and short lived: an
 * unknown link, an expired one and one already used are the same non-answer to whoever presents it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_reset')]
#[ORM\UniqueConstraint(name: 'uniq_password_reset_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_password_reset_user', columns: ['user_id'])]
class PasswordReset
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** SHA-256 of the raw token, hex; the raw value is in the mail and nowhere else. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(User $user, PasswordResetToken $token, \DateTimeImmutable $now, \DateInterval $validFor)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = $token->hash();
        $this->createdAt = $now;
        $this->expiresAt = $now->add($validFor);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    /** Usable up to and including the instant it expires, once. */
    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $now <= $this->expiresAt;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->usedAt = $now;
    }
}
