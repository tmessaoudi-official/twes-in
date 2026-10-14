<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

use App\Fiscal\Domain\ChargeLeftOut;

/**
 * What a document's lines come to, with the title of the section each line opens, in the same order, and why it leaves
 * out a fixed charge chosen on it, by code (only a credit note leaves one out).
 */
final readonly class SectionedTotals
{
    /**
     * @param list<string|null>            $titles         one per line of `$totals`, null for a line opening no section
     * @param array<string, ChargeLeftOut> $chargesLeftOut
     */
    public function __construct(public DocumentTotals $totals, public array $titles, public array $chargesLeftOut = [])
    {
        if (\count($titles) !== \count($totals->lines)) {
            throw new \LogicException('A document names one title, or none, per line.');
        }
    }
}
