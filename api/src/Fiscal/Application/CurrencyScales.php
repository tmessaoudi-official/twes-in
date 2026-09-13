<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application;

/** How many decimals a currency has: 3 for the dinar, 2 for the euro, 0 for the yen. */
interface CurrencyScales
{
    public function of(string $currency): int;
}
