<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/** How the copies of a document's state sit on its page, once it prints more than one. */
enum WatermarkSpread: string
{
    /** One block of lines across the middle, on the diagonal. */
    case Grouped = 'grouped';
    /** One copy in each cell of a grid over the whole page. */
    case Spread = 'spread';
}
