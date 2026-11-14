<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\Login\PasswordLock;
use App\Identity\Domain\Repository\AddressLockRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** The address locks of the Doctrine repository's statements, kept in an array. */
final class InMemoryAddressLocks implements AddressLockRepository
{
    /** @var array<string, array{count: int, until: ?\DateTimeImmutable}> by account and address */
    public array $rows = [];

    /** A password lock over no address lock yet, for a use case that only reads it. */
    public static function lockOn(ClockInterface $clock, ?self $locks = null, ?FixedCallerAddress $caller = null): PasswordLock
    {
        return new PasswordLock($locks ?? new self(), $caller ?? new FixedCallerAddress(), $clock);
    }

    public function lockedUntil(Uuid $userId, string $address, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $until = $this->rows[self::key($userId, $address)]['until'] ?? null;

        return null !== $until && $until > $now ? $until : null;
    }

    public function recordFailure(Uuid $userId, string $address, \DateTimeImmutable $now, int $lockAfter, \DateTimeImmutable $lockedUntil): ?int
    {
        if (null !== $this->lockedUntil($userId, $address, $now)) {
            return null;
        }
        $row = $this->rows[self::key($userId, $address)] ?? ['count' => 0, 'until' => null];
        ++$row['count'];
        if ($row['count'] >= $lockAfter) {
            $row['until'] = $lockedUntil;
        }
        $this->rows[self::key($userId, $address)] = $row;

        return $row['count'];
    }

    public function forget(Uuid $userId, string $address, \DateTimeImmutable $now): void
    {
        if (null === $this->lockedUntil($userId, $address, $now)) {
            unset($this->rows[self::key($userId, $address)]);
        }
    }

    public function forgetAll(Uuid $userId): void
    {
        foreach (array_keys($this->rows) as $key) {
            if (str_starts_with($key, $userId->toRfc4122().' ')) {
                unset($this->rows[$key]);
            }
        }
    }

    public function latestHolding(Uuid $userId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($this->rows as $key => $row) {
            if (str_starts_with($key, $userId->toRfc4122().' ') && null !== $row['until'] && $row['until'] > $now && (null === $latest || $row['until'] > $latest)) {
                $latest = $row['until'];
            }
        }

        return $latest;
    }

    public function latestHoldingOf(array $userIds, \DateTimeImmutable $now): array
    {
        $until = [];
        foreach ($userIds as $userId) {
            $latest = $this->latestHolding($userId, $now);
            if (null !== $latest) {
                $until[$userId->toRfc4122()] = $latest;
            }
        }

        return $until;
    }

    private static function key(Uuid $userId, string $address): string
    {
        return $userId->toRfc4122().' '.$address;
    }
}
