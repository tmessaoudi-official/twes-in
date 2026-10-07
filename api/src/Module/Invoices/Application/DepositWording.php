<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * The words the API writes on the lines of a deposit invoice and of the invoice giving it back, in the language the
 * customer's documents are written in. A person can still revise a deposit's lines while it is a draft.
 */
interface DepositWording
{
    /** A line of a deposit drawn from a quote, naming the share asked; with an amount asked, no share. */
    public function depositLine(string $language, string $quoteNumber, ?string $percentage): string;

    /** A line giving back a deposit invoice, naming it by its number and day, as the final invoice must. */
    public function deductionLine(string $language, string $depositNumber, \DateTimeImmutable $issueDate): string;
}
