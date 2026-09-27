<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Domain;

/** The languages a legal page is written in; a page missing in one is read in French, then in English. */
enum LegalLanguage: string
{
    case Fr = 'fr';
    case En = 'en';
    case Ar = 'ar';

    /** @return list<self> the languages a page missing in this one is read in instead, in order */
    public function fallbacks(): array
    {
        return array_values(array_filter([self::Fr, self::En], fn (self $other) => $other !== $this));
    }
}
