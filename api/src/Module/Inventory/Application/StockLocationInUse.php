<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

/** A location is kept while it is its establishment's default, holds other locations, or has seen goods move. */
final class StockLocationInUse extends \RuntimeException
{
    public static function asDefault(): self
    {
        return new self('The default location of an establishment is kept.');
    }

    public static function holding(int $locations, int $movements): self
    {
        return new self(\sprintf('The location holds %d locations and has seen %d stock movements: it is kept.', $locations, $movements));
    }
}
