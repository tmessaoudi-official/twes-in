<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryUsers implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    public function ofId(Uuid $id): ?User
    {
        return $this->users[$id->toRfc4122()] ?? null;
    }

    public function platformOperators(): array
    {
        $operators = array_values(array_filter($this->users, static fn (User $user): bool => $user->isPlatformOperator()));
        usort($operators, static fn (User $a, User $b): int => strcmp($a->getEmail()->value, $b->getEmail()->value));

        return $operators;
    }

    public function ofEmail(Email $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getEmail()->equals($email)) {
                return $user;
            }
        }

        return null;
    }

    public function search(string $text, int $limit): array
    {
        $needle = mb_strtolower(trim($text));
        $found = array_values(array_filter(
            $this->users,
            static fn (User $u) => str_contains($u->getEmail()->value, $needle) || str_contains(mb_strtolower($u->getDisplayName()), $needle),
        ));
        usort($found, static fn (User $a, User $b) => strcmp($a->getEmail()->value, $b->getEmail()->value));

        return \array_slice($found, 0, $limit);
    }

    public function save(User $user): void
    {
        $this->users[$user->getId()->toRfc4122()] = $user;
    }
}
