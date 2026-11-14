<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Repository\AddressLockRepository;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * In SQL rather than through the entity: a guess is counted by one statement, so two guesses from one address at once
 * both count and the first guess from a new address cannot be inserted twice.
 */
final readonly class DoctrineAddressLockRepository implements AddressLockRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockedUntil(Uuid $userId, string $address, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $until = $this->connection->fetchOne(
            'SELECT locked_until FROM password_address_lock WHERE user_id = :user AND address = :address AND locked_until > :now',
            ['user' => $userId->toRfc4122(), 'address' => $address, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return $this->moment($until);
    }

    public function recordFailure(Uuid $userId, string $address, \DateTimeImmutable $now, int $lockAfter, \DateTimeImmutable $lockedUntil): ?int
    {
        // The conflict's update is skipped while the lock holds: RETURNING then gives no row, and nothing was counted.
        $count = $this->connection->fetchOne(
            'INSERT INTO password_address_lock (id, user_id, address, failed_count, locked_until, last_failed_at)
                VALUES (:id, :user, :address, 1, CASE WHEN 1 >= :after THEN CAST(:until AS timestamp) END, :now)
             ON CONFLICT (user_id, address) DO UPDATE SET
                failed_count = password_address_lock.failed_count + 1,
                locked_until = CASE WHEN password_address_lock.failed_count + 1 >= :after THEN CAST(:until AS timestamp) ELSE password_address_lock.locked_until END,
                last_failed_at = EXCLUDED.last_failed_at
             WHERE password_address_lock.locked_until IS NULL OR password_address_lock.locked_until <= EXCLUDED.last_failed_at
             RETURNING failed_count',
            ['id' => Uuid::v7()->toRfc4122(), 'user' => $userId->toRfc4122(), 'address' => $address, 'after' => $lockAfter, 'until' => $lockedUntil, 'now' => $now],
            ['after' => Types::INTEGER, 'until' => Types::DATETIME_IMMUTABLE, 'now' => Types::DATETIME_IMMUTABLE],
        );

        return false === $count ? null : Rows::int($count);
    }

    public function forget(Uuid $userId, string $address, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'DELETE FROM password_address_lock WHERE user_id = :user AND address = :address AND (locked_until IS NULL OR locked_until <= :now)',
            ['user' => $userId->toRfc4122(), 'address' => $address, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function forgetAll(Uuid $userId): void
    {
        $this->connection->executeStatement('DELETE FROM password_address_lock WHERE user_id = :user', ['user' => $userId->toRfc4122()]);
    }

    public function latestHolding(Uuid $userId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $until = $this->connection->fetchOne(
            'SELECT max(locked_until) FROM password_address_lock WHERE user_id = :user AND locked_until > :now',
            ['user' => $userId->toRfc4122(), 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return $this->moment($until);
    }

    public function latestHoldingOf(array $userIds, \DateTimeImmutable $now): array
    {
        if ([] === $userIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT user_id, max(locked_until) AS until FROM password_address_lock WHERE user_id IN (:users) AND locked_until > :now GROUP BY user_id',
            ['users' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $userIds), 'now' => $now],
            ['users' => ArrayParameterType::STRING, 'now' => Types::DATETIME_IMMUTABLE],
        );
        $until = [];
        foreach ($rows as $row) {
            $moment = $this->moment($row['until']);
            if (null !== $moment) {
                $until[Rows::text($row['user_id'])] = $moment;
            }
        }

        return $until;
    }

    private function moment(mixed $value): ?\DateTimeImmutable
    {
        $text = Rows::textOrNull(false === $value ? null : $value);
        $moment = null === $text ? null : $this->connection->convertToPHPValue($text, Types::DATETIME_IMMUTABLE);

        return $moment instanceof \DateTimeImmutable ? $moment : null;
    }
}
