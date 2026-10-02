<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

/** A customer's credit balance, kept as entries (docs/SPEC.md § 7). */
interface CustomerCreditRepository
{
    public function save(CustomerCreditEntry $entry): void;

    /** Three decimals: what the customer has to their credit, the sum of their entries. */
    public function balance(Uuid $companyId, Uuid $customerId): string;

    /**
     * The balance with the customer's row held until the transaction this runs in ends, so two applications at once
     * never both fit it; outside a transaction it refuses.
     */
    public function lockedBalance(Uuid $companyId, Uuid $customerId): string;

    /** @return list<CustomerCreditEntry> newest first, by day then in the order they were recorded */
    public function entries(Uuid $companyId, Uuid $customerId): array;

    /** The entry a payment applied, if it applied credit. */
    public function appliedByPayment(Uuid $companyId, Uuid $paymentId): ?CustomerCreditEntry;

    /** Whether a credit note of this invoice gave back money it had been paid, which a payment then cannot be taken away from. */
    public function hasGivenBack(Uuid $companyId, Uuid $invoiceId): bool;

    public function remove(CustomerCreditEntry $entry): void;
}
