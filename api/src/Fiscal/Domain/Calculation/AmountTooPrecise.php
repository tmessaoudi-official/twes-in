<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/** An amount written with more decimals than the document's currency has: refused, never rounded. */
final class AmountTooPrecise extends InvalidDocument
{
}
