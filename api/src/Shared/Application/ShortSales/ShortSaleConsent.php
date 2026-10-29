<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\ShortSales;

use Symfony\Component\Uid\Uuid;

/**
 * Who sells, as the rule reads them: their role in the company, which the rule may be set for, whether they may confirm a
 * sale beyond the stock, and whether this request confirms it. Nobody named reads the company's and the establishment's
 * rule alone, and confirms nothing.
 */
final readonly class ShortSaleConsent
{
    /** The permission a confirmation takes; the stock module declares it as `stock.sell_short`. */
    public const string PERMISSION = 'stock.sell_short';

    public function __construct(public ?Uuid $roleId = null, public bool $mayConfirm = false, public bool $confirmed = false)
    {
    }
}
