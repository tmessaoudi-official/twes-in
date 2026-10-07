<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** What the invoice an accepted quote is drafted into refuses, named on its field as the invoice names it. */
final class QuoteInvoicingRefused extends \DomainException
{
    public function __construct(public readonly string $field, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
