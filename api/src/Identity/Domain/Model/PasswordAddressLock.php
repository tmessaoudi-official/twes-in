<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The wrong passwords and six-digit codes guessed at one account from one address, and the lock they set there: five lock
 * the password from that address alone, so whoever guesses cannot keep the account's owner out from another (docs/plans
 * stable-release S2.8). The account's own count (`User::recordFailedLogin`) takes far more, from many addresses, before it
 * locks the password everywhere.
 *
 * Counted by `AddressLockRepository::recordFailure` in one statement, so two guesses at once both count; a row holds
 * nothing but the count, an address an IPv4 one whole or an IPv6 one's network (its first 64 bits, which one machine
 * is given), and when its lock ends.
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_address_lock')]
#[ORM\UniqueConstraint(name: 'uniq_password_address_lock', columns: ['user_id', 'address'])]
class PasswordAddressLock
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 45)]
    private string $address;

    #[ORM\Column]
    private int $failedCount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedUntil;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastFailedAt;

    public function __construct(User $user, string $address, int $failedCount, ?\DateTimeImmutable $lockedUntil, \DateTimeImmutable $lastFailedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->address = $address;
        $this->failedCount = $failedCount;
        $this->lockedUntil = $lockedUntil;
        $this->lastFailedAt = $lastFailedAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function getLastFailedAt(): \DateTimeImmutable
    {
        return $this->lastFailedAt;
    }
}
