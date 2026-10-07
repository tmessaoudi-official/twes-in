<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Invoices\Infrastructure\Pdf\TranslatorMentionWording;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** The mentions' wording as the API ships it, `translations/fiscal.<language>.yaml`, read the way the kernel reads it. */
final class ShippedMentionWording
{
    public static function wording(): TranslatorMentionWording
    {
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['fr', 'en'] as $language) {
            $translator->addResource('yaml', __DIR__.'/../../translations/fiscal.'.$language.'.yaml', $language, 'fiscal');
        }

        return new TranslatorMentionWording($translator);
    }
}
