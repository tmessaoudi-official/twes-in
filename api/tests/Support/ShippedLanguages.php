<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Languages;
use App\Shared\Infrastructure\Intl\CatalogueLanguages;

/** The language catalogue the application really ships, for tests that should not invent a language. */
final class ShippedLanguages
{
    public static function catalogue(): Languages
    {
        return new CatalogueLanguages(\dirname(__DIR__, 2).'/config/languages.json');
    }
}
