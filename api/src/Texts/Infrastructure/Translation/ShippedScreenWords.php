<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\Translation;

use App\Tenancy\Domain\Model\Company;
use App\Texts\Application\ReadTextOverrides;
use App\Texts\Application\ScreenMessage;
use App\Texts\Application\ScreenWords;
use App\Texts\Application\ShippedTexts;

/** The screen's words as the screen shows them: the shipped file, the installation's wording, then the company's. */
final readonly class ShippedScreenWords implements ScreenWords
{
    public function __construct(private ShippedTexts $shipped, private ReadTextOverrides $overrides)
    {
    }

    public function of(?Company $company, string $language, string $domain): \Closure
    {
        $texts = [...$this->shipped->texts($language, $domain), ...$this->overrides->screens($company, $language)[$domain] ?? []];

        return static fn (string $key, array $params = []): ?string => isset($texts[$key]) ? ScreenMessage::say($texts[$key], $language, $params) : null;
    }
}
