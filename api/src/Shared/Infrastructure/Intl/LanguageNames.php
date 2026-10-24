<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Intl;

use App\Shared\Application\Languages;
use App\Shared\Domain\Service\TypedText;
use Symfony\Component\Intl\Languages as IntlLanguages;

/**
 * A language as a person writes it in a file: its tag, or its name in itself or in any language the screens speak,
 * as ICU names it, whatever the case and accents. Only the languages documents print in are read; anything else is
 * not one of them, for the caller to refuse.
 */
final readonly class LanguageNames
{
    public function __construct(private Languages $languages)
    {
    }

    public function documentLanguageOf(string $written): ?string
    {
        $wanted = TypedText::folded($written);
        if ('' === $wanted) {
            return null;
        }
        foreach ($this->languages->documents() as $tag) {
            $names = [$tag, IntlLanguages::getName($tag, $tag)];
            foreach ($this->languages->interface() as $screen) {
                $names[] = IntlLanguages::getName($tag, $screen);
            }
            foreach ($names as $name) {
                if (TypedText::folded($name) === $wanted) {
                    return $tag;
                }
            }
        }

        return null;
    }
}
