<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use Symfony\Component\Uid\Uuid;

/**
 * The sums the home page's figures are made of, added up where the invoices are kept: reading every invoice to add
 * them costs a statement per invoice and grows with the years. Amounts are decimal strings, days are the company's.
 */
interface InvoiceSummarySource
{
    /**
     * What is still due on the company's issued and partly paid invoices (credit notes are already off it), by due day;
     * an invoice without a due day is its own group.
     *
     * @return list<array{dueDate: ?\DateTimeImmutable, amount: string, count: int}>
     */
    public function openByDueDate(Uuid $companyId): array;

    /**
     * The first of those invoices due on or before a day: the earliest due first, then by number, byte by byte.
     *
     * @return list<array{invoiceId: string, number: string, customerName: string, dueDate: \DateTimeImmutable, amountDue: string}>
     */
    public function firstDueBy(Uuid $companyId, \DateTimeImmutable $day, int $limit): array;

    /**
     * What the company's issued invoices were paid, by the month of the payment's day, from a day on.
     *
     * @return array<string, string> the amount by `Y-m`
     */
    public function paidByMonth(Uuid $companyId, \DateTimeImmutable $from): array;

    /**
     * The VAT-family taxes of the documents issued from a day to before another, credit notes included, by code and rate.
     * A tax is VAT when a line of its document charges a VAT-family component of that code: issuing keeps only the code.
     *
     * @return list<array{code: string, rate: string, amount: string}>
     */
    public function vatIssued(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array;

    /**
     * What the company's issued invoices were paid between two days (from included, until excluded).
     */
    public function paidBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): string;

    /**
     * What the company's issued documents kept back for the tax office between two days (from included, until
     * excluded), credit notes netting theirs out.
     */
    public function withheldBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): string;

    /**
     * What the documents issued from a day to before another came to before tax, credit notes counted negative, and, over
     * the lines whose frozen cost is known only, what those lines sold for, what they cost and how many they are.
     *
     * @return array{net: string, costedNet: string, cost: string, costedLines: int}
     */
    public function invoicedBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array;
}
