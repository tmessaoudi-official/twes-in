<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Statement;

/** A statement was asked for a period that ends before it starts. */
final class InvalidStatementPeriod extends \RuntimeException
{
    /** @param string $from the period's first day, YYYY-MM-DD, which its last may not precede */
    public function __construct(string $message, public readonly string $from)
    {
        parent::__construct($message);
    }
}
