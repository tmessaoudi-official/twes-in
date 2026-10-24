<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

use App\Shared\Application\Languages;
use App\Tenancy\Domain\Model\Company;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\Repository\TextOverrideRepository;
use App\Texts\Domain\ValueObject\TextSyntax;

/**
 * The screens' texts worded otherwise, as a browser lays them over the files it loads: the installation's, then the
 * company's own over them. Only the screens' files: the API's own texts are the API's to write, and a printed label
 * (a payment instruction) may hold a bank account no one without a session reads.
 */
final readonly class ReadTextOverrides
{
    public function __construct(private TextOverrideRepository $overrides, private Languages $languages)
    {
    }

    public function speaks(string $language): bool
    {
        return \in_array($language, $this->languages->interface(), true);
    }

    /**
     * @return array<string, array<string, string>> by file (`screen`, `screen:<part>`), each text by its dotted key
     */
    public function screens(?Company $company, string $language): array
    {
        $texts = [];
        $levels = null === $company ? [null] : [null, $company];
        foreach ($levels as $level) {
            foreach ($this->overrides->of($level, $language) as $override) {
                self::lay($texts, $override);
            }
        }
        ksort($texts);
        foreach ($texts as &$file) {
            ksort($file);
        }

        return $texts;
    }

    /** @param array<string, array<string, string>> $texts */
    private static function lay(array &$texts, TextOverride $override): void
    {
        $address = $override->address();
        if (TextSyntax::Screen === $address->syntax()) {
            $texts[$address->domain][$address->key] = $override->getValue();
        }
    }
}
