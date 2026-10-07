<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** A deposit invoice drawn from a quote, as the quote shows it: which, numbered or not, where it stands, how much. */
final readonly class QuoteDeposit
{
    /**
     * @param string|null $number null while it is a draft
     * @param string      $status the invoice's: draft, issued, partially_paid, paid or cancelled
     * @param string      $total  tax and fixed charges included, at the currency's scale
     */
    public function __construct(public string $invoiceId, public ?string $number, public string $status, public string $total)
    {
    }
}
