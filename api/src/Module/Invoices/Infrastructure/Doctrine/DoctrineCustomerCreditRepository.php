<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Invoices\Application\Credit\CustomerAccountsHeld;
use App\Module\Invoices\Domain\Model\CustomerCreditEntry;
use App\Module\Invoices\Domain\Repository\CustomerCreditRepository;
use App\Module\Invoices\Domain\ValueObject\CreditEntryKind;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCustomerCreditRepository implements CustomerCreditRepository
{
    /** The entries of the customer kept and of every customer merged into it, merges being one level deep. */
    private const string HELD_BY = '(ec.id = :kept OR IDENTITY(ec.mergedInto) = :kept)';

    public function __construct(private EntityManagerInterface $entityManager, private CustomerAccountsHeld $held)
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
            ->join('e.customer', 'ec')
            ->where('e.company = :company')
            ->andWhere(self::HELD_BY)
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('kept', $this->kept($customerId), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return self::decimal($sum);
    }

    public function lockedBalance(Uuid $companyId, Uuid $customerId): string
    {
        // The customer's row is the lock every use of the balance takes, so two of them never both fit it. A deposit,
        // a refund given back or a payment deleted adds or removes entries without it, and none of them lowers the
        // sum: it can grow before this transaction ends, never shrink below what was read. The row is the customers'
        // own, so they hold it; outside a transaction the hold refuses.
        $this->held->hold($companyId, $customerId);

        return $this->balance($companyId, $customerId);
    }

    public function entries(Uuid $companyId, Uuid $customerId): array
    {
        $entries = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(CustomerCreditEntry::class, 'e')
            ->join('e.customer', 'ec')
            ->where('e.company = :company')
            ->andWhere(self::HELD_BY)
            ->orderBy('e.date', \SortDirection::Descending)
            ->addOrderBy('e.createdAt', \SortDirection::Descending)
            ->addOrderBy('e.id', \SortDirection::Descending)
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('kept', $this->kept($customerId), 'uuid')
            ->getQuery()
            ->getResult();

        return Rows::entities($entries, CustomerCreditEntry::class);
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

    /** The customer kept for the one asked: the one it was merged into, or itself. */
    private function kept(Uuid $customerId): Uuid
    {
        $customer = $this->entityManager->find(Customer::class, $customerId);

        return $customer instanceof Customer ? $customer->standingFor()->getId() : $customerId;
    }

    private static function decimal(mixed $value): string
    {
        // The database's NUMERIC comes back as an exact string, and stays one (docs/SPEC.md § 7, audit E-1).
        $value = \is_int($value) ? (string) $value : $value;

        return \is_string($value) && is_numeric($value) ? Decimal::format(Decimal::of($value), 3) : throw new \UnexpectedValueException('A credit balance came back as neither a decimal string nor an integer.');
    }
}
