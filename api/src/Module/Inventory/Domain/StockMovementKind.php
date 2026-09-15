<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/** Which way goods moved: in, out, or a count that set the stock right. */
enum StockMovementKind: string
{
    case In = 'in';
    case Out = 'out';
    case Adjustment = 'adjustment';
}
