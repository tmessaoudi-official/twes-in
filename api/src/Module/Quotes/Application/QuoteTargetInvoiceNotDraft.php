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
    public function __construct(string $number)
    {
        parent::__construct(\sprintf('The invoice %s is not a draft any more: a quote is added only to a draft.', $number));
    }
}
