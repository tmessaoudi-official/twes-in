<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/** Whether a product is delivered or performed: only goods go on a delivery note's stock movements. */
enum ProductKind: string
{
    case Goods = 'goods';
    case Service = 'service';
}
