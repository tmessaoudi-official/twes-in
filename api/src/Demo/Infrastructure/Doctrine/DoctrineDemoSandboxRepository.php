<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Doctrine;

use App\Demo\Domain\Model\DemoSandbox;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Demo\Domain\ValueObject\SandboxState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineDemoSandboxRepository implements DemoSandboxRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(DemoSandbox $sandbox): void
    {
        $this->entityManager->persist($sandbox);
        $this->entityManager->flush();
    }

    public function remove(DemoSandbox $sandbox): void
    {
        $this->entityManager->remove($sandbox);
        $this->entityManager->flush();
    }

    public function ofId(Uuid $id): ?DemoSandbox
    {
        return $this->entityManager->find(DemoSandbox::class, $id);
    }

    public function lockedOfId(Uuid $id): ?DemoSandbox
    {
        return $this->entityManager->find(DemoSandbox::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function lockedReadyOf(string $country): ?DemoSandbox
    {
        // DQL has no SKIP LOCKED: the row is taken in SQL, then read by its id under the lock it now holds.
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM demo_sandbox WHERE country = ? AND state = ? ORDER BY ready_at, id LIMIT 1 FOR UPDATE SKIP LOCKED',
            [$country, SandboxState::Ready->value],
        );
        if (false === $id) {
            return null;
        }

        return $this->ofId(Uuid::fromString(\is_string($id) ? $id : throw new \LogicException('A sandbox id reads as text.')));
    }

    public function lockPool(): void
    {
        // Transaction-scoped, as for stock: the sandboxes about to be queued have no row yet to lock.
        $this->entityManager->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['demo_sandbox_pool']);
    }

    public function waitingByCountry(): array
    {
        $waiting = [];
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT country, COUNT(*) AS n FROM demo_sandbox WHERE state IN (?, ?, ?) GROUP BY country',
            [SandboxState::Queued->value, SandboxState::Building->value, SandboxState::Ready->value],
        );
        foreach ($rows as $row) {
            $country = $row['country'];
            $count = $row['n'];
            if (!\is_string($country) || !is_numeric($count)) {
                throw new \LogicException('A count of sandboxes reads as a country and a number.');
            }
            $waiting[$country] = (int) $count;
        }

        return $waiting;
    }

    public function buildingSince(\DateTimeImmutable $before): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM demo_sandbox WHERE state = ? AND build_started_at < ? ORDER BY build_started_at',
            [SandboxState::Building->value, $before],
            [1 => 'datetime_immutable'],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromString(\is_string($id) ? $id : throw new \LogicException('A sandbox id reads as text.')), $ids);
    }

    public function ownsOne(Uuid $userId): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM demo_sandbox WHERE owner_id = ?', [$userId->toRfc4122()]);
    }

    public function ownedByAddress(string $address): ?DemoSandbox
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT s.id FROM demo_sandbox s JOIN "user" u ON u.id = s.owner_id WHERE u.email = ?',
            [$address],
        );

        return false === $id ? null : $this->ofId(Uuid::fromString(\is_string($id) ? $id : throw new \LogicException('A sandbox id reads as text.')));
    }

    public function ofCompany(Uuid $companyId): ?DemoSandbox
    {
        return $this->entityManager->getRepository(DemoSandbox::class)->findOneBy(['company' => $companyId]);
    }

    public function count(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM demo_sandbox');

        return is_numeric($count) ? (int) $count : throw new \LogicException('A count of sandboxes reads as a number.');
    }
}
