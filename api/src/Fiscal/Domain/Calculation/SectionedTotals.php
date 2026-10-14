<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

use App\Fiscal\Domain\ChargeLeftOut;

/**
 * What a document's lines come to, with the title of the section each line opens, in the same order, why it leaves out a
 * fixed charge chosen on it, by code (only a credit note leaves one out), and where each line was sent from, as a
 * document may keep its lines otherwise than they were sent.
 */
final readonly class SectionedTotals
{
    /**
     * @param list<string|null>            $titles         one per line of `$totals`, null for a line opening no section
     * @param array<string, ChargeLeftOut> $chargesLeftOut
     * @param list<int|null>               $sentFrom       one per line of `$totals`: its position among the lines sent, null for one the document adds
     */
    public function __construct(public DocumentTotals $totals, public array $titles, public array $chargesLeftOut, public array $sentFrom)
    {
        if (\count($titles) !== \count($totals->lines)) {
            throw new \LogicException('A document names one title, or none, per line.');
        }
        if (\count($sentFrom) !== \count($totals->lines)) {
            throw new \LogicException('A document says where each of its lines was sent from.');
        }
    }
}
