<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Quotes;

use App\Module\Customers\Domain\Model\Customer;
use App\Tenancy\Domain\Model\Establishment;
use Symfony\Component\Uid\Uuid;

/** A quote as delivery notes see it: what it offers, line by line, and what keeps it from being delivered through notes. */
final readonly class QuoteToDeliver
{
    /**
     * @param bool                     $invoiced         whether it is on an invoice that is not cancelled, drafted from it as a whole
     * @param bool                     $documentDiscount whether it carries a discount on the whole, which an invoice of some of its
     *                                                   lines cannot share out exactly
     * @param bool                     $deposits         whether a deposit invoice drawn from it is not cancelled, which only the
     *                                                   invoice of the whole gives back
     * @param list<QuoteLineToDeliver> $lines            in order
     */
    public function __construct(
        public Uuid $id,
        public ?string $number,
        public bool $accepted,
        public Customer $customer,
        public Establishment $establishment,
        public ?string $customerReference,
        public bool $invoiced,
        public bool $documentDiscount,
        public bool $deposits,
        public array $lines,
    ) {
    }
}
