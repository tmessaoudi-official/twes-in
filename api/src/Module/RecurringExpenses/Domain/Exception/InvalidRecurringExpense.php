<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Domain\Exception;

/** A recurring expense refused on the field that says why, with the reason a screen translates. */
final class InvalidRecurringExpense extends \DomainException
{
    /** @param array<string, string|int> $params what the reason's text names */
    public function __construct(public readonly string $field, string $message, public readonly string $reason = 'invalid_value', public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
