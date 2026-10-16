<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Chain;

use App\Module\Invoices\Domain\ValueObject\ChainSeries;

/** A place where a series' chain does not hold, and why. */
final readonly class ChainBreak
{
    /** What the document says no longer gives the hash it was sealed with. */
    public const string HASH = 'hash';
    /** It does not name the hash of the document before it. */
    public const string LINK = 'link';
    /** Its place is not the one after the document before it: one is missing, or was put in. */
    public const string POSITION = 'position';
    /** The chain does not end where it was last recorded to end: its last documents were taken away. */
    public const string HEAD = 'head';
    /** An issued document never sealed. */
    public const string UNSEALED = 'unsealed';

    public function __construct(
        public ChainSeries $series,
        public ?string $number,
        public ?int $position,
        public string $reason,
    ) {
    }
}
