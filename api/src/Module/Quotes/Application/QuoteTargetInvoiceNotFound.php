<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** The invoice a quote was to be added to does not exist, or belongs to another company. */
final class QuoteTargetInvoiceNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such invoice.');
    }
}
