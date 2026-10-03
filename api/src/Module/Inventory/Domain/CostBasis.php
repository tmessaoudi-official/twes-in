<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/** The figure a receipt can make the product's cost: the weighted average of what came in, or the cost typed on the receipt. */
enum CostBasis: string
{
    case Average = 'average';
    case Last = 'last';
}
