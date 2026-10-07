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
     * The money the company received, by the month of its day, from a day on: payments not made of credit, deposits,
     * less refunds.
     *
     * @return array<string, string> the amount by `Y-m`
     */
    public function paidByMonth(Uuid $companyId, \DateTimeImmutable $from): array;

    /**
     * The VAT-family taxes of the documents issued from a day to before another, credit notes included, by code and rate.
     * A tax is VAT when a line of its document charges a VAT-family component of that code: issuing keeps only the code.
     * Each is named as the company names that code while it still has it at that rate, null otherwise: the rate a
     * document froze stays true, a name given since to another rate would not.
     *
     * @return list<array{code: string, rate: string, name: ?string, amount: string}>
     */
    public function vatIssued(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array;

    /**
     * The money the company received between two days (from included, until excluded), as `paidByMonth` counts it.
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
