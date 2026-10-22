<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Invoices;

use App\Module\DeliveryNotes\Domain\Model\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Module\Invoices\Application\SourceDeliveryNote;
use App\Module\Invoices\Application\SourceDeliveryNoteLine;
use App\Module\Invoices\Application\SourceDeliveryNoteLines;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Answers the invoices' `SourceDeliveryNoteLines` port out of this module, within the company asked about only. */
final readonly class DeliveryNoteLinesAsSources implements SourceDeliveryNoteLines
{
    public function __construct(private EntityManagerInterface $entityManager, private DeliveryNoteRepository $notes)
    {
    }

    public function lockedOfIds(array $lineIds, Uuid $companyId): array
    {
        if ([] === $lineIds) {
            return [];
        }
        $this->notes->lockedOfLineIdsInCompany($lineIds, $companyId);

        return $this->ofIds($lineIds, $companyId);
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
                $sources[$line->getId()->toRfc4122()] = new SourceDeliveryNoteLine($line->getProduct()?->getId(), $line->getQuantity(), DeliveryNoteStatus::Cancelled === $line->getDeliveryNote()->getStatus());
            }
        }

        return $sources;
    }

    public function notesOf(array $lineIds, Uuid $companyId): array
    {
        if ([] === $lineIds) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select('n.id AS note', 'n.number AS number', 'l.id AS line')
            ->from(DeliveryNoteLine::class, 'l')
            ->join('l.deliveryNote', 'n')
            ->where('l.id IN (:lines)')
            ->andWhere('n.company = :company')
            ->setParameter('lines', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $lineIds), ArrayParameterType::STRING)
            ->setParameter('company', $companyId, 'uuid')
            ->orderBy('n.number')
            ->addOrderBy('n.id')
            ->getQuery()
            ->getArrayResult();

        $notes = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !$row['note'] instanceof Uuid || !$row['line'] instanceof Uuid || !(null === $row['number'] || \is_string($row['number']))) {
                throw new \UnexpectedValueException('A delivery note line read back without its note.');
            }
            $id = $row['note']->toRfc4122();
            $notes[$id] ??= ['id' => $row['note'], 'number' => $row['number'], 'lines' => []];
            $notes[$id]['lines'][] = $row['line']->toRfc4122();
        }

        return array_values(array_map(static fn (array $note): SourceDeliveryNote => new SourceDeliveryNote($note['id'], $note['number'], $note['lines']), $notes));
    }
}
