<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/** What a document's lines come to, with the title of the section each line opens, in the same order. */
final readonly class SectionedTotals
{
    /** @param list<string|null> $titles one per line of `$totals`, null for a line opening no section */
    public function __construct(public DocumentTotals $totals, public array $titles)
    {
        if (\count($titles) !== \count($totals->lines)) {
            throw new \LogicException('A document names one title, or none, per line.');
        }
    }
}
