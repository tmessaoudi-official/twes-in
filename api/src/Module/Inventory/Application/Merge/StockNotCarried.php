<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Merge;

/** What a product merged away holds could not go to the product kept: the merge is refused, its reason a code the screen says. */
final class StockNotCarried extends \RuntimeException
{
    /** @param array<string, string> $params */
    public function __construct(public readonly string $reason, string $message, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
