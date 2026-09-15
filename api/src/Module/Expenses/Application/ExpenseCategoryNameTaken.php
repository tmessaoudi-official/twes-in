<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

final class ExpenseCategoryNameTaken extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Another expense category of this company already has this name.');
    }
}
