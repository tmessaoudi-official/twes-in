<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Invoices;

use App\Module\Customers\Domain\Model\Customer;
use App\Tenancy\Domain\Model\Establishment;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice as delivery notes see it: whether it is issued and a sale of goods, and the lines whose goods it billed
 * straight away, which left stock when it was issued and are delivered afterwards, on as many notes as it takes.
 */
final readonly class InvoiceToDeliver
{
    /**
     * @param bool                       $issued whether it is issued and not cancelled: a draft has billed nothing yet
     * @param bool                       $sale   whether it is an invoice of goods, neither a credit note nor a deposit invoice
     * @param list<InvoiceLineToDeliver> $lines  its lines of a product it billed itself, not through a delivery note, in order
     */
    public function __construct(
        public Uuid $id,
        public ?string $number,
        public bool $issued,
        public bool $sale,
        public Customer $customer,
        public Establishment $establishment,
        public ?string $customerReference,
        public array $lines,
    ) {
    }
}
