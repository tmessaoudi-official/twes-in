<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

use App\Tenancy\Domain\Model\Company;

/**
 * The words a screen shows, for the API to write them where a person reads them outside a screen (a report file's
 * headings): the release's screen file, with the installation's wording and then the company's laid over it, as the
 * screen itself shows them.
 */
interface ScreenWords
{
    /**
     * @param string $domain `screen`, or `screen:<part>` for a page's own texts
     *
     * @return \Closure(string, array<string, int>=): ?string a text by its dotted key, filled in as the screen fills it;
     *                                                        null when no file holds it
     */
    public function of(?Company $company, string $language, string $domain): \Closure;
}
