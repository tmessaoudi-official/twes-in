<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

use App\Module\Expenses\Domain\ExpenseDetails;
use Symfony\Component\Uid\Uuid;

/** An expense as written: its details and the ids of what it names, each looked up in the company. */
final readonly class ExpenseInput
{
    public function __construct(
        public ExpenseDetails $details,
        public ?Uuid $vendorId = null,
        public ?Uuid $categoryId = null,
        public ?Uuid $taxComponentId = null,
    ) {
    }
}
