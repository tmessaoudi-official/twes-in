<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/** What moved a product's cost: it was set when the product was created, a person edited it, or a receipt applied one. */
enum CostChangeSource: string
{
    case Created = 'created';
    case Edited = 'edited';
    case Receipt = 'receipt';
}
