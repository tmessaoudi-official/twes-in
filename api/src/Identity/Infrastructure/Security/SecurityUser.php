<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What Symfony Security holds: a snapshot of the User aggregate, rebuilt from the database on every request
 * by the user provider. The context listener compares the copy in the session with the fresh one through
 * isEqualTo(): a rotated stamp, a deactivated account or a scope change makes them unequal, and the session
 * is logged out. The password hash never reaches the session store (__serialize replaces it by a checksum,
 * as the Symfony documentation recommends).
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const string ROLE_USER = 'ROLE_USER';
    public const string ROLE_PLATFORM_OPERATOR = 'ROLE_PLATFORM_OPERATOR';

    /** @param non-empty-string $email */
    private function __construct(
        private readonly Uuid $id,
        private readonly string $email,
        private string $passwordHash,
        private readonly string $securityStamp,
        private readonly bool $active,
        private readonly bool $platformOperator,
        private readonly ?\DateTimeImmutable $lockedUntil,
    ) {
    }

    public static function of(User $user): self
    {
        return new self($user->getId(), $user->getEmail()->value, $user->getPasswordHash(), $user->getSecurityStamp(), $user->isActive(), $user->isPlatformOperator(), $user->getLockedUntil());
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return non-empty-list<string> */
    public function getRoles(): array
    {
        return $this->platformOperator ? [self::ROLE_USER, self::ROLE_PLATFORM_OPERATOR] : [self::ROLE_USER];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isPlatformOperator(): bool
    {
        return $this->platformOperator;
    }

    public function isLockedAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->lockedUntil && $this->lockedUntil > $now;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id->equals($this->id)
            && $user->securityStamp === $this->securityStamp
            && $user->active === $this->active
            && $user->platformOperator === $this->platformOperator;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0passwordHash"] = hash('crc32c', $this->passwordHash);

        return $data;
    }
}
