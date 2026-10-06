<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Invoices;

use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\Invoices\Application\SourceDeliveryNoteLine;
use App\Module\Invoices\Application\SourceDeliveryNoteLines;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Answers the invoices' `SourceDeliveryNoteLines` port out of this module, within the company asked about only. */
final readonly class DeliveryNoteLinesAsSources implements SourceDeliveryNoteLines
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofIds(array $lineIds, Uuid $companyId): array
    {
        if ([] === $lineIds) {
            return [];
        }
        $lines = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(DeliveryNoteLine::class, 'l')
            ->join('l.deliveryNote', 'n')
            ->where('l.id IN (:lines)')
            ->andWhere('n.company = :company')
            ->setParameter('lines', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $lineIds), ArrayParameterType::STRING)
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->getResult();

        $sources = [];
        foreach (\is_array($lines) ? $lines : [] as $line) {
            if ($line instanceof DeliveryNoteLine) {
                $sources[$line->getId()->toRfc4122()] = new SourceDeliveryNoteLine($line->getProduct()?->getId(), $line->getQuantity());
            }
        }

        return $sources;
    }
}
