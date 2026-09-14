<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Invoices\Domain\InvoiceHeader;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice as it is written: its customer and establishment by id (none: the company's default), its header, its
 * lines and its document taxes (null: the company's and the customer's defaults the customer's regime charges).
 */
final readonly class InvoiceInput
{
    /**
     * @param list<InvoiceLineInput> $lines
     * @param list<Uuid>|null        $documentTaxComponentIds null for the defaults; an empty list for none
     */
    public function __construct(
        public Uuid $customerId,
        public ?Uuid $establishmentId,
        public InvoiceHeader $header,
        public array $lines,
        public ?array $documentTaxComponentIds = null,
    ) {
    }
}
