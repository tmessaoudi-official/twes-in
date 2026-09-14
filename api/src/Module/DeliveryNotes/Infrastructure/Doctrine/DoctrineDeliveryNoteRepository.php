<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Doctrine;

use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineDeliveryNoteRepository implements DeliveryNoteRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(DeliveryNote::class)->findBy(['company' => $companyId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?DeliveryNote
    {
        $note = $this->entityManager->find(DeliveryNote::class, $id);

        return null !== $note && $note->getCompany()->getId()->equals($companyId) ? $note : null;
    }

    public function lockedOfIdsInCompany(array $ids, Uuid $companyId): array
    {
        return $this->locked('n.id IN (:ids)', $ids, $companyId);
    }

    public function lockedOfLineIdsInCompany(array $lineIds, Uuid $companyId): array
    {
        return $this->locked('n.id IN (SELECT IDENTITY(l.deliveryNote) FROM '.DeliveryNoteLine::class.' l WHERE l.id IN (:ids))', $lineIds, $companyId);
    }

    public function numberTaken(Uuid $companyId, string $number): bool
    {
        return null !== $this->entityManager->getRepository(DeliveryNote::class)->findOneBy(['company' => $companyId, 'number' => $number]);
    }

    public function save(DeliveryNote $note): void
    {
        $this->entityManager->persist($note);
        $this->entityManager->flush();
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<DeliveryNote>
     */
    private function locked(string $condition, array $ids, Uuid $companyId): array
    {
        if ([] === $ids) {
            return [];
        }
        $notes = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(DeliveryNote::class, 'n')
            ->where($condition)
            ->andWhere('n.company = :company')
            ->orderBy('n.id')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            // SELECT … FOR UPDATE in id order, so two requests holding the same notes wait for each other rather than deadlock.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_values(array_filter(\is_array($notes) ? $notes : [], static fn (mixed $note): bool => $note instanceof DeliveryNote));
    }
}
