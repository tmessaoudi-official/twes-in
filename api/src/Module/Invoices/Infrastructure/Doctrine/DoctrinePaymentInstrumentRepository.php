<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Domain\InstrumentPortfolioSearch;
use App\Module\Invoices\Domain\InstrumentStatus;
use App\Module\Invoices\Domain\PaymentInstrument;
use App\Module\Invoices\Domain\PaymentInstrumentRepository;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\Intervals;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePaymentInstrumentRepository implements PaymentInstrumentRepository
{
    /** The DQL expression each sort of the portfolio reads. */
    private const array SORTED_BY = ['dueOn' => 'i.dueOn', 'amount' => 'i.amount', 'status' => 'i.status'];

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

    public function portfolio(Uuid $companyId, InstrumentPortfolioSearch $search, PageRequest $page): Page
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('i', 'inv', 'c')
            ->from(PaymentInstrument::class, 'i')
            ->join('i.invoice', 'inv')
            ->join('inv.customer', 'c')
            ->where('i.company = :company')
            ->setParameter('company', $companyId, 'uuid');
        // The values of one filter are OR'd, the filters AND'd.
        if ([] !== $search->statuses) {
            $query->andWhere('i.status IN (:statuses)')->setParameter('statuses', $search->statuses);
        }
        if ([] !== $search->kinds) {
            $query->andWhere('i.kind IN (:kinds)')->setParameter('kinds', $search->kinds);
        }
        if ([] !== $search->customers) {
            $query->andWhere('inv.customer IN (:customerIds)')
                ->setParameter('customerIds', array_map(static fn (Uuid $each): string => $each->toRfc4122(), $search->customers), ArrayParameterType::STRING);
        }
        Intervals::days($query, 'i.dueOn', 'due', $search->dueOn);
        Intervals::amounts($query, 'i.amount', 'amount', $search->amount);
        // The nearest due day first when nothing was asked; the id settles ties, so a page never shifts.
        ListOrder::apply($query, [] === $search->order ? ['dueOn' => 'asc'] : $search->order, self::SORTED_BY, [], 'i.id')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<PaymentInstrument> $found */
        $found = iterator_to_array($paginator, false);

        return new Page($found, \count($paginator), $page);
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
