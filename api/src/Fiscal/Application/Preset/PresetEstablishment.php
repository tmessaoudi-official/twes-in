<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/** How a country codes an establishment: the part of a registration number that tells a company's places apart. */
final readonly class PresetEstablishment
{
    /**
     * @param string $defaultCode the code a company's first establishment starts with, until the company corrects it
     * @param string $codePattern a regular expression the whole code matches, without delimiters
     */
    public function __construct(public string $defaultCode, public string $codePattern)
    {
    }
}
