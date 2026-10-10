<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/**
 * One section of a document's lines: its title, the line that opens it (by position from 0), how many lines it holds,
 * and what their nets add up to, excluding tax, at the currency's scale.
 */
final readonly class SectionSubtotal
{
    public function __construct(public string $title, public int $firstLine, public int $lineCount, public string $subtotal)
    {
    }

    /** The position of its last line. */
    public function lastLine(): int
    {
        return $this->firstLine + $this->lineCount - 1;
    }
}
