<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Domain\Customer;
use App\Module\Invoices\Domain\CreditEntryKind;
use App\Module\Invoices\Domain\CustomerCreditEntry;
use App\Module\Invoices\Domain\CustomerCreditRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCustomerCreditRepository implements CustomerCreditRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(CustomerCreditEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function balance(Uuid $companyId, Uuid $customerId): string
    {
        $sum = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(e.amount), 0)')
            ->from(CustomerCreditEntry::class, 'e')
            ->where('e.company = :company')
            ->andWhere('e.customer = :customer')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('customer', $customerId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return self::decimal($sum);
    }

    public function lockedBalance(Uuid $companyId, Uuid $customerId): string
    {
        // The customer's row is the lock: entries are only ever added or removed with it held, so the sum read next
        // is not moved by another transaction before this one ends. Doctrine refuses the lock outside a transaction.
        $this->entityManager->createQueryBuilder()
            ->select('c.id')
            ->from(Customer::class, 'c')
            ->where('c.id = :customer')
            ->andWhere('c.company = :company')
            ->setParameter('customer', $customerId, 'uuid')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $this->balance($companyId, $customerId);
    }

    public function entries(Uuid $companyId, Uuid $customerId): array
    {
        $entries = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(CustomerCreditEntry::class, 'e')
            ->where('e.company = :company')
            ->andWhere('e.customer = :customer')
            ->orderBy('e.date', 'DESC')
            ->addOrderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('customer', $customerId, 'uuid')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(\is_array($entries) ? $entries : [], static fn (mixed $entry): bool => $entry instanceof CustomerCreditEntry));
    }

    public function appliedByPayment(Uuid $companyId, Uuid $paymentId): ?CustomerCreditEntry
    {
        $entry = $this->entityManager->getRepository(CustomerCreditEntry::class)->findOneBy(['company' => $companyId, 'paymentId' => $paymentId]);

        return $entry instanceof CustomerCreditEntry ? $entry : null;
    }

    public function hasGivenBack(Uuid $companyId, Uuid $invoiceId): bool
    {
        return null !== $this->entityManager->getRepository(CustomerCreditEntry::class)->findOneBy(['company' => $companyId, 'invoiceId' => $invoiceId, 'kind' => CreditEntryKind::Credited]);
    }

    public function remove(CustomerCreditEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }

    private static function decimal(mixed $value): string
    {
        // The database's NUMERIC comes back as an exact string, and stays one (docs/SPEC.md § 7, audit E-1).
        $value = \is_int($value) ? (string) $value : $value;

        return \is_string($value) && is_numeric($value) ? Decimal::format(Decimal::of($value), 3) : throw new \UnexpectedValueException('A credit balance came back as neither a decimal string nor an integer.');
    }
}
