<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Twig;

use App\Shared\Application\Languages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `text_direction(tag)`: `rtl` or `ltr`, the way a mail or a printed document's language reads, from the catalogue, so a
 * template turns over for Arabic without naming a language.
 */
final class LanguageExtension extends AbstractExtension
{
    public function __construct(private readonly Languages $languages)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('text_direction', $this->languages->direction(...))];
    }
}
