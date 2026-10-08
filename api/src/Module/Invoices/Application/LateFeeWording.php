<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * The words the API writes on the line of a late fee's draft, in the language the customer's documents are written in.
 * A person can still revise the line while it is a draft.
 */
interface LateFeeWording
{
    /** The fee's line, naming the late invoice by its number and the reminder stage it reached. */
    public function lateFeeLine(string $language, string $invoiceNumber, int $stage, int $daysLate): string;
}
