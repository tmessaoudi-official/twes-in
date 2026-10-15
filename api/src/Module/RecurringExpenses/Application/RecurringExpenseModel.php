<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Application;

/** What a list and a notification say of the expense a recurring expense copies. */
final readonly class RecurringExpenseModel
{
    /**
     * @param string|null $payee       the vendor's name, or the payee written when no vendor is named
     * @param string      $amountGross what the model comes to, written in its currency's decimals
     */
    public function __construct(public string $description, public ?string $payee, public string $amountGross, public string $currency)
    {
    }
}
