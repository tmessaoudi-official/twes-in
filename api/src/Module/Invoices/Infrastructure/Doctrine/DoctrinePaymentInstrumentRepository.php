<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Domain\InstrumentStatus;
use App\Module\Invoices\Domain\PaymentInstrument;
use App\Module\Invoices\Domain\PaymentInstrumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePaymentInstrumentRepository implements PaymentInstrumentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(PaymentInstrument $instrument): void
    {
        $this->entityManager->persist($instrument);
        $this->entityManager->flush();
    }

    public function remove(PaymentInstrument $instrument): void
    {
        $this->entityManager->remove($instrument);
        $this->entityManager->flush();
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?PaymentInstrument
    {
        return $this->entityManager->getRepository(PaymentInstrument::class)->findOneBy(['id' => $id, 'company' => $companyId]);
    }

    public function ofInvoice(Uuid $companyId, Uuid $invoiceId): array
    {
        /** @var list<PaymentInstrument> $found */
        $found = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(PaymentInstrument::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.invoice = :invoice')
            ->orderBy('i.dueOn', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('invoice', $invoiceId, 'uuid')
            ->getQuery()
            ->getResult();

        return $found;
    }

    public function openAmount(Uuid $companyId, Uuid $invoiceId): string
    {
        $sum = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(i.amount), 0)')
            ->from(PaymentInstrument::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.invoice = :invoice')
            ->andWhere('i.status IN (:open)')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('invoice', $invoiceId, 'uuid')
            ->setParameter('open', [InstrumentStatus::Held, InstrumentStatus::Deposited])
            ->getQuery()
            ->getSingleScalarResult();

        return \is_string($sum) || \is_int($sum) || \is_float($sum) ? number_format((float) $sum, 3, '.', '') : throw new \UnexpectedValueException('An open amount came back as neither a string nor a number.');
    }

    public function cashedByPayment(Uuid $companyId, Uuid $paymentId): ?PaymentInstrument
    {
        return $this->entityManager->getRepository(PaymentInstrument::class)->findOneBy(['company' => $companyId, 'payment' => $paymentId]);
    }
}
