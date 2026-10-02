<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Domain\CreditEntryKind;
use App\Module\Invoices\Domain\CustomerCreditEntry;
use App\Module\Invoices\Domain\CustomerCreditRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryCustomerCredits implements CustomerCreditRepository
{
    /** @var list<CustomerCreditEntry> */
    public array $entries = [];

    public function save(CustomerCreditEntry $entry): void
    {
        if (!\in_array($entry, $this->entries, true)) {
            $this->entries[] = $entry;
        }
    }

    public function balance(Uuid $companyId, Uuid $customerId): string
    {
        $sum = Decimal::zero();
        foreach ($this->entries($companyId, $customerId) as $entry) {
            $sum = $sum->add(Decimal::of($entry->getAmount()));
        }

        return Decimal::format($sum, 3);
    }

    public function lockedBalance(Uuid $companyId, Uuid $customerId): string
    {
        return $this->balance($companyId, $customerId);
    }

    public function entries(Uuid $companyId, Uuid $customerId): array
    {
        $mine = array_values(array_filter($this->entries, static fn (CustomerCreditEntry $entry): bool => $entry->getCompany()->getId()->equals($companyId) && $entry->getCustomer()->getId()->equals($customerId)));
        usort($mine, static fn (CustomerCreditEntry $a, CustomerCreditEntry $b): int => [$b->getDate(), $b->getCreatedAt()] <=> [$a->getDate(), $a->getCreatedAt()]);

        return $mine;
    }

    public function appliedByPayment(Uuid $companyId, Uuid $paymentId): ?CustomerCreditEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->getCompany()->getId()->equals($companyId) && $paymentId->equals($entry->getPaymentId())) {
                return $entry;
            }
        }

        return null;
    }

    public function hasGivenBack(Uuid $companyId, Uuid $invoiceId): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->getCompany()->getId()->equals($companyId) && CreditEntryKind::Credited === $entry->getKind() && $invoiceId->equals($entry->getInvoiceId())) {
                return true;
            }
        }

        return false;
    }

    public function remove(CustomerCreditEntry $entry): void
    {
        $this->entries = array_values(array_filter($this->entries, static fn (CustomerCreditEntry $each): bool => $each !== $entry));
    }
}
