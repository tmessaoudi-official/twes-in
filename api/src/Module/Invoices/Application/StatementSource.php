<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use Symfony\Component\Uid\Uuid;

/**
 * What one customer's account is made of, read where the documents are kept. An amount is signed from the customer's
 * side: positive when it adds to what they owe (an invoice), negative when it takes from it (a credit note, a
 * payment). Drafts and withdrawn documents are not on the account: nothing was owed by them.
 */
interface StatementSource
{
    /** What the customer owed at the start of a day: the sum of everything dated before it. */
    public function balanceBefore(Uuid $companyId, Uuid $customerId, \DateTimeImmutable $day): string;

    /**
     * What happened from a day to a day, both included, in the order it happened: by day, then invoices, credit notes
     * and payments, then by number byte by byte.
     *
     * @return list<array{day: \DateTimeImmutable, kind: string, number: string, documentId: string, reference: ?string, amount: string}>
     */
    public function entries(Uuid $companyId, Uuid $customerId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
