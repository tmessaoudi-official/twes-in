<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/** A document the calculator refuses to total, with the reason, and the line it concerns when it is one line's. */
class InvalidDocument extends \InvalidArgumentException
{
    /** @param int|null $lineIndex the index of the line refused, null when the document is refused as a whole */
    public function __construct(string $message, public readonly ?int $lineIndex = null)
    {
        parent::__construct($message);
    }

    /** Where a document's own refusal points: `lines[1].quantity` for a line's, given the field a line is entered by, `lines` otherwise. */
    public function field(string $lineField): string
    {
        return null === $this->lineIndex ? 'lines' : \sprintf('lines[%d].%s', $this->lineIndex, $lineField);
    }
}
