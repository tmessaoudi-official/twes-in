<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Location;

/** A location is kept while it is its establishment's default, holds other locations, or has seen goods move. */
final class StockLocationInUse extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $isDefault,
        public readonly int $locations,
        public readonly int $movements,
    ) {
        parent::__construct($message);
    }

    public static function asDefault(): self
    {
        return new self('The default location of an establishment is kept.', true, 0, 0);
    }

    public static function holding(int $locations, int $movements): self
    {
        return new self(\sprintf('The location holds %d locations and has seen %d stock movements: it is kept.', $locations, $movements), false, $locations, $movements);
    }
}
