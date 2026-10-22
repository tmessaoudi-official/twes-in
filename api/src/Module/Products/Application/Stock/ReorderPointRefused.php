<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Stock;

/**
 * A reorder point a product file named, refused by the inventory's own rule; the message says why, and the reason and
 * its parameters, when the rule gives one, what the import report translates.
 */
final class ReorderPointRefused extends \RuntimeException
{
    /** @param array<string, string|int> $params */
    public function __construct(string $message, public readonly ?string $reason = null, public readonly array $params = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
