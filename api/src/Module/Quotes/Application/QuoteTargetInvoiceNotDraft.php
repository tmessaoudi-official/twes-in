<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** The invoice a quote was to be added to is issued or cancelled: an issued invoice is corrected by a credit note. */
final class QuoteTargetInvoiceNotDraft extends \DomainException
{
    /**
     * @param string                    $reason a stable code the screen says in its own words, the English message being for logs
     * @param array<string, string|int> $params that text's parameters
     */
    public function __construct(string $message, public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
