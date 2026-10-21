<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/** One of the answers a switch offers where it is a choice rather than a tick: « Juste en dessous ». */
final readonly class ImportChoice
{
    /**
     * @param string $key   what the request names it by, stable across languages
     * @param string $label a key of the screen's catalogue
     */
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
