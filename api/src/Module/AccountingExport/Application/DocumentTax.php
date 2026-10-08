<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

/**
 * A tax a document carries, as its figures add it up: the tax component's code, its rate and base (none for a fixed
 * charge), and its amount; all decimal strings, signed as the document counts in the period.
 */
final readonly class DocumentTax
{
    public function __construct(
        public string $code,
        public ?string $rate,
        public ?string $base,
        public string $amount,
    ) {
    }
}
