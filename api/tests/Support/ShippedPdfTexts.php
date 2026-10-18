<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** The printed documents' texts as the API ships them, `translations/pdf.<language>.yaml`, with the kernel's fallback. */
final class ShippedPdfTexts
{
    public static function translator(): Translator
    {
        $languages = ShippedLanguages::catalogue();
        $translator = new Translator($languages->default());
        $translator->setFallbackLocales([$languages->default()]);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach ($languages->documents() as $language) {
            $translator->addResource('yaml', __DIR__.'/../../translations/pdf.'.$language.'.yaml', $language, 'pdf');
        }

        return $translator;
    }
}
