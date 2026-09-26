<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/**
 * An issued invoice or credit note in the terms of EN 16931, as the Factur-X EN 16931 profile carries them in a
 * UN/CEFACT Cross Industry Invoice (D16B). Amounts are decimal strings at the currency's scale, positive on a credit note
 * as on an invoice: the type code (BT-3, 380 or 381) says which way the money goes.
 */
final readonly class CiiInvoice
{
    /**
     * @param list<string>          $notes        BT-22, in order
     * @param list<CiiLine>         $lines
     * @param list<CiiAllowance>    $allowances   the document discount, one per VAT category and rate
     * @param list<CiiVatBreakdown> $vatBreakdown
     */
    public function __construct(
        public string $typeCode,
        public string $number,
        public \DateTimeImmutable $issueDate,
        public string $currency,
        public array $notes,
        public CiiParty $seller,
        public CiiParty $buyer,
        public ?string $buyerReference,
        public ?\DateTimeImmutable $deliveryDate,
        public ?string $precedingInvoiceNumber,
        public ?\DateTimeImmutable $precedingInvoiceIssueDate,
        public ?string $payeeIban,
        public ?string $payeeBic,
        public ?\DateTimeImmutable $dueDate,
        public array $lines,
        public array $allowances,
        public array $vatBreakdown,
        public CiiTotals $totals,
    ) {
    }
}
