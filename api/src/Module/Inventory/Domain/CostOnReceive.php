<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/** What a receipt does to the product's cost, as the company decided: offer a choice, apply one figure, or leave it. */
enum CostOnReceive: string
{
    case Suggest = 'suggest';
    case Average = 'average';
    case Last = 'last';
    case Manual = 'manual';
}
