<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Application;

final class RecurringExpenseNotFound extends \DomainException
{
    public function __construct()
    {
        parent::__construct('No such recurring expense in this company.');
    }
}
