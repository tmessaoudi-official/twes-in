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
 * A person who can sign in. Company membership is a Tenancy concern (Membership); platform operators are
 * flagged here because their scope sits outside every company. The security token Symfony keeps is a
 * snapshot of this aggregate (Infrastructure\Security\SecurityUser), never the aggregate itself.
 */
#[ORM\Entity]
#[ORM\Table(name: '"user"')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'email', length: 254)]
    private Email $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash = '';

    #[ORM\Column(length: 120)]
    private string $displayName;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $isPlatformOperator = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column]
    private int $failedLoginCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    /**
     * Ciphertext, never the secret itself: a TOTP secret cannot be a digest, because verifying a code needs
     * the secret back. The `SecretCipher` port does the encrypting; the domain only ever holds the opaque
     * value it was handed (ruling of 2026-09-10).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpSecret = null;

    /** Null while an enrolment is pending: a secret is not a second factor until one real code has proved it. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $totpConfirmedAt = null;

    /** The last timestep spent, so a code cannot be replayed inside its own window. */
    #[ORM\Column(nullable: true)]
    private ?int $totpLastTimestep = null;

    /**
     * Rotated to force every session of this user out (admin-forced logout, password change): the security
     * token carries the stamp it was created with, and a mismatch on refresh means the token is stale.
     */
    #[ORM\Column(length: 32)]
    private string $securityStamp;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Email $email, string $displayName, string $locale = 'fr', ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->displayName = $displayName;
        $this->locale = $locale;
        $this->securityStamp = bin2hex(random_bytes(16));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
        $this->touch();
    }

    public function isPlatformOperator(): bool
    {
        return $this->isPlatformOperator;
    }

    public function setPlatformOperator(bool $operator): void
    {
        $this->isPlatformOperator = $operator;
        $this->touch();
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getFailedLoginCount(): int
    {
        return $this->failedLoginCount;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function isLockedAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->lockedUntil && $this->lockedUntil > $now;
    }

    public function recordFailedLogin(\DateTimeImmutable $now, int $lockAfter, \DateInterval $lockFor): void
    {
        ++$this->failedLoginCount;
        if ($this->failedLoginCount >= $lockAfter) {
            $this->lockedUntil = $now->add($lockFor);
        }
        $this->touch($now);
    }

    public function recordSuccessfulLogin(\DateTimeImmutable $now): void
    {
        $this->failedLoginCount = 0;
        $this->lockedUntil = null;
        $this->lastLoginAt = $now;
        $this->touch($now);
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getPasswordChangedAt(): ?\DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    /** A new password: the hash is dated, and later policies (expiry, reuse) read that date. */
    public function setPasswordHash(string $hash, \DateTimeImmutable $now): void
    {
        $this->passwordHash = $hash;
        $this->passwordChangedAt = $now;
        $this->touch($now);
    }

    /** The same password hashed with newer parameters: not a change the user made. */
    public function upgradePasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
        $this->touch();
    }

    public function getSecurityStamp(): string
    {
        return $this->securityStamp;
    }

    /** Invalidates every existing session of this user on its next request. */
    public function rotateSecurityStamp(): void
    {
        $this->securityStamp = bin2hex(random_bytes(16));
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(?\DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    /** Stores a freshly generated secret as pending. Any factor already on stops counting until this one is confirmed. */
    public function beginTotpEnrolment(string $cipherSecret, ?\DateTimeImmutable $now = null): void
    {
        $this->totpSecret = $cipherSecret;
        $this->totpConfirmedAt = null;
        $this->totpLastTimestep = null;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    /** Turns the pending secret into a real factor. The timestep is the one the confirming code came from, and is spent. */
    public function confirmTotpEnrolment(int $timestep, ?\DateTimeImmutable $now = null): void
    {
        if (null === $this->totpSecret) {
            throw new \DomainException('There is no pending enrolment to confirm.');
        }

        $now ??= new \DateTimeImmutable();
        $this->totpConfirmedAt = $now;
        $this->totpLastTimestep = $timestep;
        $this->updatedAt = $now;
    }

    /**
     * Spends a timestep. Refuses anything not strictly newer than the last one, which is what stops a code
     * being replayed while it is still arithmetically valid.
     */
    public function useTotpTimestep(int $timestep, ?\DateTimeImmutable $now = null): void
    {
        if (!$this->hasTotp()) {
            throw new \DomainException('This account has no confirmed second factor.');
        }

        if (null !== $this->totpLastTimestep && $timestep <= $this->totpLastTimestep) {
            throw new \DomainException('That code has already been used.');
        }

        $this->totpLastTimestep = $timestep;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function disableTotp(?\DateTimeImmutable $now = null): void
    {
        $this->totpSecret = null;
        $this->totpConfirmedAt = null;
        $this->totpLastTimestep = null;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function hasTotp(): bool
    {
        return null !== $this->totpSecret && null !== $this->totpConfirmedAt;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function getTotpConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->totpConfirmedAt;
    }

    public function getTotpLastTimestep(): ?int
    {
        return $this->totpLastTimestep;
    }
}
