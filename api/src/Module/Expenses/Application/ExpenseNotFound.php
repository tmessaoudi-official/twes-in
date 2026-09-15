<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

final class ExpenseNotFound extends \DomainException
{
    public function __construct()
    {
        parent::__construct('No such expense in this company.');
    }
}
