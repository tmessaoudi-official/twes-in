<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Application;

/**
 * One condition to watch: what kind it is (`stock.lot_expiring`), what it is about, for the link to its list, and its
 * figures, which the screen puts into its sentence. Amounts and quantities are decimal strings, as everywhere else.
 */
final readonly class WatchItem
{
    /** @param array<string, string|int> $params */
    public function __construct(public string $kind, public ?string $subjectId, public array $params)
    {
    }
}
