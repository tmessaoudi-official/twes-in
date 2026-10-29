<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Identity\Domain\Model\User;

/** An account as the platform's operators see it: who it is and whether it may sign in. */
final readonly class AccountView
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public bool $active,
        public bool $platformOperator,
        public string $createdAt,
        /** Until when wrong guesses keep its password locked, null when they do not now. */
        public ?string $passwordLockedUntil = null,
    ) {
    }

    public static function of(User $user, \DateTimeImmutable $now): self
    {
        return new self(
            $user->getId()->toRfc4122(),
            $user->getEmail()->value,
            $user->getDisplayName(),
            $user->isActive(),
            $user->isPlatformOperator(),
            $user->getCreatedAt()->format(\DATE_ATOM),
            $user->isLockedAt($now) ? $user->getLockedUntil()?->format(\DATE_ATOM) : null,
        );
    }
}
