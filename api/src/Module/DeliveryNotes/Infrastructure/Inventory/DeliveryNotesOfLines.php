<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Inventory;

use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\Inventory\Application\SourceDeliveryNotes;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Answers the inventory's `SourceDeliveryNotes` port out of this module, within the company asked about only. */
final readonly class DeliveryNotesOfLines implements SourceDeliveryNotes
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofLines(array $lineIds, Uuid $companyId): array
    {
        if ([] === $lineIds) {
            return [];
        }
        /** @var list<array{id: Uuid}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT n.id AS id')
            ->from(DeliveryNoteLine::class, 'l')
            ->join('l.deliveryNote', 'n')
            ->where('l.id IN (:lines)')
            ->andWhere('n.company = :company')
            ->setParameter('lines', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $lineIds), ArrayParameterType::STRING)
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): Uuid => $row['id'], $rows);
    }
}
