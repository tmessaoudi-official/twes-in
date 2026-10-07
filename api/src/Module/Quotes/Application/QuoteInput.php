<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Module\Quotes\Domain\QuoteHeader;
use Symfony\Component\Uid\Uuid;

/** A quote as it is written: its customer and establishment by id (none: the company's default), its header and its lines. */
final readonly class QuoteInput
{
    /** @param list<QuoteLineInput> $lines */
    public function __construct(
        public Uuid $customerId,
        public ?Uuid $establishmentId,
        public QuoteHeader $header,
        public array $lines,
    ) {
    }
}
