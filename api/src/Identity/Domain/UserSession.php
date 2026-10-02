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
 * One browser signed in to an account, as the person sees it on their security page: where from, on what, and when
 * last. Only the SHA-256 of the session id is kept, so a copy of this table cannot be replayed as a cookie. Ending a
 * session marks it revoked and the next request carrying it is refused; the row stays until it is older than the
 * absolute session limit, so a revoked cookie cannot come back to life by its row being gone.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_session')]
#[ORM\UniqueConstraint(name: 'uniq_user_session_hash', columns: ['session_hash'])]
#[ORM\Index(name: 'idx_user_session_user', columns: ['user_id'])]
class UserSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $sessionHash;

    #[ORM\Column(length: 255)]
    private string $device;

    #[ORM\Column(length: 45)]
    private string $address;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $sessionId, string $device, string $address, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->sessionHash = self::hashOf($sessionId);
        $this->device = mb_substr($device, 0, 255);
        $this->address = mb_substr($address, 0, 45);
        $this->createdAt = $now;
        $this->lastSeenAt = $now;
    }

    public static function hashOf(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSessionHash(): string
    {
        return $this->sessionHash;
    }

    public function getDevice(): string
    {
        return $this->device;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isFor(string $sessionId): bool
    {
        return hash_equals($this->sessionHash, self::hashOf($sessionId));
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }
}
