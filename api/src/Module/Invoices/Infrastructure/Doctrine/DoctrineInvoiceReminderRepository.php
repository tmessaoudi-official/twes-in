<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Domain\InvoiceReminder;
use App\Module\Invoices\Domain\InvoiceReminderRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInvoiceReminderRepository implements InvoiceReminderRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function recordOnce(InvoiceReminder $reminder): bool
    {
        // The unique stage per invoice decides between two runs at once, without a lock: the one whose row lands tells.
        $written = $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO invoice_reminder (id, company_id, invoice_id, stage, days_late, reached_on, recorded_at)
             VALUES (:id, :company, :invoice, :stage, :late, :day, :at)
             ON CONFLICT (invoice_id, stage) DO NOTHING',
            [
                'id' => $reminder->getId()->toRfc4122(),
                'company' => $reminder->getCompany()->getId()->toRfc4122(),
                'invoice' => $reminder->getInvoiceId()->toRfc4122(),
                'stage' => $reminder->getStage(),
                'late' => $reminder->getDaysLate(),
                'day' => $reminder->getReachedOn()->format('Y-m-d'),
                'at' => $reminder->getRecordedAt()->format('Y-m-d H:i:s'),
            ],
        );

        return 1 === (int) $written;
    }

    public function highestStages(Uuid $companyId, array $invoiceIds): array
    {
        if ([] === $invoiceIds) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT invoice_id, MAX(stage) AS stage FROM invoice_reminder WHERE company_id = :company AND invoice_id IN (:invoices) GROUP BY invoice_id',
            ['company' => $companyId->toRfc4122(), 'invoices' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $invoiceIds)],
            ['invoices' => ArrayParameterType::STRING],
        );
        $highest = [];
        foreach ($rows as $row) {
            $invoice = $row['invoice_id'];
            $stage = $row['stage'];
            if (!\is_string($invoice) || !is_numeric($stage)) {
                throw new \UnexpectedValueException('A reminder stage came back in a shape it is never written in.');
            }
            $highest[$invoice] = (int) $stage;
        }

        return $highest;
    }

    public function ofInvoice(Uuid $companyId, Uuid $invoiceId): array
    {
        $found = $this->entityManager->createQueryBuilder()
            ->select('r')->from(InvoiceReminder::class, 'r')
            ->where('r.company = :company')->andWhere('r.invoiceId = :invoice')
            ->setParameter('company', $companyId, 'uuid')->setParameter('invoice', $invoiceId, 'uuid')
            ->orderBy('r.stage')
            ->getQuery()->getResult();
        $reminders = [];
        foreach (\is_array($found) ? $found : [] as $reminder) {
            if ($reminder instanceof InvoiceReminder) {
                $reminders[] = $reminder;
            }
        }

        return $reminders;
    }
}
