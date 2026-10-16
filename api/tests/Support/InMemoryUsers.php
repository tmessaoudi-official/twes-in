<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\ValueObject\AccountSearch;
use App\Identity\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use Symfony\Component\Uid\Uuid;

final class InMemoryUsers implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    /** How many times a user was read by id. */
    public int $reads = 0;

    public function ofId(Uuid $id): ?User
    {
        ++$this->reads;

        return $this->users[$id->toRfc4122()] ?? null;
    }

    public function lockedOfId(Uuid $id): ?User
    {
        return $this->ofId($id);
    }

    public function platformOperators(): array
    {
        $operators = array_values(array_filter($this->users, static fn (User $user): bool => $user->isPlatformOperator()));
        usort($operators, static fn (User $a, User $b): int => strcmp($a->getEmail()->value, $b->getEmail()->value));

        return $operators;
    }

    public function withTotpSecret(): array
    {
        return array_values(array_filter($this->users, static fn (User $user): bool => null !== $user->getTotpSecret()));
    }

    public function deletionsRequestedBefore(\DateTimeImmutable $moment): array
    {
        $due = array_values(array_filter($this->users, static fn (User $user): bool => null !== $user->getDeletionRequestedAt() && $user->getDeletionRequestedAt() < $moment));
        usort($due, static fn (User $a, User $b): int => $a->getDeletionRequestedAt() <=> $b->getDeletionRequestedAt());

        return $due;
    }

    public function remove(User $user): void
    {
        unset($this->users[$user->getId()->toRfc4122()]);
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

    /** Narrows on address and name, active and operator; in address order. */
    public function search(AccountSearch $search, PageRequest $page): Page
    {
        $needle = mb_strtolower(trim($search->text ?? ''));
        $found = array_values(array_filter(
            $this->users,
            static fn (User $u): bool => ('' === $needle || str_contains($u->getEmail()->value, $needle) || str_contains(mb_strtolower($u->getDisplayName()), $needle))
                && (null === $search->active || $u->isActive() === $search->active)
                && (null === $search->platformOperator || $u->isPlatformOperator() === $search->platformOperator),
        ));
        usort($found, static fn (User $a, User $b) => strcmp($a->getEmail()->value, $b->getEmail()->value));

        return new Page(\array_slice($found, $page->offset(), $page->size), \count($found), $page);
    }

    public function save(User $user): void
    {
        $this->users[$user->getId()->toRfc4122()] = $user;
    }
}
