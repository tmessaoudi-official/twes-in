<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Domain;

/** A recurring expense refused on the field that says why, with the reason a screen translates. */
final class InvalidRecurringExpense extends \DomainException
{
    public function __construct(public readonly string $field, string $message, public readonly string $reason = 'invalid_value')
    {
        parent::__construct($message);
    }
}
