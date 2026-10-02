<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/**
 * Why goods left the stock with no document: the reasons a loss is recorded under, so reports can tell a breakage from
 * a theft and a count never stands in for either. What each does to VAT is not decided here: that is sourced in
 * docs/fiscal before any reason carries a tax effect.
 */
enum StockLossReason: string
{
    case Lost = 'lost';
    case Broken = 'broken';
    case Expired = 'expired';
    case Stolen = 'stolen';
    case InternalUse = 'internal_use';
    case Sample = 'sample';
}
