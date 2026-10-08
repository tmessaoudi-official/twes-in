<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Application;

/**
 * What a recurring invoice shows of its model: the model's number, null while it is a draft, the customer it is for,
 * and whether it can be copied at all (a credit note, a deposit invoice or a cancelled invoice cannot).
 */
final readonly class RecurringModel
{
    public function __construct(
        public ?string $number,
        public string $customerName,
        public bool $copiable,
    ) {
    }
}
