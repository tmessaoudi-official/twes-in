<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/** Whether the prices typed on a document's lines exclude its percentage taxes or already include them. */
enum TaxBasis: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';
}
